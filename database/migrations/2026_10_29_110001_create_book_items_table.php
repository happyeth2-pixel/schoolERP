<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A textbook (or workbook, stationery set, ...) the school sells, scoped like FeeStructure (branch, grade,
 * academic year) since different grades genuinely need different books at different prices -- Grade 1's
 * books are not Grade 8's. Unlike FeeStructure there is no `frequency`: a book is bought once, not billed
 * every term. `revenue_account_id` is optional (falls back to the school's default revenue account, same
 * as an unmapped fee/service already does in AccountingPostings).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('size')->nullable();
            $table->decimal('price', 10, 2);
            $table->foreignId('revenue_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['branch_id', 'academic_year_id', 'grade_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_items');
    }
};
