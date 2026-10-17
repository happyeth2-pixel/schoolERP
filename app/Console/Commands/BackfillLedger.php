<?php

namespace App\Console\Commands;

use App\Models\AccountingPeriod;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\School;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\AccountingPostings;
use App\Support\ChartOfAccountsTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One-time, explicitly-triggered catch-up: posts ledger entries for Invoices/Payments/PaymentRefunds/
 * SupplierBills/SupplierPayments that existed before the accounting engine (Phase A-C) was adopted, or
 * before a school installed its chart of accounts, so the ledger's control-account balances reconcile
 * with what the fee/procurement systems have always said. Run `accounting:backfill-ledger --dry-run`
 * first, and check `FinancialReportController::reconciliation()` afterward to verify the result.
 *
 * Idempotent and safe to re-run: a source record that already has ANY journal entry is left alone
 * entirely -- it is already being tracked live by App\Services\AccountingPostings going forward, so
 * running this command twice posts nothing a second time.
 *
 * Posts with the SOURCE RECORD'S OWN historical date, never today's date, auto-creating the minimum
 * number of non-overlapping "Historical Backfill" accounting periods needed to cover dates where no
 * period exists yet. A date already covered by a CLOSED period is never force-opened -- that record is
 * reported as skipped instead, honoring the same closed-period protection the live posting engine
 * enforces everywhere else. Periods this command itself creates are closed again once the run for that
 * school succeeds; any period the school already had is left exactly as it was.
 */
class BackfillLedger extends Command
{
    protected $signature = 'accounting:backfill-ledger {--school= : limit to one school ID} {--dry-run : report what would be posted without posting anything}';

    protected $description = 'Post ledger entries for existing invoices/payments/supplier bills that predate the accounting engine or its adoption by a school.';

    /** @var list<int> */
    private array $createdPeriodIds = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $schoolId = $this->option('school');

        $schools = School::when($schoolId, fn ($q) => $q->where('id', $schoolId))->get()
            ->filter(fn (School $s) => ChartOfAccountsTemplate::isInstalled($s));

        if ($schools->isEmpty()) {
            $this->warn('No school has a chart of accounts installed yet -- nothing to backfill.');

            return self::SUCCESS;
        }

        foreach ($schools as $school) {
            $this->backfillSchool($school, $dryRun);
        }

        return self::SUCCESS;
    }

    private function backfillSchool(School $school, bool $dryRun): void
    {
        $actor = User::where('school_id', $school->id)->whereHas('roles', fn ($q) => $q->where('name', 'owner'))->first();
        if (! $actor) {
            $this->warn("Skipping {$school->name}: no owner account found to attribute the postings to.");

            return;
        }

        $candidates = $this->collectCandidates($school);
        if ($candidates->isEmpty()) {
            $this->info("{$school->name}: nothing to backfill, already fully posted.");

            return;
        }

        $this->info("{$school->name}: {$candidates->count()} unposted record(s) found.");

        if ($dryRun) {
            foreach ($candidates as $c) {
                $this->line("  would post: {$c['label']} dated {$c['date']}");
            }

            return;
        }

        $this->createdPeriodIds = [];

        DB::transaction(function () use ($school, $actor, $candidates) {
            $this->ensurePeriodsCover($school, $candidates->pluck('date')->unique()->sort()->values());

            foreach ($candidates as $c) {
                $period = $school->periodFor($c['date']);
                if (! $period || $period->status !== 'open') {
                    $this->warn("  skipped {$c['label']}: no open accounting period covers {$c['date']} (closed period protected).");

                    continue;
                }

                $posted = ($c['post'])($actor, $c['date']);
                if ($posted) {
                    $this->line("  posted: {$c['label']} dated {$c['date']}");
                } else {
                    $this->line("  skipped: {$c['label']} -- nothing to post (zero amount or an account mapping is still missing).");
                }
            }

            foreach ($this->createdPeriodIds as $periodId) {
                AccountingPeriod::whereKey($periodId)->update(['status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now()]);
            }
        });
    }

    /** @return Collection<int, array{label:string, date:string, post:callable}> */
    private function collectCandidates(School $school): Collection
    {
        $items = collect();

        Invoice::whereHas('student', fn ($q) => $q->where('school_id', $school->id))
            ->whereDoesntHave('journalEntries')
            ->with('items.feeStructure', 'items.optionalService', 'payments.refunds', 'student')
            ->get()
            ->each(function (Invoice $invoice) use ($items) {
                $items->push([
                    'label' => "Invoice {$invoice->invoice_number}",
                    'date' => $invoice->period_start->toDateString(),
                    'post' => function (User $actor, string $date) use ($invoice) {
                        $entry = AccountingPostings::postInvoiceIssued($invoice, $actor, $date);
                        if ((float) $invoice->discount_amount > 0.005) {
                            AccountingPostings::postDiscountChanged($invoice, 0.0, (float) $invoice->discount_amount, $actor, $date);
                        }
                        if ($invoice->is_waived && $invoice->waivedAmount() > 0.005) {
                            AccountingPostings::postWaiverChanged($invoice, true, $invoice->waivedAmount(), $actor, $date);
                        }

                        return $entry;
                    },
                ]);
            });

        Payment::where('status', 'verified')
            ->whereHas('invoice.student', fn ($q) => $q->where('school_id', $school->id))
            ->whereDoesntHave('journalEntries')
            ->with('invoice.student')
            ->get()
            ->each(function (Payment $payment) use ($items) {
                $date = ($payment->verified_at ?? $payment->created_at)->toDateString();
                $items->push([
                    'label' => "Payment #{$payment->id}",
                    'date' => $date,
                    'post' => fn (User $actor, string $date) => AccountingPostings::postPaymentVerified($payment, $actor, $date),
                ]);
            });

        PaymentRefund::whereHas('payment.invoice.student', fn ($q) => $q->where('school_id', $school->id))
            ->whereDoesntHave('journalEntries')
            ->with('payment.invoice.student')
            ->get()
            ->each(function (PaymentRefund $refund) use ($items) {
                $date = ($refund->refunded_at ?? $refund->created_at)->toDateString();
                $items->push([
                    'label' => "Refund #{$refund->id}",
                    'date' => $date,
                    'post' => fn (User $actor, string $date) => AccountingPostings::postPaymentRefunded($refund, $actor, $date),
                ]);
            });

        SupplierBill::where('school_id', $school->id)
            ->whereDoesntHave('journalEntries')
            ->with('items', 'supplier')
            ->get()
            ->each(function (SupplierBill $bill) use ($items) {
                $items->push([
                    'label' => "Supplier bill {$bill->bill_number}",
                    'date' => $bill->bill_date->toDateString(),
                    'post' => fn (User $actor, string $date) => AccountingPostings::postSupplierBillRecorded($bill, $actor, $date),
                ]);
            });

        SupplierPayment::where('school_id', $school->id)
            ->whereDoesntHave('journalEntries')
            ->with('supplierBill')
            ->get()
            ->each(function (SupplierPayment $payment) use ($items) {
                $items->push([
                    'label' => "Supplier payment #{$payment->id}",
                    'date' => $payment->paid_date->toDateString(),
                    'post' => fn (User $actor, string $date) => AccountingPostings::postSupplierPaymentRecorded($payment, $actor, $date),
                ]);
            });

        return $items->sortBy('date')->values();
    }

    /** Creates the minimum number of non-overlapping "Historical Backfill" periods needed so every date in
     * $dates that currently has NO period at all (open or closed) gets one. A date already covered by an
     * existing period -- open or closed -- is left alone; a closed one is handled per-record as a skip in
     * the posting loop, never silently reopened here. */
    private function ensurePeriodsCover(School $school, Collection $dates): void
    {
        $gaps = $dates->reject(fn ($date) => $school->periodFor($date) !== null)->values();
        if ($gaps->isEmpty()) {
            return;
        }

        $ranges = [];
        $current = null;
        foreach ($gaps as $date) {
            if ($current === null) {
                $current = [$date, $date];

                continue;
            }

            $crossesExisting = $school->accountingPeriods()
                ->where('start_date', '<=', $date)->where('end_date', '>=', $current[0])->exists();

            if ($crossesExisting) {
                $ranges[] = $current;
                $current = [$date, $date];
            } else {
                $current[1] = $date;
            }
        }
        if ($current !== null) {
            $ranges[] = $current;
        }

        foreach ($ranges as [$start, $end]) {
            $period = AccountingPeriod::create([
                'school_id' => $school->id,
                'name' => "Historical Backfill ({$start} to {$end})",
                'start_date' => $start,
                'end_date' => $end,
                'status' => 'open',
            ]);
            $this->createdPeriodIds[] = $period->id;
        }
    }
}
