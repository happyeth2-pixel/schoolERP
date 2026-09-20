<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optional_services', function (Blueprint $table) {
            $table->enum('frequency', ['annual', 'termly', 'monthly'])->default('annual')->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('optional_services', function (Blueprint $table) {
            $table->dropColumn('frequency');
        });
    }
};
