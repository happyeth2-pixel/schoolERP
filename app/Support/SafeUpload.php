<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Everything to do with accepting a file from a person.
 *
 *  - The file's TYPE is decided from its CONTENT (leading bytes AND the server's
 *    own MIME detection must agree), never from the name or the type the browser
 *    claims. A script renamed "receipt.png" is rejected.
 *  - The name it is stored under is random and its extension comes from the
 *    detected type. The original filename is never used for anything.
 *  - Images are decoded and re-encoded (which drops metadata and anything hidden
 *    inside the file); public images are also resized.
 *  - PDFs cannot be re-encoded, so they are scanned for active content
 *    (JavaScript, launch actions, embedded files) and refused if it is there.
 *  - Private files (payment proofs) go to the non-public disk and are only ever
 *    served through an authorized controller action.
 */
class SafeUpload
{
    public const TYPES = [
        'jpg' => ['mime' => 'image/jpeg', 'magic' => "\xFF\xD8\xFF"],
        'png' => ['mime' => 'image/png', 'magic' => "\x89PNG\r\n\x1a\n"],
        'gif' => ['mime' => 'image/gif', 'magic' => 'GIF8'],
        'pdf' => ['mime' => 'application/pdf', 'magic' => '%PDF-'],
    ];

    /** Largest decoded image we will process (guards against decompression bombs). */
    public const MAX_PIXELS = 36_000_000;

    /** A GIF is never re-encoded (that would collapse it to its first frame and kill the animation), so it
     * is never shrunk either -- this caps it directly instead. */
    public const MAX_GIF_BYTES = 5 * 1024 * 1024;

    /** What kind of file this really is (jpg, png, pdf), or null if it is none of those or the evidence disagrees. */
    public static function detect(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();
        if (! $path || ! is_readable($path)) {
            return null;
        }

        $head = (string) file_get_contents($path, false, null, 0, 16);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        foreach (self::TYPES as $ext => $t) {
            if (str_starts_with($head, $t['magic']) && $mime === $t['mime']) {
                return $ext;
            }
        }

        return null;
    }

    /** A plain-language reason the file must be refused, or null when it is acceptable. @param list<string> $allowed */
    public static function problem(UploadedFile $file, array $allowed): ?string
    {
        $type = self::detect($file);

        if ($type === null || ! in_array($type, $allowed, true)) {
            return 'This file is not a valid '.strtoupper(implode(', ', $allowed)).' file. Please upload the original file, not a renamed one.';
        }

        if ($type === 'pdf') {
            $content = (string) file_get_contents($file->getRealPath());

            if (! str_contains($content, '%%EOF')) {
                return 'This PDF looks damaged or incomplete.';
            }
            if (preg_match('#/(JavaScript|JS|Launch|EmbeddedFile|RichMedia|XFA)\b#', $content)) {
                return 'This PDF contains scripts or attachments and cannot be accepted. Please upload a plain PDF or a photo.';
            }

            return null;
        }

        $info = @getimagesize($file->getRealPath());
        if (! $info || $info[0] < 1 || $info[1] < 1) {
            return 'This image could not be read.';
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            return 'This image is too large in pixels. Please upload a smaller one.';
        }
        if ($type === 'gif' && $file->getSize() > self::MAX_GIF_BYTES) {
            return 'This GIF is too large (over 5 MB). Please upload a smaller one.';
        }

        return null;
    }

    /**
     * A payment proof: private disk, random name, images re-encoded.
     * Returns the stored path (relative to the private disk).
     */
    public static function storePrivate(UploadedFile $file, string $directory, array $allowed = ['jpg', 'png', 'pdf']): string
    {
        $type = self::checked($file, $allowed);
        $path = trim($directory, '/').'/'.date('Y/m').'/'.Str::random(40).'.'.$type;

        if ($type === 'pdf') {
            Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));
        } else {
            Storage::disk('local')->put($path, self::reencode($file, $type, null));
        }

        return $path;
    }

    /**
     * A logo or photo people will see: public disk, random name, decoded,
     * shrunk to at most $maxSide px and re-encoded (PNG keeps transparency).
     */
    public static function storePublicImage(UploadedFile $file, string $directory, int $maxSide = 1024): string
    {
        $type = self::checked($file, ['jpg', 'png']);
        $path = trim($directory, '/').'/'.Str::random(40).'.'.$type;

        Storage::disk('public')->put($path, self::reencode($file, $type, $maxSide));

        return $path;
    }

    /**
     * A chat/announcement/complaint attachment: public disk, random name. A jpg/png is decoded, shrunk to at
     * most $maxSide px and re-encoded exactly like storePublicImage(); a GIF is deliberately left untouched
     * (re-encoding would collapse it to a single frame and kill the animation) -- its magic bytes, declared
     * MIME, structure and size were already checked in problem()/checked() above, which is as far as a file
     * nobody will ever execute (only ever displayed as an <img>) needs to be checked.
     */
    public static function storePublicAttachment(UploadedFile $file, string $directory): string
    {
        $type = self::checked($file, ['jpg', 'png', 'gif']);
        $path = trim($directory, '/').'/'.Str::random(40).'.'.$type;

        $bytes = $type === 'gif' ? (string) file_get_contents($file->getRealPath()) : self::reencode($file, $type, 1280);
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    private static function checked(UploadedFile $file, array $allowed): string
    {
        if ($problem = self::problem($file, $allowed)) {
            throw new RuntimeException($problem);
        }

        // Off by default -- see VirusScan's own docblock. Defense-in-depth on top of the content-sniffing
        // and re-encoding above, not a replacement for it.
        if ($problem = VirusScan::problem($file)) {
            throw new RuntimeException($problem);
        }

        return self::detect($file);
    }

    private static function reencode(UploadedFile $file, string $type, ?int $maxSide): string
    {
        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $source) {
            throw new RuntimeException('This image could not be processed.');
        }

        $w = imagesx($source);
        $h = imagesy($source);
        $scale = $maxSide && max($w, $h) > $maxSide ? $maxSide / max($w, $h) : 1;
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $canvas = imagecreatetruecolor($nw, $nh);
        if ($type === 'png') {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        } else {
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        }
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        $type === 'png' ? imagepng($canvas, null, 6) : imagejpeg($canvas, null, 88);
        $bytes = (string) ob_get_clean();

        return $bytes;
    }

    /** Content type + safe extension to serve a stored private file with. */
    public static function servingType(string $storedPath): array
    {
        $ext = strtolower(pathinfo($storedPath, PATHINFO_EXTENSION));

        return [$ext, self::TYPES[$ext]['mime'] ?? 'application/octet-stream'];
    }
}
