<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `inventory_items.sku`/`.barcode` were only ever validated unique at the application layer
 * (`Rule::unique(...)->where('school_id', ...)->whereNull('deleted_at')`) -- never at the database layer, so
 * a direct insert (a buggy import script, a future API, a race between two simultaneous requests neither of
 * which saw the other's row yet) could silently create two active items sharing a sku/barcode within the
 * same school, with nothing ever catching it. This closes that gap for real.
 *
 * Scope matches the existing app-level rule exactly: `inventory_items` has no branch_id at all (it is
 * school-wide, like FeeStructure -- see the table's own migration comment), so the constraint is
 * (school_id, sku) / (school_id, barcode), never narrowed to a branch.
 *
 * The "ignore soft-deleted rows" half of the old app-level rule can't be expressed as a portable DB
 * constraint (MySQL has no partial/filtered unique index; this project's test suite also runs on SQLite).
 * Instead, `InventoryItem::booted()`'s `deleting` listener now rewrites sku/barcode to an
 * `{original}#archived-{id}` value at the moment an item is archived, which frees the clean value for reuse
 * by a new item while keeping the original value readable in an archived record's history -- so this plain,
 * portable unique index is enough on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Defensive, not merely optimistic: if real data already has two ACTIVE rows sharing a sku or
        // barcode within a school, `Schema::table(...)->unique(...)` below would fail with a raw, unhelpful
        // "duplicate entry" SQL error naming no specific rows. Finding and naming the conflict first, before
        // attempting the index, turns that into an actionable message an operator can actually act on. This
        // was verified against this machine's own dev database where reachable; it could not be checked
        // against a real production dataset from here -- run this migration's conflict check manually against
        // a production snapshot before deploying it, if that hasn't already been done.
        // Grouped and compared in PHP, not SQL (no GROUP_CONCAT/STRING_AGG), so this runs identically on
        // every driver this project supports (MySQL, SQLite in tests, and Postgres -- see the disaster-
        // recovery drill, which exercised a real Postgres instance despite §2's "MySQL in production" note;
        // that inconsistency is worth resolving separately, but this check can't afford to guess which one
        // is true and break on the other).
        foreach (['sku', 'barcode'] as $column) {
            $rows = DB::table('inventory_items')->select('id', 'school_id', $column)
                ->whereNull('deleted_at')->whereNotNull($column)->get();

            $conflicts = $rows->groupBy(fn ($r) => $r->school_id.'|'.$r->{$column})
                ->filter(fn ($g) => $g->count() > 1);

            if ($conflicts->isNotEmpty()) {
                $details = $conflicts->map(function ($g) use ($column) {
                    $first = $g->first();
                    return "school {$first->school_id}, {$column} \"{$first->{$column}}\": items #".$g->pluck('id')->implode(', #');
                })->implode('; ');
                throw new \RuntimeException(
                    "Cannot add a unique constraint on inventory_items.{$column}: active rows already collide ({$details}). ".
                    'Resolve these (rename one side\'s value, or archive the stale one) before re-running this migration.'
                );
            }
        }

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->unique(['school_id', 'sku'], 'inventory_items_school_sku_unique');
            $table->unique(['school_id', 'barcode'], 'inventory_items_school_barcode_unique');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropUnique('inventory_items_school_sku_unique');
            $table->dropUnique('inventory_items_school_barcode_unique');
        });
    }
};
