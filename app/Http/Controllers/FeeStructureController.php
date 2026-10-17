<?php

namespace App\Http\Controllers;

use App\Models\FeeStructure;
use App\Models\Term;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FeeStructureController extends Controller
{
    public function index(Request $request): Response
    {
        $request->user()->can('manage-fee-structures') || abort(403);
        $user = $request->user();

        $query = FeeStructure::whereIn('branch_id', $user->school->branches()->pluck('id'))
            ->with(['branch', 'grade', 'academicYear']);

        if (! $user->isOrgWide()) {
            $query->where('branch_id', $user->branch_id);
        }

        return Inertia::render('FeeStructures/Index', [
            'feeStructures' => $query->orderBy('grade_id')->get(),
            'branches' => $user->isOrgWide()
                ? $user->school->branches()->orderBy('name')->get(['id', 'name'])
                : $user->school->branches()->where('id', $user->branch_id)->get(['id', 'name']),
            'grades' => $user->school->grades()->orderBy('sort_order')->get(['id', 'name']),
            'academicYears' => $user->school->academicYears()->orderByDesc('start_date')->get(['id', 'name', 'is_current']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('manage-fee-structures') || abort(403);
        $user = $request->user();

        $validated = $request->validate([
            'branch_id' => ['required', 'integer'],
            'grade_id' => ['required', 'integer'],
            'academic_year_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'frequency' => ['required', 'in:annual,termly,monthly'],
        ]);

        $this->assertBranchInScope($user, (int) $validated['branch_id']);
        abort_unless($user->school->grades()->whereKey($validated['grade_id'])->exists(), 403);
        abort_unless($user->school->academicYears()->whereKey($validated['academic_year_id'])->exists(), 403);
        $this->assertNoDuplicate((int) $validated['branch_id'], (int) $validated['grade_id'], (int) $validated['academic_year_id'], $validated['name']);

        $fee = FeeStructure::create($validated);

        return back()->with('success', 'Fee item added.')->with('fee_saved', $this->savedSummary($fee, 'added'));
    }

    public function update(Request $request, FeeStructure $feeStructure): RedirectResponse
    {
        $request->user()->can('manage-fee-structures') || abort(403);
        $this->assertBranchInScope($request->user(), $feeStructure->branch_id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'frequency' => ['required', 'in:annual,termly,monthly'],
        ]);

        $this->assertNoDuplicate($feeStructure->branch_id, $feeStructure->grade_id, $feeStructure->academic_year_id, $validated['name'], $feeStructure->id);

        $feeStructure->update($validated);

        return back()
            ->with('success', 'Fee item updated. Invoices already generated keep their original amounts; the new amount applies to invoices generated from now on.')
            ->with('fee_saved', $this->savedSummary($feeStructure->refresh(), 'changed'));
    }

    public function destroy(Request $request, FeeStructure $feeStructure): RedirectResponse
    {
        $request->user()->can('manage-fee-structures') || abort(403);
        $this->assertBranchInScope($request->user(), $feeStructure->branch_id);

        $feeStructure->delete();

        return back()->with('success', 'Fee item removed (archived). Existing invoices are unchanged.');
    }

    /** The guided page for adding a fee: which grade and year, then the fee, then a check. */
    public function create(Request $request): Response
    {
        $request->user()->can('manage-fee-structures') || abort(403);

        return Inertia::render('FeeStructures/Wizard', $this->wizardProps($request->user()) + ['fee' => null]);
    }

    /** The same guided page for changing a fee: the grade, year and branch are fixed (the update rule), the fee is prefilled. */
    public function edit(Request $request, FeeStructure $feeStructure): Response
    {
        $request->user()->can('manage-fee-structures') || abort(403);
        $this->assertBranchInScope($request->user(), $feeStructure->branch_id);
        $feeStructure->load(['branch', 'grade', 'academicYear']);

        return Inertia::render('FeeStructures/Wizard', $this->wizardProps($request->user()) + ['fee' => [
            'id' => $feeStructure->id,
            'branch_id' => $feeStructure->branch_id,
            'branch' => $feeStructure->branch->name,
            'grade_id' => $feeStructure->grade_id,
            'grade' => $feeStructure->grade->name,
            'academic_year_id' => $feeStructure->academic_year_id,
            'academic_year' => $feeStructure->academicYear->name,
            'name' => $feeStructure->name,
            'amount' => (float) $feeStructure->amount,
            'frequency' => $feeStructure->frequency,
        ]]);
    }

    private function wizardProps($user): array
    {
        $branches = $user->isOrgWide()
            ? $user->school->branches()->orderBy('name')->get(['id', 'name'])
            : $user->school->branches()->where('id', $user->branch_id)->get(['id', 'name']);

        $existing = FeeStructure::whereIn('branch_id', $branches->pluck('id'))->get(['id', 'branch_id', 'grade_id', 'academic_year_id', 'name', 'amount', 'frequency']);
        $years = $user->school->academicYears()->orderByDesc('is_current')->orderByDesc('start_date')->get(['id', 'name', 'is_current']);

        return [
            'branches' => $branches,
            'grades' => $user->school->grades()->orderBy('sort_order')->get(['id', 'name']),
            'academicYears' => $years,
            'existing' => $existing,
            // so the last step can warn that a per-term fee needs the year's terms
            'termCounts' => Term::whereIn('academic_year_id', $years->pluck('id'))->selectRaw('academic_year_id, COUNT(*) as n')->groupBy('academic_year_id')->pluck('n', 'academic_year_id'),
            'nameSuggestions' => FeeStructure::whereIn('branch_id', $branches->pluck('id'))->distinct()->orderBy('name')->limit(8)->pluck('name'),
            'saved' => session('fee_saved'),
        ];
    }

    private function savedSummary(FeeStructure $fee, string $action): array
    {
        $fee->loadMissing(['grade', 'academicYear', 'branch']);

        return [
            'action' => $action,
            'id' => $fee->id,
            'name' => $fee->name,
            'amount' => (float) $fee->amount,
            'frequency' => $fee->frequency,
            'grade' => $fee->grade->name,
            'grade_id' => $fee->grade_id,
            'branch_id' => $fee->branch_id,
            'academic_year' => $fee->academicYear->name,
            'academic_year_id' => $fee->academic_year_id,
        ];
    }

    /**
     * One fee of a given name per grade, school year and branch: a second "Tuition" would be billed on top of the
     * first and every student would be charged twice. (Names are compared without regard to case or spaces.)
     */
    private function assertNoDuplicate(int $branchId, int $gradeId, int $yearId, string $name, ?int $exceptId = null): void
    {
        $exists = FeeStructure::where('branch_id', $branchId)->where('grade_id', $gradeId)->where('academic_year_id', $yearId)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => 'A fee called "'.trim($name).'" already exists for this grade and school year. Change that fee instead, or use a different name.']);
        }
    }

    private function assertBranchInScope($user, int $branchId): void
    {
        $branch = $user->school->branches()->whereKey($branchId)->first();
        abort_unless($branch, 403);

        if (! $user->isOrgWide()) {
            abort_unless($user->branch_id === $branchId, 403);
        }
    }
}
