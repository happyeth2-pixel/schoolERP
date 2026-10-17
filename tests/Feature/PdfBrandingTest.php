<?php

namespace Tests\Feature;

use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Production-readiness audit finding: the report-card and payment-receipt PDF templates never
 * showed the school's own name or logo -- every printed document looked identical regardless of
 * which school issued it. dompdf has remote image fetching disabled (config/dompdf.php), so the
 * logo must be embedded as a `data:` URI (`School::logoDataUri()`), never a plain storage URL.
 * Rendering the raw Blade view (not the compiled PDF binary) is the practical way to assert on
 * this template's actual HTML output.
 */
class PdfBrandingTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        Storage::fake('public');
    }

    public function test_the_receipt_shows_the_schools_name(): void
    {
        $html = view('receipt.pdf', [
            'payment' => $this->makePayment(),
            'student' => $this->studentA,
        ])->render();

        $this->assertStringContainsString($this->school->name, $html);
    }

    public function test_the_report_card_shows_the_schools_name_when_a_school_is_given(): void
    {
        $html = view('reportcard.pdf', $this->reportCardData() + ['school' => $this->school])->render();

        $this->assertStringContainsString($this->school->name, $html);
    }

    public function test_the_report_card_still_renders_fine_with_no_school_given(): void
    {
        // computeReportCard()'s own array never includes 'school' -- the template must not break
        // for any caller that doesn't pass one.
        $html = view('reportcard.pdf', $this->reportCardData())->render();

        $this->assertStringContainsString('Student Report Card', $html);
    }

    public function test_a_school_with_no_logo_shows_the_name_only_with_no_broken_image(): void
    {
        $this->assertNull($this->school->logoDataUri());

        $html = view('receipt.pdf', [
            'payment' => $this->makePayment(),
            'student' => $this->studentA,
        ])->render();

        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_a_school_with_a_logo_embeds_it_as_a_data_uri_never_a_plain_storage_url(): void
    {
        $this->school->update(['logo_path' => \App\Support\SafeUpload::storePublicImage(
            UploadedFile::fake()->image('logo.png', 100, 100),
            'school-logos'
        )]);

        $uri = $this->school->fresh()->logoDataUri();

        $this->assertNotNull($uri);
        $this->assertStringStartsWith('data:image/png;base64,', $uri);

        $html = view('receipt.pdf', [
            'payment' => $this->makePayment(),
            'student' => $this->studentA,
        ])->render();

        $this->assertStringContainsString($uri, $html);
        $this->assertStringNotContainsString('/storage/school-logos', $html, 'dompdf cannot fetch remote/storage URLs -- the logo must be embedded, not linked');
    }

    private function makePayment(): Payment
    {
        $invoice = \App\Models\Invoice::create([
            'student_id' => $this->studentA->id,
            'academic_year_id' => $this->year->id,
            'invoice_number' => 'INV-PDF-1',
            'total_amount' => 1000,
            'status' => 'pending',
            'frequency' => 'annual',
            'period_start' => now()->toDateString(),
        ]);
        $invoice->items()->create(['description' => 'Tuition', 'amount' => 1000, 'item_type' => 'fee']);

        return Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 1000,
            'method' => 'cash',
            'status' => 'verified',
            'submitted_by' => $this->owner->id,
            'verified_by' => $this->owner->id,
            'verified_at' => now(),
            'receipt_number' => 'RCT-PDF-1',
        ]);
    }

    private function reportCardData(): array
    {
        return [
            'student' => ['name' => $this->studentA->fullName(), 'student_id_number' => $this->studentA->student_id_number],
            'section' => null,
            'academic_year' => $this->year->name,
            'subjects' => [],
            'overall_average' => null,
            'overall_letter' => null,
            'rank' => null,
            'class_size' => 0,
            'rank_basis' => [],
            'rank_reasons' => [],
            'missing_mark_policy' => 'exclude',
            'policy_note' => null,
            'weight_warnings' => [],
        ];
    }
}
