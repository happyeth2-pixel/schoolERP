<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * dompdf ships no font with Ethiopic (Ge'ez) glyphs at all -- Amharic and Tigrinya report cards
 * and receipts rendered as literal "???" until this was found and fixed (confirmed by actually
 * generating a PDF with real Amharic/Tigrinya text and looking at it; see LOCALIZATION.md).
 * Registers Noto Sans Ethiopic (committed at resources/fonts/, Google's free/open Ethiopic
 * typeface) with dompdf so `font-family: 'Noto Sans Ethiopic', 'DejaVu Sans', sans-serif` in a
 * PDF view actually has Ethiopic glyphs to fall through to.
 *
 * Idempotent -- safe to run on every deploy (dompdf's own registerFont() no-ops if the exact same
 * source file is already registered). Run once after `php artisan migrate` in the same startup
 * chain that already runs storage:link, since font_cache lives under storage/ and does not
 * survive a fresh container the way the committed source files under resources/fonts/ do.
 */
class InstallPdfFonts extends Command
{
    protected $signature = 'pdf:install-fonts';

    protected $description = 'Register the Ethiopic (Ge\'ez) font PDFs need for Amharic/Tigrinya with dompdf.';

    public function handle(): int
    {
        File::ensureDirectoryExists(config('dompdf.options.font_dir', storage_path('fonts')));

        $metrics = app(\Dompdf\Dompdf::class)->getFontMetrics();

        foreach ([
            ['weight' => 'normal', 'file' => 'NotoSansEthiopic-Regular.ttf'],
            ['weight' => 'bold', 'file' => 'NotoSansEthiopic-Bold.ttf'],
        ] as $font) {
            $path = resource_path("fonts/{$font['file']}");
            if (! file_exists($path)) {
                $this->error("Missing font file: {$path}");

                return self::FAILURE;
            }

            $ok = $metrics->registerFont(
                ['family' => 'Noto Sans Ethiopic', 'style' => 'normal', 'weight' => $font['weight']],
                $path
            );

            if (! $ok) {
                $this->error("Failed to register {$font['file']} with dompdf.");

                return self::FAILURE;
            }

            $this->info("Registered Noto Sans Ethiopic ({$font['weight']}).");
        }

        return self::SUCCESS;
    }
}
