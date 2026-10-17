<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One side of one account's movement within an entry. Exactly one of debit/credit is ever nonzero on a
 * given line -- enforced in App\Services\Ledger, not here, since cross-column checks vary too much across
 * the DB drivers this app supports to lean on a CHECK constraint. Nothing here is ever cached: the ledger,
 * trial balance and every report are queries over these rows (joined to posted journal_entries), the same
 * "never cache a derived total" rule every other financial figure in this app already follows. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->string('description')->nullable();
            $table->timestamps();
            $table->index(['account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
    }
};
