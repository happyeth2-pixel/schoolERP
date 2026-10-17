<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One billed line. `purchase_order_item_id` is nullable (a bill not tied to a PO has none), but when set,
 * the line's quantity is checked against what was actually RECEIVED for that line (never what was merely
 * ordered) -- the heart of the three-way match: a school can never be billed for more than physically
 * arrived. `expense_account_id` is nullable, falling back to the school's own `default_expense_account_id`
 * (see App\Services\AccountingPostings) -- expensed on purchase, never perpetual inventory valuation (see
 * the Accounting Phase A design notes for why). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_bill_items');
    }
};
