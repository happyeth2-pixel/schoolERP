<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

/**
 * 1. New permissions (refund-payments, approve-large-discounts, override-section-capacity),
 *    granted to the accounts that should have them today (permissions live on the user).
 * 2. A database-level guarantee of ONE current academic year per school:
 *    current_school_id equals school_id for the current year and is NULL otherwise, and it
 *    is unique -- a second "current" year in the same school cannot be stored at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['refund-payments' => ['owner'], 'approve-large-discounts' => ['owner', 'branch_admin'], 'override-section-capacity' => ['owner', 'branch_admin']] as $name => $roles) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);

            User::whereHas('roles', fn ($q) => $q->whereIn('name', $roles))->each(fn (User $u) => $u->givePermissionTo($name));
        }

        Schema::table('academic_years', function (Blueprint $table) {
            $table->unsignedBigInteger('current_school_id')->nullable()->after('is_current');
        });

        // Backfill. If a school somehow has several "current" years, keep the most recent one.
        $schools = DB::table('academic_years')->where('is_current', true)->distinct()->pluck('school_id');
        foreach ($schools as $schoolId) {
            $keep = DB::table('academic_years')->where('school_id', $schoolId)->where('is_current', true)->orderByDesc('start_date')->value('id');
            DB::table('academic_years')->where('school_id', $schoolId)->where('id', '!=', $keep)->update(['is_current' => false]);
            DB::table('academic_years')->where('id', $keep)->update(['current_school_id' => $schoolId]);
        }

        Schema::table('academic_years', function (Blueprint $table) {
            $table->unique('current_school_id', 'one_current_year_per_school');
        });
    }

    public function down(): void
    {
        Schema::table('academic_years', function (Blueprint $table) {
            $table->dropUnique('one_current_year_per_school');
            $table->dropColumn('current_school_id');
        });
    }
};
