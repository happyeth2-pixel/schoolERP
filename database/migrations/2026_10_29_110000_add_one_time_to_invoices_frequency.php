<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fourth billing frequency for a charge that is never recurring -- a one-off book sale today, and
 * anything else "charge once, right now" in future. Deliberately NOT reusing 'annual'/'termly'/'monthly':
 * those are the real dedup key invoice-generation already relies on (student, year, frequency, period_start
 * must be unique), and a one-time sale's period_start is "today", which could otherwise coincide with a
 * real annual/termly/monthly period's own start date for the same student and collide. See BookSales for
 * the one place that creates a 'one_time' invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->enum('frequency', ['annual', 'termly', 'monthly', 'one_time'])->default('annual')->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->enum('frequency', ['annual', 'termly', 'monthly'])->default('annual')->change();
        });
    }
};
