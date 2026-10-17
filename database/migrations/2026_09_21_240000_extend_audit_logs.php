<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail has to stay readable after the record it describes changes or
 * is archived, so each entry carries a human label for the record, where the
 * request came from, and a reason that is not limited to 255 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('subject_label')->nullable()->after('auditable_id');
            $table->string('ip_address', 45)->nullable()->after('note');
            $table->text('note')->nullable()->change();
            $table->index(['school_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'created_at']);
            $table->dropIndex(['action']);
            $table->dropColumn(['subject_label', 'ip_address']);
        });
    }
};
