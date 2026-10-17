<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Loan period and (optional) overdue fine rate -- academic expansion, Phase 6. `library_fine_per_day`
 * starts NULL (fines OFF) and stays that way unless explicitly set here, per the brief's own words: "not
 * part of tuition unless explicitly configured."
 */
class LibrarySettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('library-view') || $user->can('library-manage') || $user->hasRole('auditor'), 403);

        return Inertia::render('Library/Settings', [
            'canManage' => $user->can('library-manage'),
            'loanDays' => $user->school->library_loan_days,
            'finePerDay' => $user->school->library_fine_per_day !== null ? (float) $user->school->library_fine_per_day : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->can('library-manage') || abort(403);

        $validated = $request->validate([
            'library_loan_days' => ['required', 'integer', 'min:1', 'max:120'],
            'fines_enabled' => ['required', 'boolean'],
            'library_fine_per_day' => ['required_if:fines_enabled,true', 'nullable', 'numeric', 'min:0', 'max:1000'],
        ]);

        $user->school->update([
            'library_loan_days' => $validated['library_loan_days'],
            'library_fine_per_day' => $validated['fines_enabled'] ? $validated['library_fine_per_day'] : null,
        ]);

        return back()->with('success', 'Library settings saved.');
    }
}
