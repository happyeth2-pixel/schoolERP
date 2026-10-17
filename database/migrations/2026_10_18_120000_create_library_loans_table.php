<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Academic expansion, Phase 6: one row per issue/return cycle of a copy. `borrower_type`/`borrower_id` is a
 * polymorphic reference to either a `Student` (no login account needed -- issued by a staff member on their
 * behalf, the same staff-assisted shape as the rest of this app) or a `User` (a staff borrower). No existing
 * "member" abstraction spans both in this codebase, so this is the first polymorphic relation outside
 * accounting's own unrelated `JournalEntry::source()`.
 *
 * Fines are deliberately self-contained here -- confirmed with the user at the very start of this whole
 * initiative -- and NEVER become an Invoice/Payment: `fine_amount` is only ever resolved by `fine_waived_at`
 * or `fine_paid_at`, both plain timestamps recorded by a librarian, with no path into the fee/accounting
 * system at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_copy_id')->constrained()->cascadeOnDelete();
            $table->string('borrower_type');
            $table->unsignedBigInteger('borrower_id');
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->date('issued_at');
            $table->date('due_date');
            $table->date('returned_at')->nullable();
            $table->unsignedInteger('renewed_count')->default(0);
            $table->enum('status', ['active', 'returned', 'lost', 'damaged'])->default('active');
            $table->decimal('fine_amount', 8, 2)->nullable();
            $table->timestamp('fine_waived_at')->nullable();
            $table->timestamp('fine_paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['borrower_type', 'borrower_id']);
            $table->index(['book_copy_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_loans');
    }
};
