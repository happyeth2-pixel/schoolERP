<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** One row per editable text block on the public website (see App\Support\SiteContentSchema for the
     * fixed list of (page, field_key) pairs and their fallback copy). A missing row just means "use the
     * fallback" -- nothing here is ever required to exist. */
    public function up(): void
    {
        Schema::create('site_contents', function (Blueprint $table) {
            $table->id();
            $table->string('page');
            $table->string('field_key');
            $table->text('value');
            $table->timestamps();
            $table->unique(['page', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_contents');
    }
};
