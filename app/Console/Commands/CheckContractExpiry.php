<?php

namespace App\Console\Commands;

use App\Models\EmploymentContract;
use App\Models\User;
use App\Notifications\ContractExpiringSoon;
use Illuminate\Console\Command;

/**
 * Daily housekeeping for employment contracts -- this needs its own Render Cron Job in production, exactly
 * like `backup:run`/`announcements:notify-due`/`security:prune`, since this app has no in-process scheduler
 * loop. Not yet configured, only disclosed to the user.
 *
 * Two things happen, in order:
 *   1. An `active` contract whose end date is within NOTICE_DAYS moves to `expiring` and notifies whoever
 *      holds `contract-manage` in the employee's own branch (plus org-wide holders) -- exactly once per
 *      contract, via `expiry_notified`, the same "don't spam" rule the Inventory module's low-stock alert
 *      already follows.
 *   2. An `active` or `expiring` contract whose end date has already passed moves to `expired` -- silent
 *      bookkeeping, not a reminder (the reminder already fired in step 1).
 *
 * A `draft` contract never auto-transitions (it hasn't taken effect yet); `terminated` is a manual, final
 * decision this command never overrides.
 */
class CheckContractExpiry extends Command
{
    protected $signature = 'hr:check-contract-expiry';

    protected $description = 'Move employment contracts into expiring/expired status and notify HR of ones approaching their end date.';

    private const NOTICE_DAYS = 30;

    public function handle(): int
    {
        $noticeDate = now()->addDays(self::NOTICE_DAYS)->toDateString();
        $today = now()->toDateString();

        $expiringSoon = EmploymentContract::where('status', 'active')
            ->whereNotNull('end_date')
            ->where('end_date', '<=', $noticeDate)
            ->where('expiry_notified', false)
            ->with('employee')
            ->get();

        foreach ($expiringSoon as $contract) {
            $contract->update(['status' => 'expiring', 'expiry_notified' => true]);
            $this->notifyHr($contract);
            $this->line("expiring: {$contract->employee->full_name} ({$contract->end_date->toDateString()})");
        }

        $justExpired = EmploymentContract::whereIn('status', ['active', 'expiring'])
            ->whereNotNull('end_date')
            ->where('end_date', '<', $today)
            ->get();

        foreach ($justExpired as $contract) {
            $contract->update(['status' => 'expired']);
            $this->line("expired: contract #{$contract->id}");
        }

        $this->info($expiringSoon->count().' contract(s) moved to expiring, '.$justExpired->count().' to expired.');

        return self::SUCCESS;
    }

    private function notifyHr(EmploymentContract $contract): void
    {
        $employee = $contract->employee;
        $recipients = User::where('school_id', $employee->school_id)
            ->where(fn ($q) => $q->where('branch_id', $employee->branch_id)->orWhereNull('branch_id'))
            ->get()
            ->filter(fn (User $u) => $u->can('contract-manage'));

        foreach ($recipients as $recipient) {
            $recipient->notify(new ContractExpiringSoon($contract));
        }
    }
}
