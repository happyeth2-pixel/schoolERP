<?php

namespace Tests\Feature;

use App\Exceptions\DeletionBlockedException;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Enrollment;
use App\Models\FeeStructure;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Who did what, to which record, when, with before/after and the reason. */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private Branch $branch;
    private User $owner;
    private AcademicYear $year;
    private Grade $grade;
    private Section $section;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->school = School::create(['name' => 'S', 'code' => 'S'.Str::random(3)]);
        $this->branch = Branch::create(['school_id' => $this->school->id, 'name' => 'Main', 'code' => 'MAIN', 'is_main' => true]);
        $this->owner = $this->user('owner', 'Olga Owner');
        $this->year = AcademicYear::create(['school_id' => $this->school->id, 'name' => '2026', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true]);
        $this->grade = Grade::create(['school_id' => $this->school->id, 'name' => 'G1', 'sort_order' => 1]);
        $this->section = Section::create(['branch_id' => $this->branch->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'A']);
        $this->student = Student::create(['school_id' => $this->school->id, 'branch_id' => $this->branch->id, 'student_id_number' => 'STU-1', 'first_name' => 'Almaz', 'father_name' => 'Tesfaye', 'gender' => 'female', 'enrollment_date' => '2026-09-01', 'status' => 'active', 'id_card_token' => Student::newCardToken()]);
        Enrollment::create(['student_id' => $this->student->id, 'section_id' => $this->section->id, 'enrolled_on' => '2026-09-01', 'status' => 'active']);
        AuditLog::query()->delete();   // start each test from an empty trail (seeding above is not the action under test)
    }

    private function user(string $role, string $name): User
    {
        $u = User::create(['school_id' => $this->school->id, 'branch_id' => null, 'name' => $name, 'email' => Str::random(6).'@t.test', 'password' => 'x', 'status' => 'active', 'email_verified_at' => now()]);
        $u->assignTier($role);

        return $u;
    }

    private function last(string $action): AuditLog
    {
        return AuditLog::where('action', $action)->latest('id')->firstOrFail();
    }

    public function test_an_entry_records_actor_record_before_after_and_time(): void
    {
        $this->actingAs($this->owner)->patch("/students/{$this->student->id}", [
            'first_name' => 'Almaz', 'father_name' => 'Tesfaye', 'gender' => 'female', 'status' => 'active', 'address' => 'Bole',
        ]);

        $log = $this->last('student.updated');
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertSame(Student::class, $log->auditable_type);
        $this->assertSame($this->student->id, $log->auditable_id);
        $this->assertSame('STU-1 Almaz Tesfaye', $log->subject_label);
        $this->assertSame(['address' => null], $log->before);
        $this->assertSame(['address' => 'Bole'], $log->after);
        $this->assertNotNull($log->created_at);
    }

    public function test_student_status_changes_are_logged_with_their_reason(): void
    {
        $this->actingAs($this->owner)->post("/students/{$this->student->id}/withdraw", ['effective_date' => now()->toDateString(), 'reason' => 'Family moved'])->assertSessionHasNoErrors();

        $log = $this->last('student.withdrawn');
        $this->assertSame('Family moved', $log->note);
        $this->assertSame('active', $log->before['status']);
        $this->assertSame(1, AuditLog::where('auditable_type', Student::class)->count(), 'one entry, not one per touched row');
    }

    public function test_a_transfer_is_one_entry_naming_both_sections(): void
    {
        $b = Section::create(['branch_id' => $this->branch->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'B']);

        $this->actingAs($this->owner)->post("/students/{$this->student->id}/transfer", ['to_section_id' => $b->id])->assertSessionHasNoErrors();

        $log = $this->last('student.transferred');
        $this->assertSame(['section' => 'G1 A'], $log->before);
        $this->assertSame(['section' => 'G1 B'], $log->after);
        $this->assertSame(1, AuditLog::count());
    }

    public function test_a_promotion_logs_each_student_and_the_run(): void
    {
        $y2 = AcademicYear::create(['school_id' => $this->school->id, 'name' => '2027', 'start_date' => '2027-09-01', 'end_date' => '2028-06-30', 'is_current' => false]);
        $g2 = Grade::create(['school_id' => $this->school->id, 'name' => 'G2', 'sort_order' => 2]);
        $to = Section::create(['branch_id' => $this->branch->id, 'grade_id' => $g2->id, 'academic_year_id' => $y2->id, 'name' => 'A']);
        $enrollment = Enrollment::where('student_id', $this->student->id)->first();

        $this->actingAs($this->owner)->post('/promotions', ['from_section_id' => $this->section->id, 'to_section_id' => $to->id, 'promotions' => [['enrollment_id' => $enrollment->id, 'graduate' => false]]])->assertSessionHasNoErrors();

        $this->assertSame('G1 A 2026', $this->last('student.promoted')->before['section']);
        $this->assertSame('G2 A 2027', $this->last('student.promoted')->after['section']);
        $this->assertSame(1, $this->last('promotion.executed')->after['promoted']);
        $this->assertSame(2, AuditLog::count(), 'the student entry + the run summary');
    }

    public function test_discounts_and_waivers_are_logged_with_before_after_and_reason(): void
    {
        $invoice = Invoice::create(['student_id' => $this->student->id, 'academic_year_id' => $this->year->id, 'invoice_number' => 'INV-1', 'total_amount' => 100, 'status' => 'pending', 'frequency' => 'annual', 'period_start' => '2026-09-01']);
        $invoice->items()->create(['description' => 'Tuition', 'amount' => 100, 'item_type' => 'fee']);

        $this->actingAs($this->owner)->patch("/invoices/{$invoice->id}/discount", ['discount_amount' => 20, 'discount_reason' => 'Sibling discount'])->assertSessionHasNoErrors();
        $log = $this->last('invoice.discount_changed');
        $this->assertEquals(20, $log->after['discount_amount']);
        $this->assertSame('Sibling discount', $log->note);
        $this->assertSame('Invoice INV-1', $log->subject_label);

        $this->actingAs($this->owner)->post("/invoices/{$invoice->id}/toggle-waive", ['reason' => 'Hardship'])->assertSessionHasNoErrors();
        $this->assertSame(['is_waived' => true], collect($this->last('invoice.waived')->after)->only('is_waived')->all());
        $this->actingAs($this->owner)->post("/invoices/{$invoice->id}/toggle-waive");
        $this->assertNotNull($this->last('invoice.waiver_removed'));
    }

    public function test_invoice_generation_is_one_summary_entry_not_one_per_invoice(): void
    {
        FeeStructure::create(['branch_id' => $this->branch->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'Tuition', 'amount' => 100, 'frequency' => 'annual']);
        AuditLog::query()->delete();

        $this->actingAs($this->owner)->post('/invoices/generate', ['branch_id' => $this->branch->id, 'grade_id' => $this->grade->id])->assertSessionHasNoErrors();

        $this->assertSame(1, Invoice::count());
        $log = $this->last('invoices.generated');
        $this->assertSame(1, $log->after['invoices_created']);
        $this->assertSame('2026', $log->after['academic_year']);
        $this->assertSame(0, AuditLog::where('action', 'invoice.created')->count());
    }

    public function test_payment_verification_and_rejection_are_logged(): void
    {
        $accountant = $this->user('accountant', 'Ann Accountant');
        $invoice = Invoice::create(['student_id' => $this->student->id, 'academic_year_id' => $this->year->id, 'invoice_number' => 'INV-2', 'total_amount' => 100, 'status' => 'pending', 'frequency' => 'annual', 'period_start' => '2026-09-01']);
        $invoice->items()->create(['description' => 'Tuition', 'amount' => 100, 'item_type' => 'fee']);
        $p1 = Payment::create(['invoice_id' => $invoice->id, 'amount' => 40, 'method' => 'cash', 'status' => 'pending', 'submitted_by' => $this->owner->id]);
        $p2 = Payment::create(['invoice_id' => $invoice->id, 'amount' => 10, 'method' => 'cash', 'status' => 'pending', 'submitted_by' => $this->owner->id]);

        $this->actingAs($accountant)->post("/payments/{$p1->id}/verify")->assertSessionHasNoErrors();
        $verified = $this->last('payment.verified');
        $this->assertSame($accountant->id, $verified->user_id);
        $this->assertSame('pending', $verified->before['status']);
        $this->assertSame('verified', $verified->after['status']);
        $this->assertNotEmpty($verified->after['receipt_number']);

        $this->actingAs($accountant)->post("/payments/{$p2->id}/reject", ['rejection_reason' => 'Blurry proof'])->assertSessionHasNoErrors();
        $rejected = $this->last('payment.rejected');
        $this->assertSame('Blurry proof', $rejected->note);
    }

    public function test_staff_role_and_permission_changes_are_logged(): void
    {
        $this->actingAs($this->owner)->post('/staff', ['name' => 'Vera Vice', 'email' => 'vera@t.test', 'phone' => '0911000222', 'role' => 'branch_admin', 'branch_id' => $this->branch->id, 'permissions' => ['manage-students']])->assertSessionHasNoErrors();
        $vera = User::where('email', 'vera@t.test')->firstOrFail();

        $assigned = $this->last('staff.role_assigned');
        $this->assertSame('branch_admin', $assigned->after['role']);
        $this->assertContains('manage-students', $assigned->after['permissions']);

        $this->actingAs($this->owner)->patch("/staff/{$vera->id}", ['title' => 'Registrar', 'status' => 'active', 'permissions' => ['manage-students', 'manage-invoices']])->assertSessionHasNoErrors();
        $changed = $this->last('staff.permissions_changed');
        $this->assertSame(['manage-invoices'], $changed->after['added']);
        $this->assertSame([], $changed->after['removed']);

        $this->actingAs($this->owner)->patch("/staff/{$vera->id}", ['title' => 'Registrar', 'status' => 'inactive', 'permissions' => ['manage-students', 'manage-invoices']]);
        $status = $this->last('user.status_changed');
        $this->assertSame(['status' => 'active'], collect($status->before)->only('status')->all());
        $this->assertSame('inactive', $status->after['status']);
    }

    public function test_settings_changes_and_archiving_are_logged_and_secrets_never_are(): void
    {
        $this->actingAs($this->owner)->patch('/settings/school', ['name' => 'New Name', 'address' => 'X', 'phone' => '0911223344', 'calendar_preference' => 'both', 'missing_mark_policy' => 'zero', 'enabled_languages' => ['en'], 'discount_limit_percent' => 10, 'waiver_limit_amount' => 1000, 'payment_due_days' => 14, 'allow_overlapping_terms' => false])->assertSessionHasNoErrors();
        $settings = $this->last('school.settings_changed');
        $this->assertSame('New Name', $settings->after['name']);
        $this->assertSame('S', $settings->before['name']);

        $fee = FeeStructure::create(['branch_id' => $this->branch->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'Bus', 'amount' => 5, 'frequency' => 'monthly']);
        $this->actingAs($this->owner)->delete("/fee-structures/{$fee->id}");
        $archived = $this->last('fee_structure.archived');
        $this->assertSame('Bus', $archived->before['name']);

        $this->actingAs($this->owner)->post('/staff', ['name' => 'T', 'email' => 't@t.test', 'phone' => '0911000333', 'role' => 'teacher']);
        $all = json_encode(AuditLog::all()->map(fn ($l) => [$l->before, $l->after])->all());
        $this->assertStringNotContainsString('password', $all);
        $this->assertStringNotContainsString('id_card_token', $all);
        $this->assertStringNotContainsString('remember_token', $all);
    }

    public function test_the_trail_is_append_only(): void
    {
        $this->actingAs($this->owner)->patch("/students/{$this->student->id}", ['first_name' => 'Almaz', 'gender' => 'female', 'status' => 'active', 'address' => 'Bole']);
        $log = AuditLog::firstOrFail();

        $this->expectException(DeletionBlockedException::class);
        $log->update(['note' => 'tampered']);
    }

    public function test_the_trail_cannot_be_deleted(): void
    {
        $this->actingAs($this->owner)->patch("/students/{$this->student->id}", ['first_name' => 'Almaz', 'gender' => 'female', 'status' => 'active', 'address' => 'Bole']);

        $this->expectException(DeletionBlockedException::class);
        AuditLog::firstOrFail()->delete();
    }

    public function test_the_page_filters_by_type_action_person_text_and_day_and_stays_within_the_school(): void
    {
        $other = School::create(['name' => 'Other', 'code' => 'O'.Str::random(3)]);
        $stranger = User::create(['school_id' => $other->id, 'name' => 'Stranger', 'email' => 's@t.test', 'password' => 'x', 'status' => 'active', 'email_verified_at' => now()]);
        $this->actingAs($this->owner)->patch("/students/{$this->student->id}", ['first_name' => 'Almaz', 'gender' => 'female', 'status' => 'active', 'address' => 'Bole']);
        $this->actingAs($this->owner)->patch('/settings/school', ['name' => 'Renamed', 'calendar_preference' => 'both', 'missing_mark_policy' => 'zero', 'enabled_languages' => ['en'], 'discount_limit_percent' => 10, 'waiver_limit_amount' => 1000, 'payment_due_days' => 14, 'allow_overlapping_terms' => false])->assertSessionHasNoErrors();

        AuditLog::record($stranger, 'student.updated', $this->student, null, ['x' => 1], 'not for us');

        $props = fn (string $qs) => $this->actingAs($this->owner)->get('/audit-log'.$qs)->original->getData()['page']['props'];

        $this->assertSame(2, $props('')['logs']['total'], 'the other school\'s entry is never shown');
        $this->assertSame(1, $props('?entity=Student')['logs']['total']);
        $this->assertSame(1, $props('?action=school.settings_changed')['logs']['total']);
        $this->assertSame(1, $props('?q=Almaz')['logs']['total']);
        $this->assertSame(2, $props('?user_id='.$this->owner->id)['logs']['total']);
        $this->assertSame(0, $props('?user_id='.$stranger->id)['logs']['total']);
        $this->assertSame(2, $props('?from='.now()->toDateString().'&to='.now()->addDay()->toDateString())['logs']['total']);
        $this->assertSame(0, $props('?to='.now()->subDays(3)->toDateString())['logs']['total']);
    }
}
