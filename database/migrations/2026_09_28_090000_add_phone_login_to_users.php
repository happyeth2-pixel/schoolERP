<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phone number becomes the sign-in identifier.
 *
 *  - login_phone: the canonical Ethiopian mobile number (+2519XXXXXXXX / +2517XXXXXXXX) an account signs in with.
 *    Unique. It is separate from `phone` (a contact number) on purpose: two parents may share one contact number,
 *    but two accounts can never share a sign-in number, and nothing already stored is thrown away.
 *  - email becomes optional (it stays unique when present).
 *  - must_change_password: set when an administrator issues a temporary password.
 *
 * Existing accounts get a login_phone only when their stored phone is a valid mobile number that no other account
 * has; anyone else keeps signing in with their email until an administrator gives them a phone number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('login_phone', 20)->nullable()->unique()->after('phone');
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
        });

        $byNumber = [];
        foreach (DB::table('users')->whereNotNull('phone')->get(['id', 'phone']) as $row) {
            $canonical = PhoneNumber::normalizeMobile($row->phone);
            if ($canonical !== null) {
                $byNumber[$canonical][] = $row->id;
            }
        }

        foreach ($byNumber as $canonical => $ids) {
            if (count($ids) === 1) {
                DB::table('users')->where('id', $ids[0])->update(['login_phone' => $canonical]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['login_phone']);
            $table->dropColumn(['login_phone', 'must_change_password', 'password_changed_at']);
        });
    }
};
