<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Branch;
use App\Models\Complaint;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A parent must reach ONLY their own children's data. Every test here plays
 * Parent A and tries Parent B's IDs directly in the URL (and a few staff-only
 * URLs), then checks the request was refused, nothing changed, and nothing
 * about the other child leaked into the response.
 */
class ParentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private Branch $branch;
    private User $parentA;
    private User $parentB;
    private Student $childA;
    private Student $childB;
    private Invoice $invoiceA;
    private Invoice $invoiceB;
    private Payment $paymentA;   // verified, submitted by A, with proof + receipt
    private Payment $paymentB;   // verified, submitted by B, with proof + receipt
    private Complaint $complaintB;
    private string $notificationB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->school = School::create(['name' => 'Test School', 'code' => 'TST']);
        $this->branch = Branch::create(['school_id' => $this->school->id, 'name' => 'Main', 'code' => 'MAIN', 'is_main' => true]);
        $year = AcademicYear::create(['school_id' => $this->school->id, 'name' => '2026', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true]);
        $grade = Grade::create(['school_id' => $this->school->id, 'name' => 'Grade 1', 'sort_order' => 1]);
        $section = Section::create(['branch_id' => $this->branch->id, 'grade_id' => $grade->id, 'academic_year_id' => $year->id, 'name' => 'A']);

        $this->parentA = $this->makeUser('Parent A', 'parent', $this->branch->id);
        $this->parentB = $this->makeUser('Parent B', 'parent', $this->branch->id);

        $this->childA = $this->makeChild('Almaz', 'Tesfaye', $section, $this->parentA);
        $this->childB = $this->makeChild('Bekele', 'Girma', $section, $this->parentB);

        [$this->invoiceA, $this->paymentA] = $this->makeBilling($this->childA, $this->parentA, 'INV-A', 'RCT-A');
        [$this->invoiceB, $this->paymentB] = $this->makeBilling($this->childB, $this->parentB, 'INV-B', 'RCT-B');

        $this->complaintB = Complaint::create([
            'school_id' => $this->school->id, 'branch_id' => $this->branch->id, 'submitted_by' => $this->parentB->id,
            'student_id' => $this->childB->id, 'subject' => 'Private B complaint', 'body' => 'Secret body of B',
        ]);

        $this->notificationB = (string) Str::uuid();
        $this->parentB->notifications()->create([
            'id' => $this->notificationB, 'type' => 'test', 'data' => ['title' => 'B only', 'body' => 'B only'],
        ]);
    }

    // ---------------------------------------------------------------- reads

    public function test_parent_cannot_read_another_childs_records_by_changing_the_id(): void
    {
        $this->actingAs($this->parentA);

        foreach ([
            "/invoices/{$this->invoiceB->id}",
            "/students/{$this->childB->id}/report-card",
            "/students/{$this->childB->id}/report-card.pdf",
            "/students/{$this->childB->id}/id-card",
            "/payments/{$this->paymentB->id}/proof",
            "/payments/{$this->paymentB->id}/receipt",
            "/complaints/{$this->complaintB->id}",
            "/students/{$this->childB->id}/edit",
        ] as $url) {
            $this->assertDeniedAndNothingLeaks($this->get($url), $url);
        }
    }

    public function test_an_unlinked_id_looks_exactly_like_an_id_that_does_not_exist(): void
    {
        $this->actingAs($this->parentA);

        foreach ([
            ['/invoices/%d', $this->invoiceB->id],
            ['/students/%d/report-card', $this->childB->id],
            ['/students/%d/id-card', $this->childB->id],
            ['/payments/%d/proof', $this->paymentB->id],
            ['/payments/%d/receipt', $this->paymentB->id],
            ['/complaints/%d', $this->complaintB->id],
        ] as [$pattern, $realId]) {
            $this->assertSame(
                $this->get(sprintf($pattern, 999999))->getStatusCode(),
                $this->get(sprintf($pattern, $realId))->getStatusCode(),
                "Parent could tell a real-but-not-theirs ID from a missing one at {$pattern}",
            );
        }
    }

    public function test_parent_can_read_their_own_childs_records(): void
    {
        $this->actingAs($this->parentA);

        foreach ([
            "/invoices/{$this->invoiceA->id}",
            "/students/{$this->childA->id}/report-card",
            "/students/{$this->childA->id}/report-card.pdf",
            "/students/{$this->childA->id}/id-card",
            "/payments/{$this->paymentA->id}/proof",
            "/payments/{$this->paymentA->id}/receipt",
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    // --------------------------------------------------------------- writes

    public function test_parent_cannot_pay_against_another_childs_invoice(): void
    {
        $this->actingAs($this->parentA);
        $before = Payment::count();

        $response = $this->post("/invoices/{$this->invoiceB->id}/payments", [
            'amount' => 10, 'method' => 'cash', 'proof' => UploadedFile::fake()->image('p.png'),
        ]);

        $this->assertDeniedAndNothingLeaks($response, 'POST payment on B invoice');
        $this->assertSame($before, Payment::count(), 'a payment row was created against another child\'s invoice');
    }

    public function test_parent_cannot_raise_a_complaint_about_another_child(): void
    {
        $this->actingAs($this->parentA);
        $before = Complaint::count();

        $response = $this->post('/complaints', ['student_id' => $this->childB->id, 'subject' => 'x', 'body' => 'y']);

        $this->assertContains($response->getStatusCode(), [403, 404]);
        $this->assertSame($before, Complaint::count());
    }

    public function test_parent_cannot_use_any_staff_action_even_on_their_own_child(): void
    {
        $this->actingAs($this->parentA);
        $before = Payment::where('status', 'pending')->count();

        foreach ([
            ['patch', "/invoices/{$this->invoiceA->id}/discount", ['discount_amount' => 1]],
            ['post', "/invoices/{$this->invoiceA->id}/toggle-waive", []],
            ['post', "/payments/{$this->paymentA->id}/verify", []],
            ['post', "/payments/{$this->paymentA->id}/reject", ['rejection_reason' => 'x']],
            ['patch', "/students/{$this->childA->id}", ['first_name' => 'Hacked', 'father_name' => 'X', 'gender' => 'male', 'status' => 'active']],
            ['post', "/students/{$this->childA->id}/withdraw", ['effective_date' => '2026-09-10', 'reason' => 'nope']],
            ['post', "/students/{$this->childA->id}/transfer", ['to_section_id' => 1]],
            ['post', "/students/{$this->childA->id}/guardians", ['guardian_mode' => 'new', 'guardian_phone' => '0911998877', 'guardian_name' => 'X', 'guardian_relationship' => 'father']],
            ['post', "/complaints/{$this->complaintB->id}/status", ['status' => 'resolved']],
            ['get', '/parents/search?email=x', []],
            ['get', '/students', []],
            ['get', '/invoices', []],
            ['get', '/payments/pending', []],
        ] as [$method, $url, $data]) {
            $response = $this->{$method}($url, $data);
            $this->assertContains($response->getStatusCode(), [403, 404], "{$method} {$url} was not refused");
        }

        $this->assertSame('Almaz', $this->childA->fresh()->first_name);
        $this->assertSame('active', $this->childA->fresh()->status);
        $this->assertSame($before, Payment::where('status', 'pending')->count());
        $this->assertSame('open', $this->complaintB->fresh()->status);
    }

    public function test_parent_cannot_mark_another_users_notification_as_read(): void
    {
        $this->actingAs($this->parentA);

        $this->post("/notifications/{$this->notificationB}/read")->assertNotFound();

        $this->assertNull($this->parentB->notifications()->first()->read_at);
    }

    // ------------------------------------------------- the link is the rule

    public function test_access_follows_the_guardian_link_and_nothing_else(): void
    {
        $second = $this->makeUser('Second guardian', 'parent', $this->branch->id);
        $url = "/invoices/{$this->invoiceA->id}";

        $this->actingAs($second)->get($url)->assertNotFound();

        $this->childA->guardians()->attach($second->id, ['relationship' => 'mother']);
        $this->actingAs($second)->get($url)->assertOk();
        $this->actingAs($second)->get("/students/{$this->childA->id}/report-card")->assertOk();
        $this->actingAs($second)->get("/payments/{$this->paymentA->id}/receipt")->assertOk();
        // ...but the proof image belongs to whoever submitted it.
        $this->actingAs($second)->get("/payments/{$this->paymentA->id}/proof")->assertNotFound();

        $this->childA->guardians()->detach($second->id);
        $this->actingAs($second)->get($url)->assertNotFound();

        $this->childA->guardians()->detach($this->parentA->id);
        $this->actingAs($this->parentA)->get($url)->assertNotFound();
        $this->actingAs($this->parentA)->get("/payments/{$this->paymentA->id}/proof")->assertNotFound();
    }

    public function test_student_and_other_branch_staff_get_no_access_either(): void
    {
        $student = $this->makeUser('A Student', 'student', $this->branch->id);
        // there is no student portal: a student account is signed out and sent to the login page, reaching nothing
        $this->actingAs($student)->get("/students/{$this->childA->id}/report-card")->assertRedirect('/login');
        $this->actingAs($student)->get("/invoices/{$this->invoiceA->id}")->assertRedirect('/login');

        $otherBranch = Branch::create(['school_id' => $this->school->id, 'name' => 'Other', 'code' => 'OTH']);
        $accountant = $this->makeUser('Other Accountant', 'accountant', $otherBranch->id);
        $this->actingAs($accountant)->get("/invoices/{$this->invoiceA->id}")->assertForbidden();
        $this->actingAs($accountant)->get("/payments/{$this->paymentA->id}/proof")->assertForbidden();

        $sameBranch = $this->makeUser('Home Accountant', 'accountant', $this->branch->id);
        $this->actingAs($sameBranch)->get("/invoices/{$this->invoiceA->id}")->assertOk();
    }

    // -------------------------------------------------------------- helpers

    private function assertDeniedAndNothingLeaks($response, string $what): void
    {
        $this->assertContains($response->getStatusCode(), [403, 404], "{$what} was not refused (got {$response->getStatusCode()})");

        $body = (string) $response->getContent();
        foreach (['Bekele', 'Girma', 'Secret body of B', 'Private B complaint', 'INV-B', 'RCT-B'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, "{$what} leaked '{$secret}'");
        }
    }

    private function makeUser(string $name, string $role, ?int $branchId): User
    {
        $user = User::create([
            'school_id' => $this->school->id, 'branch_id' => $branchId, 'name' => $name,
            'email' => Str::slug($name).'@test.test', 'password' => 'password', 'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->assignTier($role);

        return $user;
    }

    private function makeChild(string $first, string $father, Section $section, User $guardian): Student
    {
        $student = Student::create([
            'school_id' => $this->school->id, 'branch_id' => $this->branch->id,
            'student_id_number' => 'STU-'.Str::upper(Str::random(6)), 'first_name' => $first, 'father_name' => $father,
            'gender' => 'male', 'enrollment_date' => '2026-09-01', 'status' => 'active', 'id_card_token' => Str::random(40),
        ]);
        Enrollment::create(['student_id' => $student->id, 'section_id' => $section->id, 'enrolled_on' => '2026-09-01', 'status' => 'active']);
        $student->guardians()->attach($guardian->id, ['relationship' => 'father']);

        return $student;
    }

    /** @return array{0: Invoice, 1: Payment} */
    private function makeBilling(Student $student, User $parent, string $invoiceNumber, string $receipt): array
    {
        $invoice = Invoice::create([
            'student_id' => $student->id, 'academic_year_id' => AcademicYear::first()->id,
            'frequency' => 'annual', 'period_start' => '2026-09-01', 'invoice_number' => $invoiceNumber,
        ]);
        $invoice->items()->create(['description' => 'Tuition', 'quantity' => 1, 'unit_amount' => 1000, 'amount' => 1000]);

        $path = "payment-proofs/{$invoiceNumber}.png";
        Storage::disk('local')->put($path, 'fake-image');

        $payment = Payment::create([
            'invoice_id' => $invoice->id, 'amount' => 100, 'method' => 'cash', 'proof_path' => $path,
            'submitted_by' => $parent->id, 'status' => 'verified', 'verified_by' => $parent->id,
            'verified_at' => now(), 'receipt_number' => $receipt,
        ]);

        return [$invoice, $payment];
    }
}
