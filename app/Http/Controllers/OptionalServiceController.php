<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\OptionalService;
use App\Models\Student;
use App\Services\StudentServices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OptionalServiceController extends Controller
{
    private const CUSTOM_AMOUNT_RULES = ['nullable', 'numeric', 'min:0', 'max:99999999.99'];

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
                'category' => $s->category,
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

    /** "Add a service" as three short steps: where it applies, what it is, what it costs. */
    public function create(Request $request): Response
    {
        $request->user()->can('manage-fee-structures') || abort(403);
        $user = $request->user();

        return Inertia::render('OptionalServices/Wizard', [
            'branches' => $user->isOrgWide()
                ? $user->school->branches()->orderBy('name')->get(['id', 'name'])
                : $user->school->branches()->where('id', $user->branch_id)->get(['id', 'name']),
            'academicYears' => $user->school->academicYears()->orderByDesc('start_date')->get(['id', 'name', 'is_current']),
            'existing' => OptionalService::whereIn('branch_id', $user->school->branches()->pluck('id'))
                ->when(! $user->isOrgWide(), fn ($q) => $q->where('branch_id', $user->branch_id))
                ->get(['id', 'branch_id', 'academic_year_id', 'name', 'category']),
            'saved' => session('service_saved'),
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
            'category' => ['sometimes', 'in:'.implode(',', OptionalService::CATEGORIES)],
            'amount' => ['required', 'numeric', 'min:0'],
            'frequency' => ['required', 'in:annual,termly,monthly'],
        ]);

        $this->assertBranchInScope($user, (int) $validated['branch_id']);
        abort_unless($user->school->academicYears()->whereKey($validated['academic_year_id'])->exists(), 403);

        $service = OptionalService::create($validated);

        return back()->with('success', 'Optional service added.')->with('service_saved', [
            'id' => $service->id,
            'name' => $service->name,
            'category' => $service->category,
            'amount' => $service->amount,
            'frequency' => $service->frequency,
            'branch' => $service->branch->name,
            'academic_year' => $service->academicYear->name,
        ]);
    }

    public function update(Request $request, OptionalService $optionalService): RedirectResponse
    {
        $request->user()->can('manage-fee-structures') || abort(403);
        $this->assertBranchInScope($request->user(), $optionalService->branch_id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['sometimes', 'in:'.implode(',', OptionalService::CATEGORIES)],
            'amount' => ['required', 'numeric', 'min:0'],
            'frequency' => ['required', 'in:annual,termly,monthly'],
        ]);

        // Turning a service into a transport route must not leave anyone on two routes at once.
        if (($validated['category'] ?? null) === 'transport' && $optionalService->category !== 'transport') {
            $onTwo = $optionalService->students()
                ->whereHas('optionalServices', fn ($q) => $q->where('optional_services.category', 'transport')
                    ->where('optional_services.academic_year_id', $optionalService->academic_year_id))
                ->exists();
            if ($onTwo) {
                return back()->withErrors(['category' => 'Some students in this service already use another transport route. Move them to one route first.']);
            }
        }

        $optionalService->update($validated);

        return back()->with('success', 'Optional service updated. Invoices already generated keep their original amounts.');
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
     * sync without a separate step. A student has one transport route
     * at a time: see StudentServices::enroll().
     */
    public function enrollStudent(Request $request, Student $student): RedirectResponse
    {
        $request->user()->can('manage-invoices') || abort(403);
        $this->assertStudentInScope($request->user(), $student);

        $validated = $request->validate([
            'optional_service_id' => ['required', 'integer'],
            'custom_amount' => self::CUSTOM_AMOUNT_RULES,
        ]);

        $service = OptionalService::whereKey($validated['optional_service_id'])->firstOrFail();
        abort_unless($service->branch_id === $student->branch_id, 422);

        $custom = isset($validated['custom_amount']) ? (float) $validated['custom_amount'] : null;
        $result = StudentServices::enroll($request->user(), $student, $service, $custom);

        $redirect = back()->with('success', $result['replaced']
            ? "Transport changed from {$result['replaced'][0]} to {$service->name}."
            : "Enrolled in {$service->name}.");

        if ($result['already_billed']) {
            $redirect->with('warning', "{$result['already_billed'][0]} is already charged on this period's invoice and was not removed from it. Add a discount on that invoice if the family should not pay for both.");
        }

        return $redirect;
    }

    /** Sets or clears this student's own price for a service they are enrolled in. */
    public function updateStudentService(Request $request, Student $student, OptionalService $optionalService): RedirectResponse
    {
        $request->user()->can('manage-invoices') || abort(403);
        $this->assertStudentInScope($request->user(), $student);
        abort_unless($optionalService->branch_id === $student->branch_id, 404);

        $validated = $request->validate(['custom_amount' => self::CUSTOM_AMOUNT_RULES]);
        $custom = isset($validated['custom_amount']) ? (float) $validated['custom_amount'] : null;

        StudentServices::setCustomAmount($request->user(), $student, $optionalService, $custom);

        return back()->with('success', $custom === null
            ? "{$optionalService->name} now uses the standard price. Invoices already generated keep their original amounts."
            : "Price for this student set. Invoices already generated keep their original amounts.");
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
        AuditLog::record($request->user(), 'student.service_withdrawn', $student, ['service' => $optionalService->name], null);

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
