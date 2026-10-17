<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which of the app's languages a school has actually turned on --
     * an Owner picks a subset (or all of them), and only those show up
     * as choices for that school's own staff and families. Defaults to
     * English + Amharic, the two the school can trust are accurate;
     * Afaan Oromo and Tigrinya exist but are opt-in until reviewed.
     */
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->json('enabled_languages')->default(json_encode(['en', 'am']))->after('mobile_money_number');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('enabled_languages');
        });
    }
};
