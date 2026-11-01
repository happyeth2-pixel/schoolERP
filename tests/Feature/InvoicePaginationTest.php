<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * InvoiceController@index used to load every invoice in scope (with every item/payment/refund relation) just to
 * build the on-screen list, the totals bar and the status filter. This pins the fix: the screen list is paginated
 * at the database level, the totals bar and the status filter both still cover the WHOLE filtered set (not just
 * the page on screen), the export is never limited to a page, and the query count stays flat as invoices pile up.
 */
class InvoicePaginationTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        Carbon::setTestNow('2026-10-15 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(float $amount = 1000, ?Student $student = null): Invoice
    {
        $i = Invoice::create(['student_id' => ($student ?? $this->studentA)->id, 'academic_year_id' => $this->year->id, 'invoice_number' => 'INV-'.(++$this->n), 'frequency' => 'annual', 'period_start' => Carbon::parse('2026-09-01')->addDays($this->n)->toDateString()]);
        $i->items()->create(['description' => 'Tuition', 'amount' => $amount, 'item_type' => 'fee']);

        return $i->fresh();
    }

    private function pay(Invoice $i, float $amount): Payment
    {
        return Payment::create(['invoice_id' => $i->id, 'amount' => $amount, 'method' => 'cash', 'status' => 'verified', 'submitted_by' => $this->owner->id, 'verified_by' => $this->owner->id, 'verified_at' => now(), 'receipt_number' => 'R'.(++$this->n)]);
    }

    public function test_the_list_is_paginated(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->invoice(1000);
        }

        $page = $this->props($this->actingAs($this->owner)->get('/invoices')->assertOk());

        $this->assertCount(25, $page['invoices']);
        $this->assertSame(30, $page['invoicesMeta']['total']);
        $this->assertSame(2, $page['invoicesMeta']['last_page']);
    }

    public function test_the_totals_bar_covers_every_matching_invoice_not_just_the_page_on_screen(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->pay($this->invoice(1000), 1000);   // 30 fully-paid invoices, 30,000 ETB total
        }

        $page = $this->props($this->actingAs($this->owner)->get('/invoices')->assertOk());

        $this->assertCount(25, $page['invoices'], 'only one page of rows is sent to the browser');
        $this->assertSame(30, $page['totals']['invoice_count'], 'but the totals describe every matching invoice');
        $this->assertEquals(30000.0, (float) $page['totals']['net']);
        $this->assertEquals(30000.0, (float) $page['totals']['collected']);
    }

    public function test_the_status_filter_matches_across_the_whole_set_not_just_the_page_on_screen(): void
    {
        for ($i = 1; $i <= 22; $i++) {
            $this->invoice(1000);   // unpaid
        }
        for ($i = 1; $i <= 3; $i++) {
            $this->pay($this->invoice(500), 500);   // paid
        }

        $unpaid = $this->props($this->actingAs($this->owner)->get('/invoices?status=unpaid')->assertOk());
        $this->assertSame(22, $unpaid['totals']['invoice_count']);
        $this->assertTrue(collect($unpaid['invoices'])->every(fn ($i) => $i['status'] === 'unpaid'));

        $paid = $this->props($this->actingAs($this->owner)->get('/invoices?status=paid')->assertOk());
        $this->assertSame(3, $paid['totals']['invoice_count']);
        $this->assertCount(3, $paid['invoices']);
    }

    public function test_searching_by_student_name_narrows_the_list_and_its_totals(): void
    {
        $other = $this->makeStudent($this->branchA, $this->sectionA, 'Findme', 'STU-FM');
        $this->invoice(1000, $other);
        for ($i = 1; $i <= 5; $i++) {
            $this->invoice(1000, $this->studentA);
        }

        $page = $this->props($this->actingAs($this->owner)->get('/invoices?q=Findme')->assertOk());

        $this->assertSame(1, $page['totals']['invoice_count']);
        $this->assertSame('Findme Tesfaye', $page['invoices'][0]['student']);
    }

    public function test_the_export_covers_the_whole_filtered_set_never_just_a_page(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->invoice(1000);
        }

        Excel::fake();
        $this->actingAs($this->owner)->get('/invoices/export');
        Excel::assertDownloaded('invoices.xlsx', function ($export) {
            // 1 school-name row + 1 title row + 1 header row + 30 invoice rows + a blank line + 4 total lines
            $this->assertCount(38, $export->array());

            return true;
        });
    }

    public function test_the_export_respects_the_same_status_filter_as_the_screen(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->invoice(1000);   // unpaid
        }
        $this->pay($this->invoice(500), 500);   // paid

        Excel::fake();
        $this->actingAs($this->owner)->get('/invoices/export?status=paid');
        Excel::assertDownloaded('invoices.xlsx', function ($export) {
            $dataRows = array_slice($export->array(), 3, -5);
            $this->assertCount(1, $dataRows);

            return true;
        });
    }

    public function test_the_number_of_queries_does_not_grow_once_the_page_is_already_full(): void
    {
        // the page itself is always capped at one page's worth of rows (25 by default), so the meaningful claim
        // isn't "fewer invoices means fewer queries" (a half-empty page is naturally cheaper) -- it's that going
        // from one full page's worth of invoices to many full pages' worth costs the same handful of queries
        for ($i = 1; $i <= 30; $i++) {
            $this->invoice(1000);
        }
        DB::enableQueryLog();
        $this->actingAs($this->owner)->get('/invoices')->assertOk();
        $fewQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        for ($i = 1; $i <= 100; $i++) {
            $this->invoice(1000);
        }
        DB::flushQueryLog();

        $this->actingAs($this->owner)->get('/invoices')->assertOk();
        $manyQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($fewQueries + 2, $manyQueries);
    }

    public function test_grade_scope_still_applies_to_the_paginated_list_and_its_totals(): void
    {
        $g2 = \App\Models\Grade::create(['school_id' => $this->school->id, 'name' => 'G2', 'sort_order' => 2]);
        $sectionG2 = \App\Models\Section::create(['branch_id' => $this->branchA->id, 'grade_id' => $g2->id, 'academic_year_id' => $this->year->id, 'name' => 'A']);
        $otherGradeStudent = $this->makeStudent($this->branchA, $sectionG2, 'Other', 'STU-OG');

        $this->invoice(1000, $this->studentA);
        $this->invoice(1000, $otherGradeStudent);

        $page = $this->props($this->actingAs($this->owner)->get("/invoices?grade_id={$this->grade->id}")->assertOk());

        $this->assertSame(1, $page['totals']['invoice_count']);
    }

    // ------------------------------------------------------------------ year / type / period filters

    public function test_the_list_defaults_to_the_current_year_and_a_past_year_can_be_chosen(): void
    {
        $pastYear = \App\Models\AcademicYear::create(['school_id' => $this->school->id, 'name' => '2025', 'start_date' => '2025-09-01', 'end_date' => '2026-06-30', 'is_current' => false]);
        $thisYear = $this->invoice(1000);
        $lastYear = Invoice::create(['student_id' => $this->studentA->id, 'academic_year_id' => $pastYear->id, 'invoice_number' => 'INV-OLD', 'frequency' => 'annual', 'period_start' => '2025-09-01']);
        $lastYear->items()->create(['description' => 'Tuition', 'amount' => 900, 'item_type' => 'fee']);

        $default = $this->props($this->actingAs($this->owner)->get('/invoices')->assertOk());
        $this->assertSame([$thisYear->id], collect($default['invoices'])->pluck('id')->all());
        $this->assertSame($this->year->id, $default['selectedAcademicYearId']);

        $old = $this->props($this->actingAs($this->owner)->get("/invoices?academic_year_id={$pastYear->id}")->assertOk());
        $this->assertSame([$lastYear->id], collect($old['invoices'])->pluck('id')->all());
    }

    public function test_the_type_filter_narrows_to_annual_termly_or_monthly(): void
    {
        $annual = $this->invoice(1000);
        $monthly = Invoice::create(['student_id' => $this->studentA->id, 'academic_year_id' => $this->year->id, 'invoice_number' => 'INV-M1', 'frequency' => 'monthly', 'period_label' => 'October', 'period_start' => '2026-10-01']);
        $monthly->items()->create(['description' => 'Tuition', 'amount' => 300, 'item_type' => 'fee']);

        $page = $this->props($this->actingAs($this->owner)->get('/invoices?frequency=monthly')->assertOk());

        $this->assertSame([$monthly->id], collect($page['invoices'])->pluck('id')->all());
        $this->assertNotContains($annual->id, collect($page['invoices'])->pluck('id')->all());
    }

    public function test_the_period_filter_picks_out_one_exact_billing_period_and_is_listed_for_the_dropdown(): void
    {
        $october = Invoice::create(['student_id' => $this->studentA->id, 'academic_year_id' => $this->year->id, 'invoice_number' => 'INV-OCT', 'frequency' => 'monthly', 'period_label' => 'October', 'period_start' => '2026-10-01']);
        $october->items()->create(['description' => 'Tuition', 'amount' => 300, 'item_type' => 'fee']);
        $november = Invoice::create(['student_id' => $this->studentA->id, 'academic_year_id' => $this->year->id, 'invoice_number' => 'INV-NOV', 'frequency' => 'monthly', 'period_label' => 'November', 'period_start' => '2026-11-01']);
        $november->items()->create(['description' => 'Tuition', 'amount' => 300, 'item_type' => 'fee']);

        $page = $this->props($this->actingAs($this->owner)->get('/invoices')->assertOk());
        $this->assertContains(['value' => 'monthly|2026-10-01', 'frequency' => 'monthly', 'label' => 'October'], $page['periods']);

        $filtered = $this->props($this->actingAs($this->owner)->get('/invoices?period='.urlencode('monthly|2026-10-01'))->assertOk());
        $this->assertSame([$october->id], collect($filtered['invoices'])->pluck('id')->all());
    }

    public function test_paid_on_shows_when_the_payment_was_verified_and_nothing_for_an_unpaid_bill(): void
    {
        $unpaid = $this->invoice(1000);
        $paid = $this->invoice(500);
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->pay($paid, 500);

        $page = $this->props($this->actingAs($this->owner)->get('/invoices')->assertOk());
        $rows = collect($page['invoices'])->keyBy('id');

        $this->assertSame('2026-10-20', $rows[$paid->id]['paid_at']);
        $this->assertNull($rows[$unpaid->id]['paid_at']);
    }

    // ------------------------------------------------------------------ when it was sent

    public function test_the_default_order_is_the_most_recently_sent_bill_first(): void
    {
        Carbon::setTestNow('2026-10-10 09:00:00');
        $older = $this->invoice(1000);
        Carbon::setTestNow('2026-10-12 09:00:00');
        $newer = $this->invoice(1000);

        $page = $this->props($this->actingAs($this->owner)->get('/invoices')->assertOk());

        $this->assertSame([$newer->id, $older->id], collect($page['invoices'])->pluck('id')->all());
    }

    public function test_the_sent_date_filter_narrows_to_bills_created_in_that_range(): void
    {
        Carbon::setTestNow('2026-10-10 09:00:00');
        $early = $this->invoice(1000);
        Carbon::setTestNow('2026-10-14 09:00:00');
        $late = $this->invoice(1000);

        $fromLate = $this->props($this->actingAs($this->owner)->get('/invoices?sent_from=2026-10-13')->assertOk());
        $this->assertSame([$late->id], collect($fromLate['invoices'])->pluck('id')->all());

        $toEarly = $this->props($this->actingAs($this->owner)->get('/invoices?sent_to=2026-10-11')->assertOk());
        $this->assertSame([$early->id], collect($toEarly['invoices'])->pluck('id')->all());

        $both = $this->props($this->actingAs($this->owner)->get('/invoices?sent_from=2026-10-09&sent_to=2026-10-15')->assertOk());
        $this->assertSame([$late->id, $early->id], collect($both['invoices'])->pluck('id')->all());
    }

    public function test_each_row_carries_the_date_and_hour_it_was_sent(): void
    {
        Carbon::setTestNow('2026-10-10 14:30:00');
        $invoice = $this->invoice(1000);

        $page = $this->props($this->actingAs($this->owner)->get('/invoices')->assertOk());

        $row = collect($page['invoices'])->firstWhere('id', $invoice->id);
        $this->assertNotEmpty($row['sent_at']);
    }
}
