<?php

namespace App\Http\Controllers;

use App\Models\Journal;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class JournalController extends Controller
{
    private function canView(User $user): bool
    {
        return $user->can('accounting-view') || $user->can('accounting-manage') || $user->hasRole('auditor');
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        return Inertia::render('Accounting/Journals/Index', [
            'journals' => Journal::where('school_id', $user->school_id)->orderBy('code')->get(),
            'canManage' => $user->can('accounting-manage'),
            'types' => Journal::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('accounting-manage'), 403);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('journals')->where('school_id', $user->school_id)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(Journal::TYPES)],
        ]);

        Journal::create([
            'school_id' => $user->school_id,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'type' => $validated['type'],
        ]);

        return back()->with('success', 'Journal added.');
    }

    public function update(Request $request, Journal $journal): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('accounting-manage'), 403);
        abort_unless($journal->school_id === $user->school_id, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $journal->update(['name' => $validated['name'], 'is_active' => $validated['is_active'] ?? $journal->is_active]);

        return back()->with('success', 'Journal updated.');
    }
}
