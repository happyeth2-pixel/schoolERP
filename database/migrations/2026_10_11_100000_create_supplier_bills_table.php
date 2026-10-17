<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The vendor invoice itself -- the missing piece Procurement deliberately deferred ("prepare integration
 * points for a future Phase 3 Accounting... do not implement a fake accounting system here"). Exists fully
 * formed the moment it's created, same as `Invoice` -- no draft state, no stored status or total (see
 * SupplierBill::total()/balance(), summed live from items and payments, same never-cache-a-total rule as
 * every other document in this app). `purchase_order_id` is nullable: a bill can exist without ever having
 * gone through Procurement's own PO flow (a small, sole-source purchase), but when it does, each line ties
 * back to the specific ordered/received line for the three-way check (request vs. order vs. receipt vs. bill). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('bill_number')->unique();
            $table->string('supplier_reference')->nullable();
            $table->date('bill_date');
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_bills');
    }
};
