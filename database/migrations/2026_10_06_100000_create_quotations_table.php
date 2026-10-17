<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A supplier's response to an APPROVED purchase request -- several suppliers may each quote the same
 * request, for comparison; `selected` marks the one a human chose (never automatic -- the brief is explicit
 * about this). Feeds a later Procurement phase's Purchase Order. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('reference')->nullable();
            $table->date('quotation_date');
            $table->date('validity_date')->nullable();
            $table->string('delivery_info')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('selected')->default(false);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
