<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A short note the office keeps with a payment ("paid by cheque, cleared on ..."), required when the method is "other". */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('note', 500)->nullable()->after('paid_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
