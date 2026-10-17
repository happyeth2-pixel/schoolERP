<?php

namespace App\Http\Controllers;

use App\Exceptions\DeletionBlockedException;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use App\Notifications\PayslipReady;
use App\Services\AccountingPostings;
use App\Services\Payroll;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Monthly payroll runs -- HR Module Phase F. A `draft` run can be deleted and regenerated freely (nothing
 * has been promised to anyone yet); `finalize()` is the one-way step that locks every payslip, notifies
 * each employee, and -- confirmed via AskUserQuestion -- attempts a best-effort posting to the accounting
 * engine, the same "never block the real action" pattern every other money-moving module in this app uses.
 */
class PayrollRunController extends Controller
{
    private function canView(User $user): bool
    {
        return $user->can('payroll-view') || $user->can('payroll-manage') || $user->hasRole('auditor');
    }

    private function assertBranchScope(User $user, int $branchId): void
    {
        abort_unless($user->isOrgWide() || $branchId === $user->branch_id, 403);
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $runs = PayrollRun::where('school_id', $user->school_id)
            ->when(! $user->isOrgWide(), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->with('branch')->withCount('payslips')
            ->orderByDesc('year')->orderByDesc('month')->get()
            ->map(fn (PayrollRun $r) => [
                'id' => $r->id,
                'branch' => $r->branch->name,
                'label' => $r->label(),
                'status' => $r->status,
                'payslips_count' => $r->payslips_count,
            ]);

        return Inertia::render('Hr/Payroll/Runs/Index', [
            'runs' => $runs,
            'canManage' => $user->can('payroll-manage'),
            'branches' => $user->isOrgWide() ? \App\Models\Branch::where('school_id', $user->school_id)->orderBy('name')->get(['id', 'name']) : [],
            'fixedBranch' => $user->isOrgWide() ? null : ['id' => $user->branch_id, 'name' => $user->branch?->name],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('payroll-manage'), 403);

        $validated = $request->validate([
            'branch_id' => ['required', 'integer'],
            'period' => ['required', 'date_format:Y-m'],
        ]);
        $this->assertBranchScope($user, (int) $validated['branch_id']);
        $branch = Branch::where('school_id', $user->school_id)->findOrFail($validated['branch_id']);
        [$year, $month] = array_map('intval', explode('-', $validated['period']));

        if (PayrollRun::where('branch_id', $branch->id)->where('year', $year)->where('month', $month)->exists()) {
            throw ValidationException::withMessages(['period' => "A payroll run for {$branch->name} already exists for this month."]);
        }

        $result = Payroll::generate($user->school, $branch, $year, $month, $user);

        return redirect()->route('payroll-runs.show', $result['run']->id)
            ->with('success', "{$result['created']} payslip(s) generated.")
            ->with('payroll_skipped', $result['skipped']);
    }

    public function show(Request $request, PayrollRun $run): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);
        abort_unless($run->school_id === $user->school_id, 403);
        $this->assertBranchScope($user, $run->branch_id);

        return Inertia::render('Hr/Payroll/Runs/Show', [
            'run' => [
                'id' => $run->id,
                'branch' => $run->branch->name,
                'label' => $run->label(),
                'status' => $run->status,
                'finalized_at' => $run->finalized_at?->toDateString(),
            ],
            'canManage' => $user->can('payroll-manage'),
            'skipped' => $request->session()->get('payroll_skipped', []),
            'payslips' => $run->payslips()->with('employee')->get()->map(fn (Payslip $p) => [
                'id' => $p->id,
                'employee' => $p->employee->full_name,
                'basic_salary' => (float) $p->basic_salary,
                'income_tax' => (float) $p->income_tax,
                'employee_pension' => (float) $p->employee_pension,
                'employer_pension' => (float) $p->employer_pension,
                'net_pay' => (float) $p->net_pay,
            ]),
            'totals' => [
                'gross' => (float) $run->payslips()->sum('gross_pay'),
                'income_tax' => (float) $run->payslips()->sum('income_tax'),
                'employee_pension' => (float) $run->payslips()->sum('employee_pension'),
                'net_pay' => (float) $run->payslips()->sum('net_pay'),
            ],
        ]);
    }

    public function finalize(Request $request, PayrollRun $run): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('payroll-manage'), 403);
        abort_unless($run->school_id === $user->school_id, 403);
        $this->assertBranchScope($user, $run->branch_id);
        abort_unless($run->status === 'draft', 422, 'This payroll run has already been finalized.');

        $run->update(['status' => 'finalized', 'finalized_by' => $user->id, 'finalized_at' => now()]);

        $run->payslips()->with('employee.user')->get()->each(function (Payslip $payslip) {
            if ($payslip->employee->user_id) {
                $payslip->employee->user->notify(new PayslipReady($payslip));
            }
        });

        try {
            $entry = AccountingPostings::postPayrollRunFinalized($run->fresh('payslips'), $user);
            if ($entry) {
                $run->update(['journal_entry_id' => $entry->id]);
            }
        } catch (\Throwable $e) {
            Log::warning('Accounting: failed to post payroll-run entry', ['payroll_run_id' => $run->id, 'error' => $e->getMessage()]);
        }

        return back()->with('success', 'Payroll run finalized.');
    }

    public function destroy(Request $request, PayrollRun $run): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('payroll-manage'), 403);
        abort_unless($run->school_id === $user->school_id, 403);
        $this->assertBranchScope($user, $run->branch_id);

        try {
            $run->delete();
        } catch (DeletionBlockedException $e) {
            return back()->withErrors(['run' => $e->getMessage()]);
        }

        return redirect()->route('payroll-runs.index')->with('success', 'Payroll run removed.');
    }

    public function mine(Request $request): Response
    {
        $user = $request->user();
        $employee = Employee::where('school_id', $user->school_id)->where('user_id', $user->id)->first();

        return Inertia::render('Hr/Payroll/Mine', [
            'employee' => $employee ? ['id' => $employee->id, 'full_name' => $employee->full_name] : null,
            // Only a FINALIZED run's payslips are ever shown here -- a draft is just a working
            // calculation that hasn't been promised to anyone yet, same rule as Leave/Performance drafts.
            'payslips' => $employee ? $employee->payslips()->whereHas('payrollRun', fn ($q) => $q->where('status', 'finalized'))->with('payrollRun')->get()->map(fn (Payslip $p) => [
                'id' => $p->id,
                'period' => $p->payrollRun->label(),
                'basic_salary' => (float) $p->basic_salary,
                'income_tax' => (float) $p->income_tax,
                'employee_pension' => (float) $p->employee_pension,
                'net_pay' => (float) $p->net_pay,
            ])->all() : [],
        ]);
    }
}
