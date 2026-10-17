<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A branch may have its own bank or Telebirr account. branch_id NULL keeps
 * meaning "the whole school", so every existing account carries on as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_accounts', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('school_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
