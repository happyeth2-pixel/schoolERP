<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Best-effort ClamAV integration -- off by default, and silently a no-op if it isn't actually configured,
 * exactly like every other "optional, never blocks the real action if unconfigured" integration in this
 * app (AccountingPostings' GL posting, the Render-only scheduler jobs). This is defense-in-depth ON TOP OF
 * SafeUpload's existing content-sniffing/image re-encoding, not a replacement for it: re-encoding an image
 * already strips anything hidden beyond its literal pixels, which a signature scanner can't do; ClamAV
 * instead catches known-malware signatures a structural check can't reason about -- most usefully inside a
 * PDF or a GIF, neither of which this app re-encodes.
 *
 * Requires `security.clamav_enabled` (env CLAMAV_ENABLED) plus a real `clamdscan`/`clamscan` binary on the
 * server (`security.clamav_binary`) -- neither is set up anywhere in this deployment yet. Enabling this is
 * a deliberate ops decision (installing a clamav-daemon package on the server), not something this class
 * alone can provide; see config/security.php.
 */
class VirusScan
{
    /** A plain-language reason to refuse the file, or null if it's clean, unscannable, or scanning is off. */
    public static function problem(UploadedFile $file): ?string
    {
        if (! config('security.clamav_enabled')) {
            return null;
        }

        $binary = config('security.clamav_binary');
        $path = $file->getRealPath();

        if (! $binary || ! $path || ! is_readable($path)) {
            report(new RuntimeException('CLAMAV_ENABLED is true but nothing was scannable -- the upload proceeded unscanned.'));

            return null;
        }

        $output = [];
        $exitCode = null;
        @exec(escapeshellarg($binary).' --no-summary '.escapeshellarg($path).' 2>&1', $output, $exitCode);
        $text = implode(' ', $output);

        // clamdscan/clamscan: 0 = clean, 1 = a match was found, 2 = an error (binary missing, daemon down,
        // a permissions problem). Exit code 1 alone isn't trusted -- a shell that fails to invoke a
        // missing binary at all can also report exit code 1, platform-dependently -- so the file is only
        // ever refused when the exit code AND ClamAV's own documented "<name> FOUND" output both agree.
        // Anything else fails OPEN, the same "never block the real action on an infrastructure problem"
        // choice this app makes everywhere.
        if ($exitCode === 1 && str_contains($text, 'FOUND')) {
            report(new RuntimeException('Upload refused by virus scan: '.$text));

            return 'This file was flagged by a virus scan and cannot be accepted.';
        }

        if ($exitCode !== 0) {
            report(new RuntimeException('Virus scan did not complete cleanly (exit '.$exitCode.'); the upload proceeded unscanned. Output: '.$text));
        }

        return null;
    }
}
