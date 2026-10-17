<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security-relevant sign-in events: failures, cool-downs, locks, a successful sign-in after failures, password
 * resets, accounts switched off and on. Deliberately separate from the audit log: most of these happen to people who
 * are not signed in (or to numbers that belong to no account). Nothing here ever holds a password; the identifier is
 * stored masked (+2519*****344).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 60)->index();
            $table->string('identifier', 120)->nullable();
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('user_agent', 200)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
