<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\BookItem;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\StudentBookPurchase;
use App\Services\BookSales;
use App\Support\ChartOfAccountsTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Books are sold per student, à la carte (never the whole grade's set at once if some are already
 * owned), and bill immediately onto a one-off invoice -- never waiting for the regular termly/annual
 * generation run. See app/Services/BookSales.php for the design reasoning.
 */
class BookSalesTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function book(array $over = []): BookItem
    {
        return BookItem::create($over + [
            'branch_id' => $this->branchA->id,
            'grade_id' => $this->grade->id,
            'academic_year_id' => $this->year->id,
            'title' => 'Mathematics',
            'price' => 150,
        ]);
    }

    // ================================================================== catalog management

    public function test_catalog_management_needs_manage_fee_structures(): void
    {
        $registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $this->actingAs($registrar)->get('/books')->assertForbidden();
        $this->actingAs($registrar)->post('/books', ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'title' => 'X', 'price' => 10])->assertForbidden();
    }

    public function test_a_book_can_be_added_changed_and_removed(): void
    {
        $this->actingAs($this->owner)->post('/books', [
            'branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id,
            'title' => 'English Grade 1', 'size' => 'A4', 'price' => 220,
        ])->assertSessionHasNoErrors();

        $book = BookItem::where('title', 'English Grade 1')->firstOrFail();
        $this->assertSame('A4', $book->size);
        $this->assertEquals(220, $book->price);

        $this->actingAs($this->owner)->patch("/books/{$book->id}", ['title' => 'English Grade 1', 'size' => 'A4', 'price' => 250])->assertSessionHasNoErrors();
        $this->assertEquals(250, $book->fresh()->price);

        $this->actingAs($this->owner)->delete("/books/{$book->id}")->assertSessionHasNoErrors();
        $this->assertNotNull($book->fresh()->deleted_at);
    }

    public function test_a_different_grades_books_have_their_own_prices(): void
    {
        $grade2 = Grade::create(['school_id' => $this->school->id, 'name' => 'G2', 'sort_order' => 2]);
        $this->book(['title' => 'Grade 1 Reader', 'price' => 100]);
        $this->book(['grade_id' => $grade2->id, 'title' => 'Grade 2 Reader', 'price' => 180]);

        $this->assertEquals(100, BookItem::where('title', 'Grade 1 Reader')->value('price'));
        $this->assertEquals(180, BookItem::where('title', 'Grade 2 Reader')->value('price'));
    }

    public function test_an_admin_outside_the_branch_cannot_manage_another_branchs_books(): void
    {
        $adminB = $this->makeUser('branch_admin', $this->branchB->id, ['manage-fee-structures', 'manage-invoices']);
        $book = $this->book();

        $this->actingAs($adminB)->patch("/books/{$book->id}", ['title' => 'Hacked', 'price' => 1])->assertForbidden();
        $this->actingAs($adminB)->post('/books', ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'title' => 'X', 'price' => 10])->assertForbidden();
    }

    // ================================================================== selling to a student

    public function test_selling_needs_manage_invoices(): void
    {
        $book = $this->book();
        $registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $this->actingAs($registrar)->get("/students/{$this->studentA->id}/books")->assertForbidden();
        $this->actingAs($registrar)->post("/students/{$this->studentA->id}/books", ['book_item_ids' => [$book->id]])->assertForbidden();
    }

    public function test_selling_a_book_creates_a_one_time_invoice_with_the_right_line(): void
    {
        Notification::fake();
        $book = $this->book(['title' => 'Science Grade 1', 'price' => 175]);

        $this->actingAs($this->owner)->post("/students/{$this->studentA->id}/books", ['book_item_ids' => [$book->id]])->assertSessionHasNoErrors();

        $invoice = Invoice::where('student_id', $this->studentA->id)->where('frequency', 'one_time')->firstOrFail();
        $this->assertSame('Books', $invoice->period_label);
        $this->assertSame(1, $invoice->items()->count());
        $item = $invoice->items()->first();
        $this->assertSame('book', $item->item_type);
        $this->assertSame($book->id, $item->book_item_id);
        $this->assertSame('Science Grade 1', $item->description);
        $this->assertEquals(175, $item->amount);
        $this->assertSame(175.0, $invoice->totalAmount());

        $purchase = StudentBookPurchase::where('student_id', $this->studentA->id)->where('book_item_id', $book->id)->firstOrFail();
        $this->assertEquals(175, $purchase->price_at_sale);
        $this->assertSame($item->id, $purchase->invoice_item_id);
    }

    public function test_the_same_book_cannot_be_sold_to_the_same_student_twice(): void
    {
        $book = $this->book();
        BookSales::sell($this->owner, $this->studentA, collect([$book]));

        $this->actingAs($this->owner)->post("/students/{$this->studentA->id}/books", ['book_item_ids' => [$book->id]])
            ->assertSessionHasErrors('book_item_ids');
        $this->assertSame(1, StudentBookPurchase::where('student_id', $this->studentA->id)->count());
    }

    public function test_the_database_itself_refuses_a_duplicate_purchase(): void
    {
        $book = $this->book();
        StudentBookPurchase::create(['student_id' => $this->studentA->id, 'book_item_id' => $book->id, 'invoice_item_id' => $this->makeDummyInvoiceItem(), 'purchased_on' => '2026-10-29', 'price_at_sale' => 150]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        StudentBookPurchase::create(['student_id' => $this->studentA->id, 'book_item_id' => $book->id, 'invoice_item_id' => $this->makeDummyInvoiceItem(), 'purchased_on' => '2026-10-29', 'price_at_sale' => 150]);
    }

    public function test_a_second_book_sold_the_same_day_lands_on_the_same_invoice(): void
    {
        $bookA = $this->book(['title' => 'Book A', 'price' => 100]);
        $bookB = $this->book(['title' => 'Book B', 'price' => 50]);

        BookSales::sell($this->owner, $this->studentA, collect([$bookA]));
        $result = BookSales::sell($this->owner, $this->studentA, collect([$bookB]));

        $this->assertSame(1, Invoice::where('student_id', $this->studentA->id)->where('frequency', 'one_time')->count());
        $this->assertSame(2, $result['invoice']->items()->count());
        $this->assertSame(150.0, $result['invoice']->totalAmount());
    }

    public function test_only_a_book_already_bought_is_excluded_from_the_picker_the_rest_still_sell(): void
    {
        $owned = $this->book(['title' => 'Already Have', 'price' => 90]);
        $needed = $this->book(['title' => 'Still Needed', 'price' => 60]);
        BookSales::sell($this->owner, $this->studentA, collect([$owned]));

        $page = $this->props($this->actingAs($this->owner)->get("/students/{$this->studentA->id}/books"));
        $books = collect($page['books']);
        $this->assertTrue($books->firstWhere('title', 'Already Have')['already_owned']);
        $this->assertFalse($books->firstWhere('title', 'Still Needed')['already_owned']);

        $this->actingAs($this->owner)->post("/students/{$this->studentA->id}/books", ['book_item_ids' => [$needed->id]])->assertSessionHasNoErrors();
        $this->assertSame(2, StudentBookPurchase::where('student_id', $this->studentA->id)->count());
    }

    public function test_an_admin_outside_the_branch_cannot_sell_to_this_students(): void
    {
        $adminB = $this->makeUser('branch_admin', $this->branchB->id, ['manage-invoices']);
        $book = $this->book();

        $this->actingAs($adminB)->post("/students/{$this->studentA->id}/books", ['book_item_ids' => [$book->id]])->assertForbidden();
    }

    public function test_selling_a_book_is_audited(): void
    {
        $book = $this->book(['title' => 'Audited Book']);

        BookSales::sell($this->owner, $this->studentA, collect([$book]));

        $this->assertTrue(AuditLog::where('action', 'student.books_sold')->exists());
    }

    public function test_selling_a_book_posts_to_the_ledger_when_the_school_has_adopted_accounting(): void
    {
        ChartOfAccountsTemplate::install($this->school);
        AccountingPeriod::create(['school_id' => $this->school->id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $book = $this->book(['price' => 300]);

        $result = BookSales::sell($this->owner, $this->studentA, collect([$book]));

        $entry = $result['invoice']->journalEntries()->first();
        $this->assertNotNull($entry, 'a book sale with accounting adopted should post a journal entry');
        $this->assertEquals(300, $entry->lines()->sum('debit'));
    }

    private function makeDummyInvoiceItem(): int
    {
        $invoice = Invoice::create([
            'student_id' => $this->studentA->id, 'academic_year_id' => $this->year->id,
            'frequency' => 'one_time', 'period_start' => '2026-10-29', 'period_label' => 'Books', 'invoice_number' => 'INV-TEST-'.uniqid(),
        ]);

        return $invoice->items()->create(['item_type' => 'book', 'description' => 'x', 'quantity' => 1, 'unit_amount' => 1, 'amount' => 1])->id;
    }
}
