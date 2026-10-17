<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** When its audience was told about this announcement (immediately if published today, or by the daily
     * announcements:notify-due job on the morning a SCHEDULED announcement's publish date arrives). Null = not yet. */
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('publish_on');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('notified_at');
        });
    }
};
