<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plain free-text "address" was too vague to be useful on an ID card or for a home visit -- kebele and
 * city are the two pieces an Ethiopian address actually needs. `address` itself stays (existing data, and
 * room for a house number/landmark); kebele/city are the new, more specific fields the registration form
 * and the ID card now ask for and show.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('kebele')->nullable()->after('address');
            $table->string('city')->nullable()->after('kebele');
        });
    }

    public function down(): void
    {
        Schema::table('students', fn (Blueprint $t) => $t->dropColumn(['kebele', 'city']));
    }
};
