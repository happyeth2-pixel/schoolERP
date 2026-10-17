<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Two equally valid ways to get service: a parent uses the portal, or a parent walks into the school and staff help
 * them. Nothing here may depend on the parent having an account, an email, a smartphone or a computer, and helping a
 * parent must never widen what a member of staff may do: every part of the "help a parent" page and the "record
 * payment received" flow is gated by the permission that already guards that information, inside the staff member's
 * own branch, and every money movement leaves an audit entry.
 */
class StaffAssistedServiceTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private User $accountant;       // verify-payments + manage-invoices + view-financial-reports, Main
    private User $receptionist;     // manage-students only, Main
    private User $viewer;           // manage-students + view-financial-reports (can see fees, cannot take money), Main
    private User $teacher;
    private User $portalParent;     // has an account (linked to student A)
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        $this->accountant = $this->makeUser('accountant', $this->branchA->id, ['manage-invoices', 'verify-payments', 'view-financial-reports']);
        $this->receptionist = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students', 'manage-parents']);
        $this->viewer = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students', 'view-financial-reports']);
        $this->teacher = $this->makeUser('teacher', $this->branchA->id, ['mark-attendance']);
        $this->portalParent = $this->makeParent($this->studentA);
        $this->portalParent->update(['phone' => '+251911000111']);
    }

    private function invoice(float $amount = 1000, $student = null, array $extra = []): Invoice
    {
        $i = Invoice::create($extra + ['student_id' => ($student ?? $this->studentA)->id, 'academic_year_id' => $this->year->id, 'invoice_number' => 'INV-A'.(++$this->n), 'total_amount' => $amount, 'status' => 'pending', 'frequency' => 'annual', 'period_label' => 'Term '.$this->n, 'period_start' => now()->addDays($this->n)->toDateString()]);
        $i->items()->create(['description' => 'Tuition', 'amount' => $amount, 'item_type' => 'fee']);

        return $i->fresh();
    }

    private function record(Invoice $i, array $over = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->accountant)->post("/invoices/{$i->id}/record-payment", $over + ['amount' => 400, 'method' => 'cash', 'paid_by_name' => 'Almaz Tesfaye']);
    }

    private function desk(Student|int $student, ?User $as = null)
    {
        $id = $student instanceof Student ? $student->id : $student;

        return $this->actingAs($as ?? $this->receptionist)->get("/students/{$id}/help");
    }

    private function deskProps($response): array
    {
        return json_decode(json_encode($this->props($response->assertOk())), true);
    }

    private function mark(string $date, string $status, $student = null): void
    {
        $enrollment = Enrollment::where('student_id', ($student ?? $this->studentA)->id)->firstOrFail();
        AttendanceRecord::create(['enrollment_id' => $enrollment->id, 'date' => $date, 'status' => $status, 'marked_by' => $this->teacher->id]);
    }

    // ================================================================== the "help a parent" page: who sees what

    public function test_a_receptionist_sees_the_report_card_and_the_parents_but_not_money_or_attendance(): void
    {
        $this->invoice();
        $props = $this->deskProps($this->desk($this->studentA));

        $this->assertTrue($props['can']['report_card']);
        $this->assertTrue($props['can']['profile']);
        $this->assertFalse($props['can']['fees']);
        $this->assertFalse($props['can']['attendance']);
        $this->assertNull($props['fees'], 'no money figure is sent to the browser');
        $this->assertNull($props['attendance']);
        $this->assertStringContainsString('/report-card', $props['reportCard']['view_url']);
        $this->assertNotEmpty($props['student']['guardians'][0]['phone'], 'the front desk can see the parent\'s number to call them');
    }

    public function test_someone_who_may_see_fees_sees_them_and_only_the_record_button_needs_more(): void
    {
        $bill = $this->invoice(1000);
        $this->record($bill, ['amount' => 300]);

        $viewer = $this->deskProps($this->desk($this->studentA, $this->viewer));
        $this->assertEquals(700.0, (float) $viewer['fees']['owed']);
        $this->assertNull($viewer['fees']['invoices'][0]['record_url'], 'may look at the bill, may not take money');

        $accountant = $this->deskProps($this->desk($this->studentA, $this->accountant));
        $this->assertNotNull($accountant['fees']['invoices'][0]['record_url']);
        $this->assertEquals(700.0, (float) $accountant['fees']['owed']);
        $this->assertCount(1, $accountant['fees']['recent_payments']);
        $this->assertNotNull($accountant['fees']['recent_payments'][0]['receipt_url']);
    }

    public function test_an_accountant_without_student_permissions_still_gets_the_fees_but_no_profile_or_report_card(): void
    {
        $this->invoice();
        $props = $this->deskProps($this->desk($this->studentA, $this->accountant));

        $this->assertTrue($props['can']['fees']);
        $this->assertFalse($props['can']['report_card']);
        $this->assertNull($props['reportCard']);
        $this->assertNull($props['profileUrl']);
        $this->assertNull($props['student']['guardians'][0]['phone'], 'a parent\'s number is only for people who manage students');
    }

    public function test_attendance_needs_its_own_permission_and_uses_the_same_formula_as_the_portal(): void
    {
        $this->mark('2026-09-10', 'present');
        $this->mark('2026-09-11', 'late');
        $this->mark('2026-09-12', 'absent');
        $this->mark(now()->toDateString(), 'sick');
        $analyst = $this->makeUser('branch_admin', $this->branchA->id, ['view-attendance-reports']);

        $props = $this->deskProps($this->desk($this->studentA, $analyst));
        $this->assertSame('sick', $props['attendance']['today']['status']);
        $this->assertSame(4, $props['attendance']['totals']['marked']);
        $this->assertEquals(50.0, (float) $props['attendance']['rate'], 'present + late out of every recorded day');
        $this->assertNull($props['fees']);

        $this->assertNull($this->deskProps($this->desk($this->studentA, $this->receptionist))['attendance']);
    }

    public function test_attendance_says_nothing_recorded_yet_rather_than_guessing(): void
    {
        $analyst = $this->makeUser('branch_admin', $this->branchA->id, ['view-attendance-reports']);
        $props = $this->deskProps($this->desk($this->studentA, $analyst));

        $this->assertNull($props['attendance']['today']['status']);
        $this->assertNull($props['attendance']['rate']);
    }

    public function test_people_with_no_relevant_permission_cannot_open_the_page(): void
    {
        $this->desk($this->studentA, $this->teacher)->assertForbidden();
        $this->desk($this->studentA, $this->makeUser('branch_admin', $this->branchA->id, ['manage-announcements']))->assertForbidden();
    }

    public function test_a_parent_cannot_open_the_staff_page_even_for_their_own_child(): void
    {
        $this->desk($this->studentA, $this->portalParent)->assertForbidden();
    }

    public function test_the_page_stays_inside_the_staff_members_branch(): void
    {
        $this->desk($this->studentB, $this->receptionist)->assertForbidden();
        $this->desk($this->studentB, $this->accountant)->assertForbidden();

        $this->desk($this->studentB, $this->owner)->assertOk();
        $east = $this->makeUser('accountant', $this->branchB->id, ['manage-invoices']);
        $this->desk($this->studentB, $east)->assertOk();
        $this->desk($this->studentA, $east)->assertForbidden();
    }

    public function test_an_auditor_can_read_but_is_never_offered_the_record_button(): void
    {
        $bill = $this->invoice();
        $auditor = $this->makeUser('auditor', null);

        $props = $this->deskProps($this->desk($this->studentA, $auditor));
        $this->assertTrue($props['can']['fees']);
        $this->assertNull($props['fees']['invoices'][0]['record_url']);
        $this->actingAs($auditor)->post("/invoices/{$bill->id}/record-payment", ['amount' => 10, 'method' => 'cash', 'paid_by_name' => 'X'])->assertForbidden();
    }

    public function test_a_student_whose_parent_has_no_account_is_served_exactly_the_same(): void
    {
        $this->assertSame(0, $this->studentB->guardians()->count());
        $east = $this->makeUser('branch_admin', $this->branchB->id, ['manage-students', 'manage-invoices', 'verify-payments', 'view-attendance-reports']);
        $bill = $this->invoice(500, $this->studentB);

        $props = $this->deskProps($this->desk($this->studentB, $east));
        $this->assertSame([], $props['student']['guardians']);
        $this->assertEquals(500.0, (float) $props['fees']['owed']);
        $this->assertNotNull($props['reportCard']);
        $this->assertNotNull($props['attendance']);

        $this->record($bill, ['amount' => 500, 'paid_by_name' => 'Grandfather Tesfaye'], $east)->assertSessionHasNoErrors();
        $this->assertSame('paid', $bill->fresh()->figures()['status']);
    }

    public function test_waived_and_settled_bills_offer_no_record_button_and_owe_nothing(): void
    {
        $waived = $this->invoice(800, null, ['is_waived' => true, 'waiver_reason' => 'scholarship']);
        $paid = $this->invoice(100);
        $this->record($paid, ['amount' => 100]);

        $rows = collect($this->deskProps($this->desk($this->studentA, $this->accountant))['fees']['invoices'])->keyBy('id');
        $this->assertNull($rows[$waived->id]['record_url']);
        $this->assertEquals(0.0, (float) $rows[$waived->id]['balance']);
        $this->assertNull($rows[$paid->id]['record_url']);
        $this->assertEquals(0.0, (float) $this->deskProps($this->desk($this->studentA, $this->accountant))['fees']['owed']);
    }

    // ================================================================== recording money received at the school

    public function test_recording_cash_creates_a_verified_payment_with_a_receipt_and_lowers_the_balance(): void
    {
        $bill = $this->invoice(1000);

        $this->record($bill, ['amount' => 400, 'reference_number' => null])->assertSessionHasNoErrors();

        $p = Payment::firstOrFail();
        $this->assertSame('verified', $p->status);
        $this->assertSame('counter', $p->channel);
        $this->assertSame($this->accountant->id, $p->submitted_by);
        $this->assertSame($this->accountant->id, $p->verified_by);
        $this->assertSame('Almaz Tesfaye', $p->paid_by_name);
        $this->assertMatchesRegularExpression('/^RCT-\d{4}-\d{6}$/', $p->receipt_number);
        $this->assertEquals(600.0, $bill->fresh()->figures()['outstanding']);
        $this->assertSame('partially_paid', $bill->fresh()->figures()['status']);
    }

    public function test_the_redirect_lands_on_the_receipt_page_which_offers_the_printable_receipt(): void
    {
        $bill = $this->invoice();
        $this->record($bill)->assertRedirect(route('payments.review', Payment::firstOrFail()));

        $review = json_decode(json_encode($this->props($this->actingAs($this->accountant)->get('/payments/'.Payment::firstOrFail()->id)->assertOk())), true);
        $this->assertSame('counter', $review['payment']['channel']);
        $this->assertSame('Almaz Tesfaye', $review['payment']['paid_by_name']);
        $this->assertNotNull($review['payment']['receipt_url']);
    }

    public function test_the_receipt_numbers_of_portal_and_counter_payments_share_one_sequence(): void
    {
        $bill = $this->invoice(1000);
        $proof = Payment::create(['invoice_id' => $bill->id, 'amount' => 100, 'method' => 'bank_transfer', 'reference_number' => 'R1', 'submitted_by' => $this->portalParent->id, 'status' => 'pending']);
        $this->actingAs($this->accountant)->post("/payments/{$proof->id}/verify");
        $this->record($bill);

        $numbers = Payment::orderBy('id')->pluck('receipt_number')->all();
        $this->assertCount(2, array_unique($numbers));
        $this->assertSame((int) substr($numbers[0], -6) + 1, (int) substr($numbers[1], -6));
    }

    public function test_bank_and_mobile_money_need_the_slip_reference_but_cash_does_not(): void
    {
        $bill = $this->invoice(1000);

        foreach (['bank_transfer', 'mobile_money'] as $method) {
            $this->record($bill, ['method' => $method])->assertSessionHasErrors('reference_number');
        }
        $this->assertSame(0, Payment::count());

        $this->record($bill, ['method' => 'bank_transfer', 'reference_number' => 'FT26-778812'])->assertSessionHasNoErrors();
        $this->assertSame('FT26-778812', Payment::firstOrFail()->reference_number);
    }

    public function test_the_name_of_the_person_who_paid_is_required(): void
    {
        $this->record($this->invoice(), ['paid_by_name' => ''])->assertSessionHasErrors('paid_by_name');
        $this->assertSame(0, Payment::count());
    }

    public function test_nobody_can_pay_more_than_the_balance_or_nothing_or_into_a_waived_bill(): void
    {
        $bill = $this->invoice(1000);
        $this->record($bill, ['amount' => 1000.01])->assertSessionHasErrors('amount');
        $this->record($bill, ['amount' => 0])->assertSessionHasErrors('amount');
        $this->record($bill, ['amount' => -5])->assertSessionHasErrors('amount');
        $this->record($bill, ['amount' => 'lots'])->assertSessionHasErrors('amount');

        $waived = $this->invoice(500, null, ['is_waived' => true, 'waiver_reason' => 'x']);
        $this->record($waived, ['amount' => 10])->assertSessionHasErrors('amount');

        $this->assertSame(0, Payment::count());
        $this->record($bill, ['amount' => 1000])->assertSessionHasNoErrors();
        $this->assertSame('paid', $bill->fresh()->figures()['status']);
        $this->record($bill, ['amount' => 1])->assertSessionHasErrors('amount');
    }

    public function test_an_accidental_double_submit_is_stopped(): void
    {
        $bill = $this->invoice(1000);
        $this->record($bill, ['amount' => 200])->assertSessionHasNoErrors();
        $this->record($bill, ['amount' => 200])->assertSessionHasErrors('amount');
        $this->assertSame(1, Payment::count());

        $this->record($bill, ['amount' => 250])->assertSessionHasNoErrors();   // a different amount is a different payment
        Carbon::setTestNow(now()->addMinutes(2));
        $this->record($bill, ['amount' => 200])->assertSessionHasNoErrors();   // the same amount later is allowed
        $this->assertSame(3, Payment::count());
    }

    public function test_a_slip_photo_can_be_kept_and_is_private(): void
    {
        Storage::fake('local');
        $bill = $this->invoice(1000);

        $this->record($bill, ['method' => 'mobile_money', 'reference_number' => 'TB-1', 'proof' => UploadedFile::fake()->image('slip.png', 200, 200)])->assertSessionHasNoErrors();

        $p = Payment::firstOrFail();
        $this->assertNotNull($p->proof_path);
        Storage::disk('local')->assertExists($p->proof_path);
        $this->actingAs($this->accountant)->get("/payments/{$p->id}/proof")->assertOk();
        $this->actingAs($this->portalParent)->get("/payments/{$p->id}/proof")->assertNotFound();
    }

    public function test_a_file_that_is_not_an_image_or_pdf_is_refused(): void
    {
        Storage::fake('local');
        $this->record($this->invoice(), ['proof' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])->assertSessionHasErrors('proof');
        $this->assertSame(0, Payment::count());
    }

    public function test_a_parent_who_already_sent_proof_online_is_warned_before_the_money_is_counted_twice(): void
    {
        $bill = $this->invoice(1000);
        Payment::create(['invoice_id' => $bill->id, 'amount' => 400, 'method' => 'bank_transfer', 'reference_number' => 'R9', 'submitted_by' => $this->portalParent->id, 'status' => 'pending']);

        $page = json_decode(json_encode($this->props($this->actingAs($this->accountant)->get("/invoices/{$bill->id}/record-payment")->assertOk())), true);
        $this->assertCount(1, $page['waiting']);
        $this->assertSame('R9', $page['waiting'][0]['reference_number']);
        $this->assertStringContainsString('/payments/', $page['waiting'][0]['review_url']);
    }

    public function test_the_form_for_a_settled_or_waived_bill_sends_you_back_to_the_bill(): void
    {
        $paid = $this->invoice(100);
        $this->record($paid, ['amount' => 100]);
        $this->actingAs($this->accountant)->get("/invoices/{$paid->id}/record-payment")->assertRedirect(route('invoices.show', $paid));
    }

    // ================================================================== permission and branch boundaries

    public function test_only_people_holding_verify_payments_can_record_money(): void
    {
        $bill = $this->invoice();

        foreach ([$this->receptionist, $this->viewer, $this->teacher, $this->makeUser('branch_admin', $this->branchA->id, ['manage-invoices'])] as $who) {
            $this->record($bill, [], $who)->assertForbidden();
            $this->actingAs($who)->get("/invoices/{$bill->id}/record-payment")->assertForbidden();
        }
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, AuditLog::where('action', 'payment.recorded')->count());
    }

    public function test_a_parent_can_never_record_a_payment_and_gets_a_plain_not_found(): void
    {
        $bill = $this->invoice();
        $this->record($bill, [], $this->portalParent)->assertNotFound();
        $this->assertSame(0, Payment::count());
    }

    public function test_a_branch_accountant_cannot_record_into_another_branchs_bill(): void
    {
        $eastBill = $this->invoice(900, $this->studentB);

        $this->record($eastBill)->assertForbidden();
        $this->actingAs($this->accountant)->get("/invoices/{$eastBill->id}/record-payment")->assertForbidden();
        $this->assertSame(0, Payment::count());

        $this->record($eastBill, [], $this->owner)->assertSessionHasNoErrors();
        $this->assertSame(1, Payment::count());
    }

    public function test_owner_and_other_school_boundaries_hold(): void
    {
        $other = \App\Models\School::create(['name' => 'Other', 'code' => 'OTH']);
        $stranger = User::create(['school_id' => $other->id, 'name' => 'S', 'email' => 's@o.test', 'password' => 'x', 'status' => 'active']);
        $stranger->assignTier('owner');
        $bill = $this->invoice();

        $this->record($bill, [], $stranger)->assertForbidden();
        $this->desk($this->studentA, $stranger)->assertForbidden();
    }

    // ================================================================== the financial audit trail

    public function test_recording_is_audited_with_who_what_how_much_and_the_receipt(): void
    {
        $bill = $this->invoice(1000);
        $this->record($bill, ['amount' => 350, 'method' => 'mobile_money', 'reference_number' => 'TB-55', 'paid_by_name' => 'Almaz Tesfaye'])->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'payment.recorded')->firstOrFail();
        $this->assertSame($this->accountant->id, $log->user_id);
        $this->assertEquals(350.0, (float) $log->after['amount']);
        $this->assertSame('mobile_money', $log->after['method']);
        $this->assertSame('TB-55', $log->after['reference_number']);
        $this->assertSame('Almaz Tesfaye', $log->after['paid_by_name']);
        $this->assertSame(Payment::firstOrFail()->receipt_number, $log->after['receipt_number']);
        $this->assertEquals(650.0, (float) $log->after['balance_after']);
        $this->assertSame('counter', $log->after['channel']);
        $this->assertSame(0, AuditLog::where('action', 'payment.created')->count(), 'one plain entry, not a second raw creation row');
    }

    public function test_the_portal_path_still_audits_as_before(): void
    {
        $bill = $this->invoice(1000);
        $this->actingAs($this->portalParent);
        $p = Payment::create(['invoice_id' => $bill->id, 'amount' => 100, 'method' => 'bank_transfer', 'reference_number' => 'R2', 'submitted_by' => $this->portalParent->id, 'status' => 'pending']);
        $this->assertSame(1, AuditLog::where('action', 'payment.created')->count());
        $this->actingAs($this->accountant)->post("/payments/{$p->id}/verify");
        $this->assertSame(1, AuditLog::where('action', 'payment.verified')->count());
        $this->assertSame('portal', $p->fresh()->channel);
    }

    public function test_a_recorded_payment_can_be_refunded_only_by_someone_holding_refund_payments(): void
    {
        $bill = $this->invoice(1000);
        $this->record($bill, ['amount' => 500]);
        $p = Payment::firstOrFail();

        $this->actingAs($this->accountant)->post("/payments/{$p->id}/refund", ['amount' => 100, 'kind' => 'refund', 'reason' => 'overpaid'])->assertForbidden();

        $this->actingAs($this->owner)->post("/payments/{$p->id}/refund", ['amount' => 100, 'kind' => 'refund', 'reason' => 'paid twice at desk'])->assertSessionHasNoErrors();
        $this->assertEquals(600.0, $bill->fresh()->figures()['outstanding']);
        $this->assertSame(1, AuditLog::where('action', 'payment_refund.created')->count());
    }

    public function test_recorded_money_counts_in_the_school_figures(): void
    {
        $bill = $this->invoice(1000);
        $this->record($bill, ['amount' => 250]);

        $this->assertEquals(250.0, Finance::summarize(Finance::invoicesFor($this->accountant)->get())['collected']);
    }

    // ================================================================== the parent's own view of the same money

    public function test_a_parent_with_the_portal_sees_the_desk_payment_and_can_download_its_receipt(): void
    {
        $bill = $this->invoice(1000);
        $this->record($bill, ['amount' => 300]);
        $p = Payment::firstOrFail();

        $page = json_decode(json_encode($this->props($this->actingAs($this->portalParent)->get("/invoices/{$bill->id}")->assertOk())), true);
        $this->assertEquals(700.0, (float) $page['invoice']['balance']);
        $this->assertSame($p->receipt_number, $page['invoice']['payments'][0]['receipt_number']);
        $this->actingAs($this->portalParent)->get("/payments/{$p->id}/receipt")->assertOk();
    }

    public function test_the_portal_parent_is_told_and_another_family_is_not(): void
    {
        $bill = $this->invoice(1000);
        $other = $this->makeParent($this->studentB);
        $this->record($bill, ['amount' => 300]);

        $this->assertSame(1, $this->portalParent->notifications()->count());
        $this->assertSame(0, $other->notifications()->count());
        $this->actingAs($other)->get('/payments/'.Payment::firstOrFail()->id.'/receipt')->assertNotFound();
    }

    public function test_the_receipt_says_who_paid_and_that_it_was_received_at_the_school(): void
    {
        $bill = $this->invoice(1000);
        $this->record($bill, ['amount' => 300, 'paid_by_name' => 'Almaz Tesfaye']);

        $html = view('receipt.pdf', ['payment' => Payment::firstOrFail()->load('invoice.student'), 'student' => $this->studentA])->render();
        $this->assertStringContainsString('Almaz Tesfaye', $html);
        $this->assertStringContainsString('school office', strtolower($html));
    }

    public function test_the_portal_path_is_unchanged_and_still_never_needs_staff_to_type_anything(): void
    {
        $bill = $this->invoice(1000);
        Storage::fake('local');

        $this->actingAs($this->portalParent)->post("/invoices/{$bill->id}/payments", ['amount' => 200, 'method' => 'bank_transfer', 'reference_number' => 'BT1', 'proof' => UploadedFile::fake()->image('p.png')])->assertSessionHasNoErrors();
        $p = Payment::firstOrFail();
        $this->assertSame('pending', $p->status);
        $this->assertSame('portal', $p->channel);
        $this->assertNull($p->paid_by_name);
        $this->assertEquals(1000.0, $bill->fresh()->figures()['outstanding'], 'nothing counts until an accountant verifies it');

        $this->actingAs($this->accountant)->post("/payments/{$p->id}/verify")->assertSessionHasNoErrors();
        $this->assertEquals(800.0, $bill->fresh()->figures()['outstanding']);
    }
}
