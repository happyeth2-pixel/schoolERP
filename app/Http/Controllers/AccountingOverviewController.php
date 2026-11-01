<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\SupplierBill;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** A single, giant-button landing page for the Accounting section -- the handful of numbers an owner
 * actually wants at a glance (cash on hand, earned vs spent this month, who owes/is owed, books healthy
 * or not), each one a live query reusing the exact same Account/Invoice/SupplierBill methods the full
 * reports already use. Every detailed report still exists and is unchanged; this is only an extra,
 * simpler front door in front of them. */
class AccountingOverviewController extends Controller
{
    private function canView(User $user): bool
    {
        return $user->can('accounting-view') || $user->can('accounting-manage') || $user->hasRole('auditor');
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);
        $school = $user->school;

        if (! $school->default_ar_account_id) {
            return Inertia::render('Accounting/Overview', ['installed' => false]);
        }

        $asOf = now()->toDateString();
        $from = now()->startOfMonth()->toDateString();

        $cashTotal = (float) Account::where('school_id', $school->id)->where('is_header', false)->where('is_active', true)
            ->where('type', 'asset')
            ->where('id', '!=', $school->default_ar_account_id)
            ->get()->sum(fn (Account $a) => $a->balance($asOf));

        $revenue = 0.0;
        $expense = 0.0;
        Account::where('school_id', $school->id)->where('is_header', false)->where('is_active', true)
            ->whereIn('type', ['revenue', 'expense'])->get()
            ->each(function (Account $a) use (&$revenue, &$expense, $from, $asOf) {
                $amount = $a->movementBetween($from, $asOf);
                if ($a->type === 'revenue') {
                    $revenue += $amount;
                } else {
                    $expense += $amount;
                }
            });

        $arTotal = (float) Invoice::whereHas('student', fn ($q) => $q->where('school_id', $school->id))
            ->with(['items', 'payments.refunds'])->get()->sum(fn (Invoice $i) => $i->balance());

        $apTotal = $school->default_ap_account_id
            ? (float) SupplierBill::where('school_id', $school->id)
                ->with(['items', 'payments'])->get()->sum(fn (SupplierBill $b) => $b->balance())
            : 0.0;

        $reconciled = null;
        if ($school->default_ar_account_id && $school->default_ap_account_id) {
            $arLedger = $school->defaultArAccount->balance();
            $apLedger = $school->defaultApAccount->balance();
            $reconciled = abs($arLedger - $arTotal) < 0.01 && abs($apLedger - $apTotal) < 0.01;
        }

        return Inertia::render('Accounting/Overview', [
            'installed' => true,
            'as_of' => $asOf,
            'cash_total' => $cashTotal,
            'revenue' => $revenue,
            'expense' => $expense,
            'ar_total' => $arTotal,
            'ap_total' => $apTotal,
            'reconciled' => $reconciled,
        ]);
    }
}
