<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_structures', function (Blueprint $table) {
            // How often this amount is charged. "amount" is always the
            // PER-PERIOD charge -- a school billing 12,000/year over 3
            // terms enters 4,000 with frequency "termly", never 12,000
            // with the system dividing it, so the number on screen
            // always matches what's actually charged.
            $table->enum('frequency', ['annual', 'termly', 'monthly'])->default('annual')->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('fee_structures', function (Blueprint $table) {
            $table->dropColumn('frequency');
        });
    }
};
