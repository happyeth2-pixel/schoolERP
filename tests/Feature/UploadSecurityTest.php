<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Support\SafeUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/** Uploads are judged by their content, stored under random names, and private files are only served to authorized people. */
class UploadSecurityTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->buildSchool();
        $this->invoice = Invoice::create(['student_id' => $this->studentA->id, 'academic_year_id' => $this->year->id, 'invoice_number' => 'INV-1', 'total_amount' => 100, 'status' => 'pending', 'frequency' => 'annual', 'period_start' => '2026-09-01']);
        $this->invoice->items()->create(['description' => 'Tuition', 'amount' => 100, 'item_type' => 'fee']);
    }

    private function png(int $w = 40, int $h = 30): string
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($im);

        return ob_get_clean();
    }

    private function jpg(): string
    {
        $im = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($im);

        return ob_get_clean();
    }

    private function pay(string $name, string $content)
    {
        $parent = $this->studentA->guardians()->first() ?? $this->makeParent($this->studentA);

        return $this->actingAs($parent)->post("/invoices/{$this->invoice->id}/payments", [
            'amount' => 10, 'method' => 'bank_transfer',
            'proof' => UploadedFile::fake()->createWithContent($name, $content),
        ]);
    }

    public function test_a_script_renamed_to_look_like_an_image_or_pdf_is_rejected(): void
    {
        foreach (['receipt.png' => '<?php system($_GET["c"]); ?>', 'receipt.pdf' => '<html><script>alert(1)</script></html>', 'receipt.jpg' => "GIF89a<?php echo 1; ?>"] as $name => $content) {
            $this->pay($name, $content)->assertSessionHasErrors('proof');
        }
        $this->assertSame(0, Payment::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_real_image_with_the_wrong_extension_is_judged_by_content_and_a_fake_extension_gets_no_free_pass(): void
    {
        // GIF is not an allowed type even if named .png
        $this->pay('photo.png', "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;")->assertSessionHasErrors('proof');

        // a genuine PNG named .txt is accepted (content is what counts) and stored as .png
        $this->pay('notes.txt', $this->png())->assertSessionHasNoErrors();
        $this->assertStringEndsWith('.png', Payment::first()->proof_path);
    }

    public function test_the_stored_name_is_random_private_and_never_the_original_filename(): void
    {
        $this->pay('../../evil.php.png', $this->png())->assertSessionHasNoErrors();

        $path = Payment::firstOrFail()->proof_path;
        $this->assertStringStartsWith('payment-proofs/', $path);
        $this->assertStringNotContainsString('evil', $path);
        $this->assertStringNotContainsString('..', $path);
        $this->assertMatchesRegularExpression('#^payment-proofs/\d{4}/\d{2}/[A-Za-z0-9]{40}\.png$#', $path);
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_pdfs_with_scripts_or_attachments_are_refused_but_a_plain_pdf_is_accepted(): void
    {
        $plain = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
        $evil = "%PDF-1.4\n1 0 obj<</Type/Catalog/OpenAction<</S/JavaScript/JS(app.alert(1))>>>>endobj\n%%EOF";

        $this->pay('a.pdf', $evil)->assertSessionHasErrors('proof');
        $this->pay('a.pdf', $plain)->assertSessionHasNoErrors();
        $this->assertStringEndsWith('.pdf', Payment::firstOrFail()->proof_path);
    }

    public function test_image_uploads_are_reencoded_so_hidden_payloads_do_not_survive(): void
    {
        $payload = '<?php system("id"); ?>';
        $this->pay('receipt.jpg', $this->jpg().$payload)->assertSessionHasNoErrors();

        $stored = Storage::disk('local')->get(Payment::firstOrFail()->proof_path);
        $this->assertStringNotContainsString('<?php', $stored);
        $this->assertSame('jpg', SafeUpload::detect(UploadedFile::fake()->createWithContent('x', $stored)));
    }

    public function test_oversized_images_are_refused(): void
    {
        $this->assertNotNull(SafeUpload::problem(UploadedFile::fake()->createWithContent('a.png', $this->png(7000, 6000)), ['png']));
    }

    public function test_every_download_is_authorized_and_served_safely(): void
    {
        $parent = $this->makeParent($this->studentA);
        $this->pay('r.png', $this->png());
        $payment = Payment::firstOrFail();

        $this->actingAs($this->makeParent($this->studentB))->get("/payments/{$payment->id}/proof")->assertNotFound();
        $this->post('/logout');
        auth()->logout();
        $this->get("/payments/{$payment->id}/proof")->assertRedirect('/login');

        $ok = $this->actingAs($parent)->get("/payments/{$payment->id}/proof");
        $ok->assertOk();
        $this->assertSame('image/png', $ok->headers->get('Content-Type'));
        $this->assertSame('nosniff', $ok->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString("payment-{$payment->id}-proof.png", $ok->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', $ok->headers->get('Content-Security-Policy'));

        $staffInOtherBranch = $this->makeUser('accountant', $this->branchB->id);
        $this->actingAs($staffInOtherBranch)->get("/payments/{$payment->id}/proof")->assertForbidden();
        $this->actingAs($this->makeUser('accountant', $this->branchA->id))->get("/payments/{$payment->id}/proof")->assertOk();
    }

    public function test_public_photos_are_validated_by_content_resized_and_renamed(): void
    {
        $staff = $this->makeUser('branch_admin', $this->branchA->id);
        $ok = fn (string $name, string $content) => $this->actingAs($staff)->post("/students/{$this->studentA->id}/photo", ['photo' => UploadedFile::fake()->createWithContent($name, $content)]);

        $ok('me.png', '<?php echo 1;')->assertSessionHasErrors('photo');
        $ok('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')->assertSessionHasErrors('photo');

        $ok('../../x.php.png', $this->png(2000, 1000))->assertSessionHasNoErrors();
        $path = $this->studentA->fresh()->photo_path;
        $this->assertMatchesRegularExpression('#^student-photos/[A-Za-z0-9]{40}\.png$#', $path);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame(800, $w, 'shrunk to the size limit');
        $this->assertSame(400, $h);
    }

    public function test_payment_account_logos_and_profile_photos_use_the_same_checks(): void
    {
        $this->actingAs($this->owner)->post('/settings/payment-accounts', ['display_name' => 'X Bank', 'account_number' => '1', 'logo' => UploadedFile::fake()->createWithContent('l.png', '<?php ?>')])->assertSessionHasErrors('logo');
        $this->actingAs($this->owner)->post('/settings/payment-accounts', ['display_name' => 'X Bank', 'account_number' => '1', 'logo' => UploadedFile::fake()->createWithContent('l.png', $this->png())])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->post('/profile/photo', ['photo' => UploadedFile::fake()->createWithContent('p.jpg', 'not an image')])->assertSessionHasErrors('photo');
        $this->actingAs($this->owner)->post('/profile/photo', ['photo' => UploadedFile::fake()->createWithContent('p.jpg', $this->jpg())])->assertSessionHasNoErrors();
        $this->assertMatchesRegularExpression('#^avatars/[A-Za-z0-9]{40}\.jpg$#', $this->owner->fresh()->profile_photo_path);
    }
}
