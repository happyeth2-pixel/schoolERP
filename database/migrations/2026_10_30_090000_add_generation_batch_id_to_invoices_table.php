<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tags every invoice created by one "Create bills" run with the id of that run, so a registrar who made a
 * mistake (wrong grade, wrong amount, too early) can cancel the WHOLE batch in one tap right after creating
 * it -- see InvoiceController::cancelBatch() -- instead of opening each bill one at a time. Invoices are
 * never hard-deleted (NeverHardDeleted), so "cancel" there means the existing waive mechanism applied in
 * bulk, blocked per-invoice the moment a real payment exists on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->uuid('generation_batch_id')->nullable()->after('invoice_number')->index();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('generation_batch_id'));
    }
};
