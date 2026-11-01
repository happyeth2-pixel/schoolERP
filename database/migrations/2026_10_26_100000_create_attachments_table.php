<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One shared attachment table for every feature that lets someone attach a photo or GIF -- parent messages,
 * announcements, complaints -- rather than three near-identical ones. Images only (see SafeUpload::
 * storePublicAttachment(): jpg/png are re-encoded, a GIF is left untouched so its animation survives); never
 * video, never a raw/unknown file type, never anything that could be mistaken for a link or an app to run.
 * Public disk, same as any other photo this app already shows (avatars, student photos) -- nothing here is
 * more sensitive than the message/announcement/complaint it belongs to, whose own access rules already apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('path');
            $table->string('mime', 20);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
