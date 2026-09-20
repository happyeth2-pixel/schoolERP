<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // The base table's unique(student_id, academic_year_id) assumed
            // exactly one invoice per student per year -- billing frequency
            // breaks that assumption, so it's replaced below with a
            // per-billing-period key instead. MySQL won't drop it directly
            // since it's currently the only index backing the student_id
            // foreign key -- add plain single-column indexes first so that
            // constraint still has one to lean on afterward.
            $table->index('student_id', 'invoices_student_id_index');
            $table->index('academic_year_id', 'invoices_academic_year_id_index');
            $table->dropUnique(['student_id', 'academic_year_id']);

            // Which real Term this invoice is for, when it's a "termly"
            // charge -- null for an annual invoice or a monthly one
            // (months aren't a stored model in this schema).
            $table->foreignId('term_id')->nullable()->after('academic_year_id')->constrained()->nullOnDelete();
            // What a person actually sees on screen: "Term 2",
            // "October 2026", or null for a plain whole-year invoice.
            $table->string('period_label')->nullable()->after('term_id');
            // A term's start date can legitimately fall on the exact
            // same calendar day as the academic year's own start date
            // (very common -- Term 1 usually starts when the year
            // does), and that date is also the first "month" of the
            // year for monthly billing. So period_start ALONE can't
            // tell an annual invoice apart from a termly or monthly
            // one that happens to start the same day -- this
            // discriminator is what actually does that.
            $table->enum('frequency', ['annual', 'termly', 'monthly'])->default('annual')->after('period_label');
            // The idempotency key for "has this period already been
            // billed for this student" -- always populated (academic
            // year start / term start / first-of-month) alongside
            // frequency above, so generation never duplicates.
            $table->date('period_start')->nullable()->after('frequency');

            $table->unique(['student_id', 'academic_year_id', 'frequency', 'period_start'], 'invoices_student_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_student_period_unique');
            $table->dropConstrainedForeignId('term_id');
            $table->dropColumn(['period_label', 'frequency', 'period_start']);
            $table->unique(['student_id', 'academic_year_id']);
            $table->dropIndex('invoices_student_id_index');
            $table->dropIndex('invoices_academic_year_id_index');
        });
    }
};
