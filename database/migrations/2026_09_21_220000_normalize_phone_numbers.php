<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phone numbers were stored as typed (0911..., +251911..., 251911...). Rewrite
 * every number that can be understood into the one canonical +251 form so search
 * and duplicate detection work. Anything unparseable is left exactly as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([['users', 'phone'], ['schools', 'phone'], ['students', 'emergency_contact']] as [$table, $column]) {
            DB::table($table)->whereNotNull($column)->where($column, '!=', '')->orderBy('id')->each(function ($row) use ($table, $column) {
                $normalized = PhoneNumber::normalize($row->{$column});
                if ($normalized !== null && $normalized !== $row->{$column}) {
                    DB::table($table)->where('id', $row->id)->update([$column => $normalized]);
                }
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['phone']);
        });
    }
};
