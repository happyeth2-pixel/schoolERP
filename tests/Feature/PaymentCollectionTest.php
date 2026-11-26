<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\OptionalService;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * The walk-in collection grid: one table per class, a box per student per thing they could owe (tuition,
 * each extra service they are actually enrolled in, books). The boxes are a convenient way to TYPE the
 * amount; underneath, each student's total still applies oldest-invoice-first through CounterPayment,
 * exactly like the existing one-student lump sum -- payments attach to a whole invoice, never to one line
 * of it. See StudentBillingSnapshot for the proportional "owed per category" estimate this and the grid
 * both rely on.
 */
class PaymentCollectionTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private User $accountant;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        $this->accountant = $this->makeUser('accountant', $this->branchA->id, ['manage-invoices', 'verify-payments']);
    }

    private function invoiceWithItems(array $items, $student = null): Invoice
    {
        $total = array_sum(array_column($items, 'amount'));
        $i = Invoice::create([
            'student_id' => ($student ?? $this->studentA)->id,
            'academic_year_id' => $this->year->id,
            'invoice_number' => 'INV-C'.(++$this->n),
            'total_amount' => $total,
            'status' => 'pending',
            'frequency' => 'annual',
            'period_label' => 'Term '.$this->n,
            'period_start' => now()->addDays($this->n)->toDateString(),
        ]);
        foreach ($items as $item) {
            $i->items()->create($item);
        }

        return $i->fresh();
    }

    private function service(string $name = 'Transport', string $category = 'transport'): OptionalService
    {
        return OptionalService::create([
            'branch_id' => $this->branchA->id,
            'academic_year_id' => $this->year->id,
            'name' => $name,
            'category' => $category,
            'amount' => 200,
            'frequency' => 'termly',
        ]);
    }

    // ------------------------------------------------------------------ the grid itself

    public function test_the_grid_shows_tuition_books_and_only_services_the_student_is_actually_enrolled_in(): void
    {
        $transport = $this->service('Transport', 'transport');
        $this->studentA->optionalServices()->attach($transport->id, ['enrolled_on' => now(), 'custom_amount' => null]);
        $second = $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2'); // not enrolled in anything

        $this->invoiceWithItems([
            ['description' => 'Tuition', 'amount' => 1000, 'item_type' => 'fee'],
            ['description' => 'Transport', 'amount' => 200, 'item_type' => 'service', 'optional_service_id' => $transport->id],
        ], $this->studentA);

        $page = $this->props($this->actingAs($this->accountant)->get("/payments/collect?section={$this->sectionA->id}")->assertOk());
        $grid = $page['grid'];

        $this->assertSame([$transport->id], array_column($grid['services'], 'id'));

        $a = collect($grid['students'])->firstWhere('id', $this->studentA->id);
        $this->assertSame(1000.0, $a['tuition_owed']);
        $this->assertTrue($a['services'][0]['assigned']);
        $this->assertSame(200.0, $a['services'][0]['owed']);

        $b = collect($grid['students'])->firstWhere('id', $second->id);
        $this->assertSame(0.0, $b['tuition_owed']);
        $this->assertFalse($b['services'][0]['assigned'], 'not enrolled in Transport at all');
    }

    public function test_only_people_who_may_verify_payments_can_see_or_use_the_grid(): void
    {
        $noPermission = $this->makeUser('branch_admin', $this->branchA->id, []);
        $this->actingAs($noPermission)->get('/payments/collect')->assertForbidden();
        $this->actingAs($noPermission)->post("/payments/collect/{$this->sectionA->id}", ['method' => 'cash', 'rows' => []])->assertForbidden();
    }

    public function test_a_branch_scoped_user_cannot_open_another_branchs_class(): void
    {
        $this->actingAs($this->accountant)->get("/payments/collect?section={$this->sectionB->id}")->assertForbidden();
    }

    // ------------------------------------------------------------------ submitting the grid

    public function test_a_partial_amount_in_one_box_is_recorded_verified_immediately_and_the_rest_stays_owed(): void
    {
        $invoice = $this->invoiceWithItems([['description' => 'Tuition', 'amount' => 1000, 'item_type' => 'fee']]);

        $this->actingAs($this->accountant)->post("/payments/collect/{$this->sectionA->id}", [
            'method' => 'cash',
            'rows' => [['student_id' => $this->studentA->id, 'tuition' => 300, 'book' => 0, 'services' => []]],
        ])->assertSessionHasNoErrors();

        $payment = Payment::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('verified', $payment->status, 'a staff-recorded (counter) payment needs no separate approval');
        $this->assertSame(Payment::CHANNEL_COUNTER, $payment->channel);
        $this->assertEquals(300, $payment->amount);
        $this->assertSame('Tuition: 300.00', $payment->note);
        $this->assertEquals(700, $invoice->fresh()->balance(), 'the rest stays owed, same as any other partial payment');
    }

    public function test_one_submission_can_pay_tuition_a_service_and_books_together_for_one_student(): void
    {
        $transport = $this->service('Transport', 'transport');
        $this->studentA->optionalServices()->attach($transport->id, ['enrolled_on' => now(), 'custom_amount' => null]);

        $this->invoiceWithItems([
            ['description' => 'Tuition', 'amount' => 500, 'item_type' => 'fee'],
            ['description' => 'Transport', 'amount' => 200, 'item_type' => 'service', 'optional_service_id' => $transport->id],
            ['description' => 'A Book', 'amount' => 50, 'item_type' => 'book'],
        ]);

        $this->actingAs($this->accountant)->post("/payments/collect/{$this->sectionA->id}", [
            'method' => 'cash',
            'rows' => [['student_id' => $this->studentA->id, 'tuition' => 500, 'book' => 50, 'services' => [['service_id' => $transport->id, 'amount' => 200]]]],
        ])->assertSessionHasNoErrors();

        // one mixed invoice, paid in full by one combined payment (payments attach to the WHOLE invoice)
        $payments = Payment::whereHas('invoice', fn ($q) => $q->where('student_id', $this->studentA->id))->get();
        $this->assertCount(1, $payments);
        $this->assertEquals(750, $payments->first()->amount);
        $this->assertStringContainsString('Tuition: 500.00', $payments->first()->note);
        $this->assertStringContainsString('Transport: 200.00', $payments->first()->note);
        $this->assertStringContainsString('Books: 50.00', $payments->first()->note);
    }

    public function test_the_whole_class_can_be_collected_in_one_submission(): void
    {
        $second = $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2');
        $this->invoiceWithItems([['description' => 'Tuition', 'amount' => 500, 'item_type' => 'fee']], $this->studentA);
        $this->invoiceWithItems([['description' => 'Tuition', 'amount' => 800, 'item_type' => 'fee']], $second);

        $this->actingAs($this->accountant)->post("/payments/collect/{$this->sectionA->id}", [
            'method' => 'cash',
            'rows' => [
                ['student_id' => $this->studentA->id, 'tuition' => 500, 'book' => 0, 'services' => []],
                ['student_id' => $second->id, 'tuition' => 800, 'book' => 0, 'services' => []],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Payment::where('status', 'verified')->count());
    }

    public function test_a_row_with_more_than_the_students_total_owed_is_skipped_not_partially_applied(): void
    {
        $this->invoiceWithItems([['description' => 'Tuition', 'amount' => 100, 'item_type' => 'fee']]);

        $this->actingAs($this->accountant)->post("/payments/collect/{$this->sectionA->id}", [
            'method' => 'cash',
            'rows' => [['student_id' => $this->studentA->id, 'tuition' => 5000, 'book' => 0, 'services' => []]],
        ])->assertSessionHasErrors('rows'); // must read as a real, visible failure -- never a silent no-op

        $this->assertSame(0, Payment::count(), 'more than everything currently billed must not be recorded at all');
    }

    public function test_rows_with_nothing_entered_are_simply_ignored(): void
    {
        $this->invoiceWithItems([['description' => 'Tuition', 'amount' => 500, 'item_type' => 'fee']]);

        $this->actingAs($this->accountant)->post("/payments/collect/{$this->sectionA->id}", [
            'method' => 'cash',
            'rows' => [['student_id' => $this->studentA->id, 'tuition' => 0, 'book' => 0, 'services' => []]],
        ])->assertSessionHasErrors('rows');

        $this->assertSame(0, Payment::count());
    }

    public function test_every_payment_recorded_is_audited(): void
    {
        $this->invoiceWithItems([['description' => 'Tuition', 'amount' => 500, 'item_type' => 'fee']]);

        $this->actingAs($this->accountant)->post("/payments/collect/{$this->sectionA->id}", [
            'method' => 'cash',
            'rows' => [['student_id' => $this->studentA->id, 'tuition' => 500, 'book' => 0, 'services' => []]],
        ]);

        $log = AuditLog::where('action', 'payment.recorded')->firstOrFail();
        $this->assertTrue($log->after['part_of_class_collection']);
        $this->assertSame('G1 A', $log->after['section']);
    }

    /** A real bug found by a live UX audit: when ONE student in a mixed submission is over their balance
     *  while another succeeds, the whole thing must not quietly look like total success -- the student(s)
     *  it skipped need their own clearly separate (amber) message, distinct from the (green) success one. */
    public function test_a_partly_successful_submission_shows_a_separate_warning_for_who_was_skipped(): void
    {
        $second = $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2');
        $this->invoiceWithItems([['description' => 'Tuition', 'amount' => 500, 'item_type' => 'fee']], $this->studentA);
        $this->invoiceWithItems([['description' => 'Tuition', 'amount' => 100, 'item_type' => 'fee']], $second);

        $response = $this->actingAs($this->accountant)->post("/payments/collect/{$this->sectionA->id}", [
            'method' => 'cash',
            'rows' => [
                ['student_id' => $this->studentA->id, 'tuition' => 500, 'book' => 0, 'services' => []],
                ['student_id' => $second->id, 'tuition' => 9999, 'book' => 0, 'services' => []], // way over their 100
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');
        $response->assertSessionHas('warning');
        $this->assertStringContainsString('Second', session('warning'));
        $this->assertSame(1, Payment::count());
    }
}
