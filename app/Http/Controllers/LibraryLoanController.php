<?php

namespace App\Http\Controllers;

use App\Models\BookCopy;
use App\Models\LibraryLoan;
use App\Models\Student;
use App\Models\User;
use App\Services\Library;
use App\Support\SchoolCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The librarian's day-to-day: active and overdue loans, issuing a copy, returning/renewing, and settling a
 * self-contained fine -- never an Invoice. Academic expansion, Phase 6.
 */
class LibraryLoanController extends Controller
{
    private function canView(User $user): bool
    {
        return $user->can('library-view') || $user->can('library-manage') || $user->hasRole('auditor');
    }

    private function assertInScope(User $user, LibraryLoan $loan): void
    {
        $loan->load('bookCopy');
        abort_unless($loan->bookCopy->book->school_id === $user->school_id, 403);
        abort_unless($user->isOrgWide() || $loan->bookCopy->branch_id === $user->branch_id, 403);
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $branchIds = $user->isOrgWide() ? $user->school->branches()->pluck('id') : collect([$user->branch_id]);
        $today = SchoolCalendar::today()->toDateString();

        $loans = LibraryLoan::where('status', 'active')
            ->whereHas('bookCopy', fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->with(['bookCopy.book', 'bookCopy.branch', 'borrower'])
            ->orderBy('due_date')
            ->get()
            ->map(fn (LibraryLoan $l) => [
                'id' => $l->id,
                'book_title' => $l->bookCopy->book->title,
                'copy_number' => $l->bookCopy->copy_number,
                'branch_name' => $l->bookCopy->branch->name,
                'borrower_name' => $l->borrowerName(),
                'borrower_type' => class_basename($l->borrower_type),
                'issued_at' => $l->issued_at->toDateString(),
                'due_date' => $l->due_date->toDateString(),
                'overdue' => $l->isOverdue(),
                'renewed_count' => $l->renewed_count,
                'estimated_fine' => Library::calculateFine($l, $today),
            ]);

        $unresolvedFines = LibraryLoan::whereIn('status', ['returned', 'lost', 'damaged'])
            ->whereNotNull('fine_amount')
            ->whereNull('fine_paid_at')
            ->whereNull('fine_waived_at')
            ->whereHas('bookCopy', fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->with(['bookCopy.book', 'borrower'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (LibraryLoan $l) => [
                'id' => $l->id,
                'book_title' => $l->bookCopy->book->title,
                'borrower_name' => $l->borrowerName(),
                'status' => $l->status,
                'fine_amount' => (float) $l->fine_amount,
            ]);

        return Inertia::render('Library/Index', [
            'loans' => $loans,
            'unresolvedFines' => $unresolvedFines,
            'today' => $today,
            'canManage' => $user->can('library-manage'),
            'fineConfigured' => $user->school->library_fine_per_day !== null,
        ]);
    }

    /** Self-service: a staff borrower's own loan history (students have no login to view this themselves). */
    public function mine(Request $request): Response
    {
        $user = $request->user();

        $loans = $user->libraryLoans()->with('bookCopy.book')
            ->orderByDesc('issued_at')
            ->get()
            ->map(fn (LibraryLoan $l) => [
                'id' => $l->id,
                'book_title' => $l->bookCopy->book->title,
                'status' => $l->status,
                'issued_at' => $l->issued_at->toDateString(),
                'due_date' => $l->due_date->toDateString(),
                'returned_at' => $l->returned_at?->toDateString(),
                'overdue' => $l->isOverdue(),
                'fine_amount' => $l->fine_amount !== null ? (float) $l->fine_amount : null,
                'fine_resolved' => $l->fine_paid_at !== null || $l->fine_waived_at !== null,
            ]);

        return Inertia::render('Library/Mine', ['loans' => $loans]);
    }

    /** Students (no login) and staff (User) both searchable, for picking who a copy is issued to. */
    public function borrowerSearch(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $term = $request->string('q')->toString();
        abort_unless(strlen($term) >= 2, 422);

        $branchIds = $user->isOrgWide() ? $user->school->branches()->pluck('id') : collect([$user->branch_id]);

        $students = Student::whereIn('branch_id', $branchIds)
            ->where(fn ($q) => $q->where('first_name', 'like', "%{$term}%")->orWhere('father_name', 'like', "%{$term}%"))
            ->whereIn('status', Student::ENROLLED_STATUSES)
            ->with('currentEnrollment.section.grade')
            ->limit(10)->get()
            ->map(fn (Student $s) => [
                'type' => Student::class, 'id' => $s->id, 'name' => $s->fullName(),
                'detail' => $s->currentEnrollment ? "{$s->currentEnrollment->section->grade->name} {$s->currentEnrollment->section->name}" : null,
            ]);

        $staff = User::where('school_id', $user->school_id)
            ->whereIn('branch_id', $branchIds)
            ->where('status', 'active')
            ->where('name', 'like', "%{$term}%")
            ->limit(10)->get()
            ->map(fn (User $u) => ['type' => User::class, 'id' => $u->id, 'name' => $u->name, 'detail' => $u->title]);

        // ->map() on an Eloquent Collection still returns an EloquentCollection (even once every item is a
        // plain array), and ITS merge() calls $item->getKey() expecting real models -- ->toBase() drops back
        // to a plain Support Collection first, where merge() just concatenates arrays/values as expected.
        return response()->json($students->toBase()->merge($staff->toBase())->values());
    }

    public function issue(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->can('library-manage') || abort(403);

        $validated = $request->validate([
            'book_copy_id' => ['required', 'integer'],
            'borrower_type' => ['required', Rule::in([Student::class, User::class])],
            'borrower_id' => ['required', 'integer'],
        ]);

        $branchIds = $user->isOrgWide() ? $user->school->branches()->pluck('id') : collect([$user->branch_id]);
        $copy = BookCopy::whereIn('branch_id', $branchIds)->with('book')->findOrFail($validated['book_copy_id']);
        abort_unless($copy->book->school_id === $user->school_id, 403);

        $borrower = $validated['borrower_type']::whereIn('branch_id', $branchIds)->find($validated['borrower_id']);
        if (! $borrower) {
            throw ValidationException::withMessages(['borrower_id' => 'Could not find that person in your branch.']);
        }

        Library::issue($copy, $borrower, $user);

        return back()->with('success', 'Book issued.');
    }

    public function returnLoan(Request $request, LibraryLoan $loan): RedirectResponse
    {
        $user = $request->user();
        $user->can('library-manage') || abort(403);
        $this->assertInScope($user, $loan);

        Library::returnCopy($loan);

        return back()->with('success', 'Book returned.');
    }

    public function renew(Request $request, LibraryLoan $loan): RedirectResponse
    {
        $user = $request->user();
        $user->can('library-manage') || abort(403);
        $this->assertInScope($user, $loan);

        Library::renew($loan);

        return back()->with('success', 'Loan renewed.');
    }

    public function markLostOrDamaged(Request $request, LibraryLoan $loan): RedirectResponse
    {
        $user = $request->user();
        $user->can('library-manage') || abort(403);
        $this->assertInScope($user, $loan);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['lost', 'damaged'])],
            'fine_amount' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        Library::markLoanLostOrDamaged($loan, $validated['status'], $validated['fine_amount'] ?? null, $validated['reason']);

        return back()->with('success', 'Loan closed as '.$validated['status'].'.');
    }

    public function waiveFine(Request $request, LibraryLoan $loan): RedirectResponse
    {
        $user = $request->user();
        $user->can('library-manage') || abort(403);
        $this->assertInScope($user, $loan);

        Library::waiveFine($loan);

        return back()->with('success', 'Fine waived.');
    }

    public function payFine(Request $request, LibraryLoan $loan): RedirectResponse
    {
        $user = $request->user();
        $user->can('library-manage') || abort(403);
        $this->assertInScope($user, $loan);

        Library::payFine($loan);

        return back()->with('success', 'Fine recorded as paid.');
    }
}
