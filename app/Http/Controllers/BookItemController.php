<?php

namespace App\Http\Controllers;

use App\Models\BookItem;
use App\Models\Student;
use App\Services\BookSales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The book catalog (manage-fee-structures, same permission as the rest of the fee/service catalog) and
 * selling specific books to a specific student (manage-invoices, same permission optional-service
 * enrollment already requires -- both are "this changes what a family owes").
 */
class BookItemController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $user->can('manage-fee-structures') || abort(403);

        $query = BookItem::whereIn('branch_id', $user->school->branches()->pluck('id'))
            ->with(['branch', 'grade', 'academicYear']);

        if (! $user->isOrgWide()) {
            $query->where('branch_id', $user->branch_id);
        }

        return Inertia::render('Books/Index', [
            'books' => $query->orderBy('grade_id')->orderBy('title')->get()->map(fn (BookItem $b) => [
                'id' => $b->id,
                'title' => $b->title,
                'size' => $b->size,
                'price' => $b->price,
                'branch_id' => $b->branch_id,
                'branch' => $b->branch->name,
                'grade_id' => $b->grade_id,
                'grade' => $b->grade->name,
                'academic_year_id' => $b->academic_year_id,
                'academic_year' => $b->academicYear->name,
                'sold_count' => $b->purchases()->count(),
            ]),
            'branches' => $user->isOrgWide()
                ? $user->school->branches()->orderBy('name')->get(['id', 'name'])
                : $user->school->branches()->where('id', $user->branch_id)->get(['id', 'name']),
            'grades' => $user->school->grades()->orderBy('sort_order')->get(['id', 'name']),
            'academicYears' => $user->school->academicYears()->orderByDesc('start_date')->get(['id', 'name', 'is_current']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->can('manage-fee-structures') || abort(403);

        $validated = $request->validate([
            'branch_id' => ['required', 'integer'],
            'grade_id' => ['required', 'integer'],
            'academic_year_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'size' => ['nullable', 'string', 'max:100'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ]);

        $this->assertBranchInScope($user, (int) $validated['branch_id']);
        abort_unless($user->school->grades()->whereKey($validated['grade_id'])->exists(), 403);
        abort_unless($user->school->academicYears()->whereKey($validated['academic_year_id'])->exists(), 403);

        BookItem::create($validated);

        return back()->with('success', 'Book added.');
    }

    public function update(Request $request, BookItem $book): RedirectResponse
    {
        $user = $request->user();
        $user->can('manage-fee-structures') || abort(403);
        $this->assertBranchInScope($user, $book->branch_id);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'size' => ['nullable', 'string', 'max:100'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ]);

        $book->update($validated);

        return back()->with('success', 'Book updated. Already-sold copies keep their original price.');
    }

    public function destroy(Request $request, BookItem $book): RedirectResponse
    {
        $user = $request->user();
        $user->can('manage-fee-structures') || abort(403);
        $this->assertBranchInScope($user, $book->branch_id);

        $book->delete();

        return back()->with('success', 'Book removed from the catalog.');
    }

    /** This student's current grade's books, each marked if already sold to them -- what "Sell books"
     *  on the student's profile shows. */
    public function catalogForStudent(Request $request, Student $student): Response|RedirectResponse
    {
        $user = $request->user();
        $user->can('manage-invoices') || abort(403);
        $this->assertStudentInScope($user, $student);

        $student->loadMissing('currentEnrollment.section.grade');
        $gradeId = $student->currentEnrollment?->section->grade_id;

        if (! $gradeId) {
            return back()->with('warning', "{$student->fullName()} has no class yet, so there is no book list to sell from.");
        }

        $owned = $student->bookPurchases()->pluck('book_item_id');

        return Inertia::render('Students/Books', [
            'student' => ['id' => $student->id, 'name' => $student->fullName(), 'grade' => $student->currentEnrollment->section->grade->name],
            'books' => BookItem::where('branch_id', $student->branch_id)
                ->where('grade_id', $gradeId)
                ->orderBy('title')
                ->get()
                ->map(fn (BookItem $b) => [
                    'id' => $b->id,
                    'title' => $b->title,
                    'size' => $b->size,
                    'price' => $b->price,
                    'already_owned' => $owned->contains($b->id),
                ]),
            'result' => session('book_sale_result'),
        ]);
    }

    public function sell(Request $request, Student $student): RedirectResponse
    {
        $user = $request->user();
        $user->can('manage-invoices') || abort(403);
        $this->assertStudentInScope($user, $student);

        $validated = $request->validate([
            'book_item_ids' => ['required', 'array', 'min:1', 'max:50'],
            'book_item_ids.*' => ['integer'],
        ]);

        $books = BookItem::where('branch_id', $student->branch_id)->whereIn('id', $validated['book_item_ids'])->get();
        if ($books->count() !== count($validated['book_item_ids'])) {
            throw ValidationException::withMessages(['book_item_ids' => 'One of the chosen books is not available for this student.']);
        }

        $result = BookSales::sell($user, $student, $books);
        $total = $books->sum('price');

        return back()->with('success', count($result['purchased']).' book(s) sold for '.number_format($total, 2).' ETB, added to invoice '.$result['invoice']->invoice_number.'.')
            ->with('book_sale_result', ['purchased' => $result['purchased'], 'invoice_id' => $result['invoice']->id, 'invoice_number' => $result['invoice']->invoice_number]);
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
