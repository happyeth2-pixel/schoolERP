<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The library catalog -- academic expansion, Phase 6. `library-view` reads, `library-manage` writes; an
 * auditor always reads. A book title is school-wide; its physical copies (managed here too, on the show
 * page) are branch-scoped -- see `App\Models\BookCopy`.
 */
class BookController extends Controller
{
    private function canView(User $user): bool
    {
        return $user->can('library-view') || $user->can('library-manage') || $user->hasRole('auditor');
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $search = $request->string('search')->toString();

        $books = Book::where('school_id', $user->school_id)
            ->when($search, fn ($q) => $q->where(fn ($q2) => $q2->where('title', 'like', "%{$search}%")->orWhere('author', 'like', "%{$search}%")))
            ->withCount('copies')
            ->withCount(['copies as available_count' => fn ($q) => $q->where('status', 'available')])
            ->orderBy('title')
            ->get()
            ->map(fn (Book $b) => [
                'id' => $b->id,
                'title' => $b->title,
                'author' => $b->author,
                'category' => $b->category,
                'copies_count' => $b->copies_count,
                'available_count' => $b->available_count,
            ]);

        return Inertia::render('Library/Books/Index', [
            'books' => $books,
            'search' => $search,
            'canManage' => $user->can('library-manage'),
        ]);
    }

    public function show(Request $request, Book $book): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);
        abort_unless($book->school_id === $user->school_id, 403);

        $branchIds = $user->isOrgWide() ? $user->school->branches()->pluck('id') : collect([$user->branch_id]);

        return Inertia::render('Library/Books/Show', [
            'book' => ['id' => $book->id, 'title' => $book->title, 'author' => $book->author, 'isbn' => $book->isbn, 'category' => $book->category],
            'copies' => $book->copies()->whereIn('branch_id', $branchIds)->with(['branch:id,name', 'activeLoan.borrower'])
                ->orderBy('copy_number')->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'copy_number' => $c->copy_number,
                    'status' => $c->status,
                    'branch_id' => $c->branch_id,
                    'branch_name' => $c->branch->name,
                    'active_loan' => $c->activeLoan->first() ? [
                        'id' => $c->activeLoan->first()->id,
                        'borrower_name' => $c->activeLoan->first()->borrowerName(),
                        'due_date' => $c->activeLoan->first()->due_date->toDateString(),
                    ] : null,
                ]),
            'branches' => $user->isOrgWide()
                ? $user->school->branches()->orderBy('name')->get(['id', 'name'])
                : $user->school->branches()->where('id', $user->branch_id)->get(['id', 'name']),
            'canManage' => $user->can('library-manage'),
            'canPostSchoolWide' => $user->isOrgWide(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->can('library-manage') || abort(403);

        $validated = $this->validated($request);
        $book = Book::create($validated + ['school_id' => $user->school_id, 'created_by' => $user->id]);

        return redirect()->route('library-books.show', $book)->with('success', 'Book added.');
    }

    public function update(Request $request, Book $book): RedirectResponse
    {
        $user = $request->user();
        $user->can('library-manage') || abort(403);
        abort_unless($book->school_id === $user->school_id, 403);

        $book->update($this->validated($request));

        return back()->with('success', 'Book updated.');
    }

    public function destroy(Request $request, Book $book): RedirectResponse
    {
        $user = $request->user();
        $user->can('library-manage') || abort(403);
        abort_unless($book->school_id === $user->school_id, 403);

        $book->delete();

        return redirect()->route('library-books.index')->with('success', 'Book removed.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'author' => ['nullable', 'string', 'max:255'],
            'isbn' => ['nullable', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:100'],
        ]);
    }
}
