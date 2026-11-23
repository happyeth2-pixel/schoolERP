<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per "Create Bills" run, successful or not -- the Billing Batches dashboard
 * (BillingBatchController) reads this, never the invoices table directly, because a run that created
 * zero invoices (blocked on no fees/no terms/an unexpected error) leaves no invoice behind to find, but
 * is exactly the kind of run a registrar most needs to see and understand. `generation_batch_id` links a
 * completed run to the invoices it actually created (Invoice::generation_batch_id, already in place) so
 * "cancel this batch" keeps working unchanged; it stays null for a run that created nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('generation_batch_id')->nullable()->index();
            // 'grade' | 'grades' | 'students' | 'all' -- what was targeted, see InvoiceController's wizard.
            $table->string('scope_type');
            $table->string('scope_label');
            $table->json('periods')->nullable();
            $table->date('due_date_override')->nullable();
            // 'completed' (created >0 invoices), 'no_invoices' (ran fine, nothing new to bill),
            // 'blocked' (a known, explained reason stopped it -- no fees, no terms, no students),
            // 'failed' (an unexpected error).
            $table->string('status');
            $table->unsignedInteger('students_considered')->default(0);
            $table->unsignedInteger('students_billed')->default(0);
            $table->unsignedInteger('invoices_created')->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->text('message')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_batches');
    }
};
