<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Academic expansion, Phase 6: the Library. A `Book` is the school-wide catalog TITLE (shared across every
 * branch, the same split `Period` already uses for a school-wide bell schedule) -- the actual physical,
 * trackable units are `book_copies` (branch-scoped, one row per physical item), created next.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('author')->nullable();
            $table->string('isbn')->nullable();
            $table->string('category')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'title']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
