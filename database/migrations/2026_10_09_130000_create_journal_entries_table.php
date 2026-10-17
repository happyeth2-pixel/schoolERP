<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The header row of a double-entry posting. `source_module` + `source_type`/`source_id` trace an
 * auto-generated entry back to the exact event that caused it (an Invoice, a Payment, a PurchaseOrder...)
 * without a growing list of nullable FK columns -- one polymorphic pair covers every source, present and
 * future. A draft entry may be freely edited; once `status` flips to `posted` (see App\Services\Ledger)
 * the entry is immutable -- the same append-only philosophy as StockMovement/AssetEvent -- and a mistake
 * is corrected with `reverse()`, a brand new entry with debits and credits swapped, never an edit. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('journal_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
            $table->string('entry_number')->unique();
            $table->date('entry_date');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->string('source_module')->nullable(); // fee_billing, payments, procurement, manual
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('status')->default('draft'); // draft, posted, reversed
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reverses_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversed_by_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
            $table->index(['school_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
