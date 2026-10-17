<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A per-student price for one service; NULL means "use the service's own amount". */
    public function up(): void
    {
        Schema::table('student_optional_service', function (Blueprint $table) {
            $table->decimal('custom_amount', 10, 2)->nullable()->after('enrolled_on');
        });
    }

    public function down(): void
    {
        Schema::table('student_optional_service', function (Blueprint $table) {
            $table->dropColumn('custom_amount');
        });
    }
};
