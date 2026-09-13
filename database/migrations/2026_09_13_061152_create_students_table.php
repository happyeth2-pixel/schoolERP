<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('student_id_number')->unique();

            // Ethiopian patronymic naming: given name, father's name,
            // grandfather's name -- not first/middle/last.
            $table->string('first_name');
            $table->string('father_name');
            $table->string('grandfather_name')->nullable();

            $table->enum('gender', ['male', 'female']);
            $table->date('date_of_birth')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('address')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->string('previous_school')->nullable();
            $table->date('enrollment_date');
            $table->enum('status', [
                'active', 'transferred', 'graduated', 'withdrawn', 'suspended', 'expelled', 'inactive',
            ])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
