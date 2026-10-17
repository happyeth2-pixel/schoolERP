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
}
