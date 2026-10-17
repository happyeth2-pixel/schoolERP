<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fee items, optional services, payment accounts and grade bands are referenced
 * by invoices and payments long after they stop being offered. "Removing" one
 * now archives it (deleted_at) instead of erasing the row, so that history keeps
 * pointing at something real.
 */
return new class extends Migration
{
    private const TABLES = ['fee_structures', 'optional_services', 'payment_accounts', 'grade_bands'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->softDeletes());
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropSoftDeletes());
        }
    }
};
