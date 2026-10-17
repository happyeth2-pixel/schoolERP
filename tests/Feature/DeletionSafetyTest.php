<?php

namespace Tests\Feature;

use App\Exceptions\DeletionBlockedException;
use App\Models\AcademicYear;
use App\Models\Branch;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\FeeStructure;
use App\Models\Grade;
use App\Models\GradeBand;
use App\Models\Invoice;
use App\Models\MarkBatch;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** History is never erased: set-up records with dependants can't be deleted, and money/marks/students never are. */
class DeletionSafetyTest extends TestCase
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
        $this->owner = User::create(['school_id' => $this->school->id, 'name' => 'O', 'email' => 'o@t.test', 'password' => 'x', 'status' => 'active', 'email_verified_at' => now()]);
        $this->owner->assignTier('owner');
        $this->year = AcademicYear::create(['school_id' => $this->school->id, 'name' => '2026', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true]);
        $this->grade = Grade::create(['school_id' => $this->school->id, 'name' => 'G1', 'sort_order' => 1]);
        $this->section = Section::create(['branch_id' => $this->branch->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'A']);
        $this->student = Student::create(['school_id' => $this->school->id, 'branch_id' => $this->branch->id, 'student_id_number' => 'STU-1', 'first_name' => 'K', 'gender' => 'male', 'enrollment_date' => '2026-09-01', 'status' => 'active', 'id_card_token' => Student::newCardToken()]);
        Enrollment::create(['student_id' => $this->student->id, 'section_id' => $this->section->id, 'enrolled_on' => '2026-09-01', 'status' => 'active']);
    }

    public function test_a_section_with_students_cannot_be_deleted_and_nothing_cascades(): void
    {
        $this->actingAs($this->owner)->delete("/sections/{$this->section->id}")->assertSessionHasErrors('delete');

        $this->assertNotNull(Section::find($this->section->id));
        $this->assertSame(1, Enrollment::count(), 'the enrollment must not be cascaded away');
        $this->assertStringContainsString('1 student enrollment', session('errors')->first('delete'));
    }

    public function test_a_grade_with_sections_cannot_be_deleted_but_an_empty_one_can(): void
    {
        $this->actingAs($this->owner)->delete("/grades/{$this->grade->id}")->assertSessionHasErrors('delete');
        $this->assertNotNull(Grade::find($this->grade->id));

        $empty = Grade::create(['school_id' => $this->school->id, 'name' => 'Typo', 'sort_order' => 9]);
        $this->actingAs($this->owner)->delete("/grades/{$empty->id}")->assertSessionHasNoErrors();
        $this->assertNull(Grade::find($empty->id));
    }

    public function test_a_grade_with_only_an_archived_fee_item_is_still_protected(): void
    {
        $g = Grade::create(['school_id' => $this->school->id, 'name' => 'G2', 'sort_order' => 2]);
        $fee = FeeStructure::create(['branch_id' => $this->branch->id, 'grade_id' => $g->id, 'academic_year_id' => $this->year->id, 'name' => 'Tuition', 'amount' => 100, 'frequency' => 'monthly']);
        $fee->delete();

        $this->actingAs($this->owner)->delete("/grades/{$g->id}")->assertSessionHasErrors('delete');
        $this->assertNotNull(FeeStructure::withTrashed()->find($fee->id));
    }

    public function test_subjects_exams_and_terms_with_history_are_protected(): void
    {
        $subject = Subject::create(['school_id' => $this->school->id, 'name' => 'Math', 'code' => 'M']);
        $term = Term::create(['academic_year_id' => $this->year->id, 'name' => 'Term 1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-30']);
        $exam = Exam::create(['school_id' => $this->school->id, 'term_id' => $term->id, 'name' => 'Mid', 'weight_percent' => 100]);
        MarkBatch::create(['exam_id' => $exam->id, 'section_id' => $this->section->id, 'subject_id' => $subject->id, 'teacher_id' => $this->owner->id, 'status' => 'draft']);

        $this->actingAs($this->owner)->delete("/exams/{$exam->id}")->assertSessionHasErrors();
        $this->actingAs($this->owner)->delete("/subjects/{$subject->id}")->assertSessionHasErrors('delete');
        $this->actingAs($this->owner)->delete("/terms/{$term->id}")->assertSessionHasErrors('delete');

        $this->assertNotNull(Exam::find($exam->id));
        $this->assertNotNull(Subject::find($subject->id));
        $this->assertNotNull(Term::find($term->id));
        $this->assertSame(1, MarkBatch::count());
    }

    public function test_fee_items_and_payment_accounts_are_archived_not_erased(): void
    {
        $fee = FeeStructure::create(['branch_id' => $this->branch->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'Tuition', 'amount' => 100, 'frequency' => 'monthly']);
        $this->actingAs($this->owner)->delete("/fee-structures/{$fee->id}")->assertSessionHasNoErrors();

        $this->assertNull(FeeStructure::find($fee->id), 'gone from the working lists');
        $this->assertNotNull(FeeStructure::withTrashed()->find($fee->id), 'but the row is kept');

        $band = GradeBand::create(['school_id' => $this->school->id, 'letter' => 'A', 'min_score' => 90, 'max_score' => 100, 'sort_order' => 1]);
        $band->delete();
        $this->assertNotNull(GradeBand::withTrashed()->find($band->id));

        $account = PaymentAccount::create(['school_id' => $this->school->id, 'type' => 'bank', 'display_name' => 'CBE', 'account_number' => '1', 'account_name' => 'X']);
        $this->actingAs($this->owner)->delete("/settings/payment-accounts/{$account->id}")->assertSessionHasNoErrors();
        $this->assertNotNull(PaymentAccount::withTrashed()->find($account->id));
    }

    public function test_history_records_can_never_be_deleted_through_the_model(): void
    {
        $invoice = Invoice::create(['student_id' => $this->student->id, 'academic_year_id' => $this->year->id, 'invoice_number' => 'INV-1', 'total_amount' => 100, 'status' => 'pending', 'frequency' => 'annual', 'period_start' => '2026-09-01']);
        $payment = Payment::create(['invoice_id' => $invoice->id, 'amount' => 50, 'method' => 'cash', 'status' => 'pending', 'submitted_by' => $this->owner->id]);

        foreach ([$payment, $invoice, $this->student, $this->student->enrollments()->first()] as $record) {
            try {
                $record->delete();
                $this->fail(class_basename($record).' was deleted');
            } catch (DeletionBlockedException $e) {
                $this->assertStringContainsString('never deleted', $e->getMessage());
            }
            try {
                $record->forceDelete();
            } catch (DeletionBlockedException|\BadMethodCallException) {
                // forceDelete does not exist on these models, or is blocked: either way nothing is removed
            }
        }

        $this->assertSame(1, Payment::count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, Student::count());
        $this->assertSame(1, Enrollment::count());
    }

    public function test_a_deactivated_account_cannot_sign_in_or_keep_using_the_system(): void
    {
        $staff = User::create(['school_id' => $this->school->id, 'name' => 'T', 'email' => 't@t.test', 'password' => 'secret-pass-1', 'status' => 'active', 'email_verified_at' => now()]);
        $staff->assignTier('accountant');

        $staff->update(['status' => 'inactive']);

        $this->post('/login', ['phone' => 't@t.test', 'password' => 'secret-pass-1'])->assertSessionHasErrors('phone');
        $this->assertGuest();

        // already signed in when deactivated: the very next request signs them out
        $this->actingAs($staff)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_the_only_owner_cannot_deactivate_themselves(): void
    {
        $this->owner->update(['password' => 'password']);
        $this->actingAs($this->owner)->from('/profile')->delete('/profile', ['password' => 'password'])->assertSessionHasErrors('password');
        $this->assertSame('active', $this->owner->fresh()->status);
    }
}
