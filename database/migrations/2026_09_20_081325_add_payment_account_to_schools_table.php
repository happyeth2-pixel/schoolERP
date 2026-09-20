<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a parent should actually send money. Payments in this
     * system are always "pay outside, then submit proof for
     * verification" -- there is no payment gateway -- so a parent
     * needs to be told which account to send to before they can
     * screenshot proof of having done it.
     */
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('bank_name')->nullable()->after('calendar_preference');
            $table->string('bank_account_number')->nullable()->after('bank_name');
            $table->string('bank_account_name')->nullable()->after('bank_account_number');
            $table->string('mobile_money_number')->nullable()->after('bank_account_name');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['bank_name', 'bank_account_number', 'bank_account_name', 'mobile_money_number']);
        });
    }
};
