<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->unsignedInteger('library_loan_days')->default(14)->after('allow_negative_stock');
            // NULL = fines disabled entirely -- the brief's own "not part of tuition unless explicitly
            // configured" default is OFF, not a zero rate that still implies a fine of 0.00 exists.
            $table->decimal('library_fine_per_day', 6, 2)->nullable()->after('library_loan_days');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['library_loan_days', 'library_fine_per_day']);
        });
    }
};
