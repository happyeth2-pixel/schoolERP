<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A parent account is deliberately never branch-scoped (branch_id is always null -- see
 * ParentController::store(); a family's children could, in principle, be in different branches), so every
 * complaint a parent ever filed was crashing with a NOT NULL violation the moment the branch couldn't be
 * derived from `user->branch_id` directly. The fix (ComplaintController::store()) now derives the branch from
 * the student the complaint names, or failing that from the parent's own linked children -- but a complaint
 * about no particular student, filed by a parent with no children linked yet, genuinely has no branch to give
 * it. `recipientsFor()` already routes a null-branch complaint to the school's own org-wide staff (the owner),
 * so this is a safe, already-handled fallback, not a new gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable(false)->change();
        });
    }
};
