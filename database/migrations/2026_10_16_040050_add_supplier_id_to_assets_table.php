<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `assets.supplier` has been a disconnected free-text string since Inventory Phase C -- never linked to the
 * `suppliers` table Procurement later introduced, even when the exact same supplier genuinely exists there.
 * This adds an OPTIONAL `supplier_id` alongside it, rather than replacing it:
 *   - existing assets keep their free-text `supplier` value untouched by this migration -- nothing is
 *     guessed or auto-matched against `suppliers` by name, since a name match can be wrong (two different
 *     real-world suppliers sharing a display name, a typo that happens to coincide, etc.) and this is
 *     exactly the kind of "do not break existing data" migration that must not invent facts;
 *   - a brand-new asset can now be linked to a real Supplier record (AssetController::store() snapshots that
 *     supplier's name into the `supplier` text column too, the same frozen-at-creation pattern
 *     `invoice_items` already uses, so the free-text column stays a reliable display value even if the
 *     linked supplier is later renamed or archived);
 *   - `nullOnDelete()`, not `cascadeOnDelete()` or `restrictOnDelete()`: a supplier being archived must never
 *     delete an asset, and must never block archiving the supplier either -- the asset's own frozen
 *     `supplier` text already preserves the historical fact regardless of what happens to the link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('supplier')->constrained('suppliers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
        });
    }
};
