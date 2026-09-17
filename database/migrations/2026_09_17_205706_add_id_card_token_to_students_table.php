<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // Random, unguessable -- encoded in the ID card's QR code instead
            // of the human-readable student_id_number so nobody can enumerate
            // other students by trying nearby ID numbers. Rotated whenever a
            // replacement card is issued, so a lost/old card stops verifying.
            $table->string('id_card_token', 64)->nullable()->unique()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('id_card_token');
        });
    }
};
