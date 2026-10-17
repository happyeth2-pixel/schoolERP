<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_attendance_id')->constrained()->restrictOnDelete();
            $table->string('new_status');
            $table->time('new_check_in')->nullable();
            $table->time('new_check_out')->nullable();
            $table->unsignedSmallInteger('new_overtime_minutes')->nullable();
            $table->text('reason');
            $table->string('status')->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_attendance_corrections');
    }
};
