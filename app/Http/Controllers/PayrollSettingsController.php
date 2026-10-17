<?php

namespace App\Http\Controllers;

use App\Models\PayrollTaxBracket;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pension rates + progressive income-tax brackets -- HR Module Phase F. Both are seeded with researched
 * Ethiopian statutory defaults but are fully editable, and the page itself carries a prominent "verify
 * before relying on this" notice, per the user's own explicit instruction from the start of this module.
 */
class PayrollSettingsController extends Controller
{
    private function canView(User $user): bool
    {
        return $user->can('payroll-view') || $user->can('payroll-manage') || $user->hasRole('auditor');
    }

    public function edit(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        return Inertia::render('Hr/Payroll/Settings', [
            'canManage' => $user->can('payroll-manage'),
            'pensionEmployeeRate' => (float) $user->school->pension_employee_rate,
            'pensionEmployerRate' => (float) $user->school->pension_employer_rate,
            'brackets' => $user->school->payrollTaxBrackets->map(fn (PayrollTaxBracket $b) => [
                'id' => $b->id,
                'min_amount' => (float) $b->min_amount,
                'max_amount' => $b->max_amount !== null ? (float) $b->max_amount : null,
                'rate' => (float) $b->rate,
                'deduction' => (float) $b->deduction,
            ]),
        ]);
    }

    public function updatePensionRates(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('payroll-manage'), 403);

        $validated = $request->validate([
            'pension_employee_rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'pension_employer_rate' => ['required', 'numeric', 'min:0', 'max:1'],
        ]);

        $user->school->update($validated);

        return back()->with('success', 'Pension rates updated.');
    }

    public function storeBracket(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('payroll-manage'), 403);

        $validated = $this->bracketRules($request);
        $user->school->payrollTaxBrackets()->create($validated);

        return back()->with('success', 'Tax bracket added.');
    }

    public function updateBracket(Request $request, PayrollTaxBracket $bracket): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('payroll-manage'), 403);
        abort_unless($bracket->school_id === $user->school_id, 403);

        $bracket->update($this->bracketRules($request));

        return back()->with('success', 'Tax bracket updated.');
    }

    public function destroyBracket(Request $request, PayrollTaxBracket $bracket): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('payroll-manage'), 403);
        abort_unless($bracket->school_id === $user->school_id, 403);

        $bracket->delete();

        return back()->with('success', 'Tax bracket removed.');
    }

    private function bracketRules(Request $request): array
    {
        return $request->validate([
            'min_amount' => ['required', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'gt:min_amount'],
            'rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'deduction' => ['required', 'numeric', 'min:0'],
        ]);
    }
}
