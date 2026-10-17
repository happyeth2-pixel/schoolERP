<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccountingPeriodController extends Controller
{
    private function canView(User $user): bool
    {
        return $user->can('accounting-view') || $user->can('accounting-manage') || $user->hasRole('auditor');
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        return Inertia::render('Accounting/Periods/Index', [
            'periods' => AccountingPeriod::where('school_id', $user->school_id)
                ->orderByDesc('start_date')
                ->get()
                ->map(fn (AccountingPeriod $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'start_date' => $p->start_date->toDateString(),
                    'end_date' => $p->end_date->toDateString(),
                    'status' => $p->status,
                    'entry_count' => $p->entries()->count(),
                ]),
            'canManage' => $user->can('accounting-period-manage'),
            'canReopen' => $user->can('accounting-period-reopen'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('accounting-period-manage'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
        ]);

        $overlap = AccountingPeriod::where('school_id', $user->school_id)
            ->where('start_date', '<=', $validated['end_date'])
            ->where('end_date', '>=', $validated['start_date'])
            ->exists();
        if ($overlap) {
            return back()->withErrors(['start_date' => 'This period overlaps an existing accounting period.']);
        }

        AccountingPeriod::create([
            'school_id' => $user->school_id,
            'name' => $validated['name'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'status' => 'open',
        ]);

        return back()->with('success', 'Accounting period opened.');
    }

    public function close(Request $request, AccountingPeriod $accountingPeriod): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('accounting-period-manage'), 403);
        abort_unless($accountingPeriod->school_id === $user->school_id, 403);
        abort_unless($accountingPeriod->status === 'open', 422);

        $accountingPeriod->update(['status' => 'closed', 'closed_by' => $user->id, 'closed_at' => now()]);

        return back()->with('success', "Accounting period \"{$accountingPeriod->name}\" closed.");
    }

    public function reopen(Request $request, AccountingPeriod $accountingPeriod): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('accounting-period-reopen'), 403);
        abort_unless($accountingPeriod->school_id === $user->school_id, 403);
        abort_unless($accountingPeriod->status === 'closed', 422);

        $accountingPeriod->update(['status' => 'open', 'reopened_by' => $user->id, 'reopened_at' => now()]);

        return back()->with('success', "Accounting period \"{$accountingPeriod->name}\" reopened.");
    }
}
