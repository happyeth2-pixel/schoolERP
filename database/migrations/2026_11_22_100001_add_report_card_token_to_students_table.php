<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second public, no-login token -- deliberately separate from `id_card_token` (same shape, same
 * `Student::newReportCardToken()` generation pattern) so that an ID card's QR code and a report-card
 * lookup code are two independent secrets: printing/handing out one never discloses the other, and either
 * can be rotated without touching the other. Only ever shown to the family on a printed class code/QR
 * sheet (see Students/ClassCodes); never exposed in any authenticated screen's URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('report_card_token')->nullable()->unique()->after('id_card_token');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('report_card_token');
        });
    }
};
