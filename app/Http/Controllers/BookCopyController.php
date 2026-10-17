<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\BookCopy;
use App\Services\Library;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Adding/removing physical copies of a book, and marking a copy lost/damaged/restored OUTSIDE an active
 * loan (found that way on the shelf, never borrowed). A copy currently on loan is closed through
 * `LibraryLoanController` instead, since that also has a borrower and a loan record to settle.
 */
class BookCopyController extends Controller
{
    private function assertCanManage($user, Book $book): void
    {
        $user->can('library-manage') || abort(403);
        abort_unless($book->school_id === $user->school_id, 403);
    }

    public function store(Request $request, Book $book): RedirectResponse
    {
        $user = $request->user();
        $this->assertCanManage($user, $book);

        $branchIds = $user->school->branches()->pluck('id');
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', Rule::in($user->isOrgWide() ? $branchIds : [$user->branch_id])],
            'copy_number' => ['required', 'string', 'max:50', Rule::unique('book_copies')->where('book_id', $book->id)],
        ]);

        $book->copies()->create($validated + ['created_by' => $user->id]);

        return back()->with('success', 'Copy added.');
    }

    public function destroy(Request $request, Book $book, BookCopy $copy): RedirectResponse
    {
        $user = $request->user();
        $this->assertCanManage($user, $book);
        abort_unless($copy->book_id === $book->id, 404);

        $copy->delete();

        return back()->with('success', 'Copy removed.');
    }

    public function markLostOrDamaged(Request $request, Book $book, BookCopy $copy): RedirectResponse
    {
        $user = $request->user();
        $this->assertCanManage($user, $book);
        abort_unless($copy->book_id === $book->id, 404);

        $validated = $request->validate(['status' => ['required', Rule::in(['lost', 'damaged'])]]);
        Library::markCopyLostOrDamaged($copy, $validated['status']);

        return back()->with('success', 'Copy marked '.$validated['status'].'.');
    }

    public function restore(Request $request, Book $book, BookCopy $copy): RedirectResponse
    {
        $user = $request->user();
        $this->assertCanManage($user, $book);
        abort_unless($copy->book_id === $book->id, 404);

        Library::restoreCopy($copy);

        return back()->with('success', 'Copy back on the shelf.');
    }
}
