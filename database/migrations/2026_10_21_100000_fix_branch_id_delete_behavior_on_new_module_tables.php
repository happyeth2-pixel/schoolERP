<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A real, traced data-loss chain, not a hypothetical one. Deleting a branch currently CASCADES:
 *   branches -> inventory_stores (branch_id cascadeOnDelete) -> stock_movements AND stock_balances
 *              (store_id cascadeOnDelete)
 *   branches -> assets (branch_id cascadeOnDelete) -> asset_events (asset_id cascadeOnDelete)
 *   branches -> stocktakes (branch_id cascadeOnDelete) -> stocktake_lines (stocktake_id cascadeOnDelete)
 *   branches -> purchase_requests (branch_id cascadeOnDelete) -- but purchase_orders.purchase_request_id is
 *              restrictOnDelete, so if any PO already exists against a request, the cascade hits that
 *              restrict mid-chain and the WHOLE branch-delete transaction fails unpredictably instead of
 *              either cleanly succeeding or being a deliberate, visible decision.
 *   branches -> calendar_events (branch_id cascadeOnDelete)
 *
 * `stock_movements` and `asset_events` are both documented, model-guarded (DeletionBlockedException on
 * update/delete) as PERMANENT, append-only history -- the whole point of that guard is that nothing can ever
 * erase them. A branch deletion cascading through inventory_stores/assets would silently destroy that
 * "permanent" history at the one layer (the database itself) that guard can never see or stop, completely
 * defeating it. `purchase_requests`/`stocktakes`/`calendar_events` are the same "never destroy an
 * operational record just because its branch went away" case.
 *
 * Fix: these five tables' nullable `branch_id` becomes nullOnDelete instead of cascadeOnDelete -- deleting a
 * branch un-scopes the record (branch_id becomes NULL, the existing "shared / every branch" convention this
 * project already uses everywhere else) rather than deleting it or anything beneath it. This matches the
 * delete behaviour `purchase_orders`, `goods_receipts`, `accounts`, `journal_entries`, `supplier_bills`,
 * `supplier_payments`, `departments` and `employees` already use for their own nullable `branch_id` --
 * so this closes the one real inconsistency among them, it does not invent a new convention.
 *
 * Deliberately NOT touched here: `payment_accounts`/`announcements` (pre-existing core tables, outside the
 * new-modules scope this was raised against, and not something this pass has reason to believe is wrong);
 * every table with a NON-nullable branch_id (sections, students, fee_structures, complaints,
 * optional_services, rooms, timetable_slots, book_copies) -- nullOnDelete is not even a valid choice for a
 * required column, and cascading those on a genuine branch deletion was already the deliberate original
 * design, unrelated to this specific chain; and `payroll_runs`, which already uses the even stronger
 * restrictOnDelete (a branch can never be deleted at all while it has payroll history) -- correct as-is.
 */
return new class extends Migration
{
    private array $tables = ['inventory_stores', 'assets', 'stocktakes', 'purchase_requests', 'calendar_events'];

    public function up(): void
    {
        // Drop and re-add are deliberately two SEPARATE Schema::table() calls, not one combined Blueprint --
        // on SQLite (used by this project's test suite), Laravel rebuilds the table via a temp-table copy to
        // emulate "ALTER TABLE ... DROP/ADD FOREIGN KEY", and a single Blueprint carrying both a drop and an
        // add for the very same column loses track of the rest of the column list mid-rebuild, producing a
        // malformed `CREATE TABLE "__temp__..." (, foreign key(...))` with no columns at all. Verified by
        // reproducing that exact failure first, then confirming two separate calls avoid it, before writing
        // this version. MySQL/Postgres have no such quirk, but splitting costs nothing there either.
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['branch_id']);
            });
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['branch_id']);
            });
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            });
        }
    }
};
