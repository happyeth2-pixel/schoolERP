<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_optional_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('optional_service_id')->constrained()->cascadeOnDelete();
            $table->date('enrolled_on');
            $table->timestamps();
            $table->unique(['student_id', 'optional_service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_optional_service');
    }
};
