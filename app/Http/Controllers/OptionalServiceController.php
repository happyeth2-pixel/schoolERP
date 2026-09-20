<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\OptionalService;
use App\Models\Student;
use App\Models\Term;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class OptionalServiceController extends Controller
{
    public function index(Request $request): Response
    {
        $request->user()->can('manage-fee-structures') || abort(403);
        $user = $request->user();

        $query = OptionalService::whereIn('branch_id', $user->school->branches()->pluck('id'))
            ->with(['branch', 'academicYear']);

        if (! $user->isOrgWide()) {
            $query->where('branch_id', $user->branch_id);
        }

        return Inertia::render('OptionalServices/Index', [
            'optionalServices' => $query->orderBy('name')->get()->map(fn (OptionalService $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'amount' => $s->amount,
                'frequency' => $s->frequency,
                'branch' => $s->branch->name,
                'academic_year' => $s->academicYear->name,
                'enrolled_count' => $s->students()->count(),
            ]),
            'branches' => $user->isOrgWide()
                ? $user->school->branches()->orderBy('name')->get(['id', 'name'])
                : $user->school->branches()->where('id', $user->branch_id)->get(['id', 'name']),
            'academicYears' => $user->school->academicYears()->orderByDesc('start_date')->get(['id', 'name', 'is_current']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('manage-fee-structures') || abort(403);
        $user = $request->user();

        $validated = $request->validate([
            'branch_id' => ['required', 'integer'],
            'academic_year_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'frequency' => ['required', 'in:annual,termly,monthly'],
        ]);

        $this->assertBranchInScope($user, (int) $validated['branch_id']);
        abort_unless($user->school->academicYears()->whereKey($validated['academic_year_id'])->exists(), 403);

        OptionalService::create($validated);

        return back()->with('success', 'Optional service added.');
    }

    public function update(Request $request, OptionalService $optionalService): RedirectResponse
    {
        $request->user()->can('manage-fee-structures') || abort(403);
        $this->assertBranchInScope($request->user(), $optionalService->branch_id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'frequency' => ['required', 'in:annual,termly,monthly'],
        ]);

        $optionalService->update($validated);

        return back()->with('success', 'Optional service updated.');
    }

    public function destroy(Request $request, OptionalService $optionalService): RedirectResponse
    {
        $request->user()->can('manage-fee-structures') || abort(403);
        $this->assertBranchInScope($request->user(), $optionalService->branch_id);

        $optionalService->delete();

        return back()->with('success', 'Optional service removed.');
    }

    /**
     * Enrolls a student in an opt-in service. If the student already
     * has an invoice for that service's academic year, the charge is
     * added to it immediately rather than waiting for the next
     * invoice-generation run -- "opted in" and "billed for it" stay in
     * sync without a separate step.
     */
    public function enrollStudent(Request $request, Student $student): RedirectResponse
    {
        $request->user()->can('manage-invoices') || abort(403);
        $this->assertStudentInScope($request->user(), $student);

        $validated = $request->validate([
            'optional_service_id' => ['required', 'integer'],
        ]);

        $service = OptionalService::whereKey($validated['optional_service_id'])->firstOrFail();
        abort_unless($service->branch_id === $student->branch_id, 422);

        if ($student->optionalServices()->whereKey($service->id)->exists()) {
            return back()->withErrors(['optional_service_id' => 'Already enrolled in this service.']);
        }

        $student->optionalServices()->attach($service->id, ['enrolled_on' => now()->toDateString()]);

        $invoice = $this->currentPeriodInvoice($student, $service);

        if ($invoice) {
            $invoice->items()->create(['description' => $service->name, 'amount' => $service->amount]);
        }

        return back()->with('success', "Enrolled in {$service->name}.");
    }

    /**
     * The invoice this service's charge should land on right now, if
     * one already exists for the period we're currently in -- an
     * annual invoice for the year, a termly one for whichever Term
     * covers today, or a monthly one for the current calendar month.
     * If no such invoice exists yet, this returns null and the charge
     * simply gets picked up the next time invoices are generated for
     * that period, same as any other fee.
     */
    private function currentPeriodInvoice(Student $student, OptionalService $service): ?Invoice
    {
        $periodStart = match ($service->frequency) {
            'annual' => $service->academicYear->start_date,
            'termly' => Term::where('academic_year_id', $service->academic_year_id)
                ->whereDate('start_date', '<=', now())
                ->whereDate('end_date', '>=', now())
                ->value('start_date'),
            'monthly' => Carbon::now()->startOfMonth()->toDateString(),
        };

        if (! $periodStart) {
            return null;
        }

        return Invoice::where('student_id', $student->id)
            ->where('academic_year_id', $service->academic_year_id)
            ->where('frequency', $service->frequency)
            ->where('period_start', $periodStart)
            ->first();
    }

    /**
     * Withdraws a student from a service going forward. This
     * deliberately does NOT touch any invoice item already charged for
     * it -- reversing a charge that may already be paid is a billing
     * decision (discount/waive on the invoice), not something this
     * toggle should do silently.
     */
    public function withdrawStudent(Request $request, Student $student, OptionalService $optionalService): RedirectResponse
    {
        $request->user()->can('manage-invoices') || abort(403);
        $this->assertStudentInScope($request->user(), $student);

        $student->optionalServices()->detach($optionalService->id);

        return back()->with('success', "Removed from {$optionalService->name}.");
    }

    private function assertBranchInScope($user, int $branchId): void
    {
        $branch = $user->school->branches()->whereKey($branchId)->first();
        abort_unless($branch, 403);

        if (! $user->isOrgWide()) {
            abort_unless($user->branch_id === $branchId, 403);
        }
    }

    private function assertStudentInScope($user, Student $student): void
    {
        abort_unless($student->school_id === $user->school_id, 403);

        if (! $user->isOrgWide()) {
            abort_unless($student->branch_id === $user->branch_id, 403);
        }
    }
}
