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
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('logo_path')->nullable();
            $table->string('region')->nullable();
            $table->string('city')->nullable();
            $table->string('subcity')->nullable();
            $table->string('woreda')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->enum('calendar_preference', ['ethiopian', 'gregorian', 'both'])->default('both');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
