<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per book a student has actually been sold -- the record that answers "which of this grade's
 * books does this student still need?" so staff can sell only the ones missing instead of the whole set
 * again. `unique(student_id, book_item_id)` is the server-side guarantee that the same book is never sold
 * twice to the same student (the picker in BookSales also excludes already-owned books, but the database
 * is the real authority, same as everywhere else in this app). `price_at_sale` freezes what was actually
 * charged, same reasoning as InvoiceItem's own snapshot fields -- changing a book's price later never
 * rewrites history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_book_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_item_id')->constrained()->cascadeOnDelete();
            $table->date('purchased_on');
            $table->decimal('price_at_sale', 10, 2);
            $table->timestamps();

            $table->unique(['student_id', 'book_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_book_purchases');
    }
};
