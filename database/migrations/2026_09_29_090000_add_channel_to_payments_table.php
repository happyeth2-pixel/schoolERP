<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a payment came from. 'portal' = a parent sent proof through the parent portal (pending until an
     * accountant verifies it, as before); 'counter' = staff received the money at the school and recorded it
     * (born verified, with a receipt). `paid_by_name` is who handed the money over: a parent needs no account.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('channel', 20)->default('portal')->after('status');
            $table->string('paid_by_name')->nullable()->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['channel', 'paid_by_name']);
        });
    }
};
