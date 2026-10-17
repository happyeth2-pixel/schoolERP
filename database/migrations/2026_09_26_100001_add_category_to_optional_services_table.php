<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** transport | asp | other -- how the service is grouped and (for transport) limited to one per student. */
    public function up(): void
    {
        Schema::table('optional_services', function (Blueprint $table) {
            $table->string('category', 20)->default('other')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('optional_services', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
