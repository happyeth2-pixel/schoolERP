<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();   // NULL = every branch
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('body');
            $table->enum('audience', ['everyone', 'parents', 'staff'])->default('everyone');
            $table->date('publish_on');   // a school date (Addis Ababa day); visible from that day
            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'publish_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
