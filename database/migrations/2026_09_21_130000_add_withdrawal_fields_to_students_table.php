<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->date('withdrawn_on')->nullable()->after('status');
            $table->string('withdrawal_reason', 500)->nullable()->after('withdrawn_on');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['withdrawn_on', 'withdrawal_reason']);
        });
    }
};
