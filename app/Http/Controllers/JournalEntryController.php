<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class JournalEntryController extends Controller
{
    private function canView(User $user): bool
    {
        return $user->can('accounting-view') || $user->can('accounting-manage') || $user->hasRole('auditor');
    }

    private function linesRules(User $user): array
    {
        return [
            'entry_date' => ['required', 'date'],
            'journal_id' => ['required', 'integer', Rule::exists('journals', 'id')->where('school_id', $user->school_id)],
            'reference' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('school_id', $user->school_id)],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('school_id', $user->school_id)],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $entries = JournalEntry::where('school_id', $user->school_id)
            ->with(['journal:id,code,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (JournalEntry $e) => [
                'id' => $e->id,
                'entry_number' => $e->entry_number,
                'entry_date' => $e->entry_date->toDateString(),
                'journal' => $e->journal->name,
                'description' => $e->description,
                'status' => $e->status,
                'is_reversed' => $e->isReversed(),
                'total' => $e->totalDebit(),
            ]);

        return Inertia::render('Accounting/JournalEntries/Index', [
            'entries' => $entries,
            'status' => $request->query('status', ''),
            'canManage' => $user->can('accounting-manage'),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('accounting-manage'), 403);

        return Inertia::render('Accounting/JournalEntries/Create', [
            'accounts' => Account::where('school_id', $user->school_id)->where('is_active', true)->where('is_header', false)->orderBy('code')->get(['id', 'code', 'name']),
            'journals' => Journal::where('school_id', $user->school_id)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'entry' => null,
        ]);
    }

    public function edit(Request $request, JournalEntry $journalEntry): Response
    {
        $user = $request->user();
        abort_unless($user->can('accounting-manage'), 403);
        abort_unless($journalEntry->school_id === $user->school_id, 403);
        abort_unless($journalEntry->status === 'draft', 422);

        $journalEntry->load('lines');

        return Inertia::render('Accounting/JournalEntries/Create', [
            'accounts' => Account::where('school_id', $user->school_id)->where('is_active', true)->where('is_header', false)->orderBy('code')->get(['id', 'code', 'name']),
            'journals' => Journal::where('school_id', $user->school_id)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'entry' => [
                'id' => $journalEntry->id,
                'entry_number' => $journalEntry->entry_number,
                'journal_id' => $journalEntry->journal_id,
                'entry_date' => $journalEntry->entry_date->toDateString(),
                'reference' => $journalEntry->reference,
                'description' => $journalEntry->description,
                'lines' => $journalEntry->lines->map(fn ($l) => [
                    'account_id' => $l->account_id,
                    'debit' => $l->debit > 0 ? (string) $l->debit : '',
                    'credit' => $l->credit > 0 ? (string) $l->credit : '',
                    'description' => $l->description,
                ]),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('accounting-manage'), 403);

        $validated = $request->validate($this->linesRules($user));

        $entry = Ledger::createDraft($user->school, [
            'branch_id' => $validated['branch_id'] ?? null,
            'journal_id' => $validated['journal_id'],
            'entry_date' => $validated['entry_date'],
            'reference' => $validated['reference'] ?? null,
            'description' => $validated['description'] ?? null,
            'source_module' => 'manual',
        ], $validated['lines'], $user);

        return redirect()->route('journal-entries.show', $entry)->with('success', "Journal entry {$entry->entry_number} saved as a draft.");
    }

    public function show(Request $request, JournalEntry $journalEntry): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);
        abort_unless($journalEntry->school_id === $user->school_id, 403);

        $journalEntry->load(['journal:id,code,name', 'accountingPeriod:id,name,status', 'creator:id,name', 'poster:id,name', 'lines.account:id,code,name', 'reverses:id,entry_number', 'reversedBy:id,entry_number']);

        return Inertia::render('Accounting/JournalEntries/Show', [
            'entry' => [
                'id' => $journalEntry->id,
                'entry_number' => $journalEntry->entry_number,
                'entry_date' => $journalEntry->entry_date->toDateString(),
                'journal' => $journalEntry->journal->name,
                'reference' => $journalEntry->reference,
                'description' => $journalEntry->description,
                'status' => $journalEntry->status,
                'source_module' => $journalEntry->source_module,
                'period' => ['name' => $journalEntry->accountingPeriod->name, 'status' => $journalEntry->accountingPeriod->status],
                'created_by' => $journalEntry->creator->name,
                'posted_by' => $journalEntry->poster?->name,
                'posted_at' => $journalEntry->posted_at?->toDateTimeString(),
                'reverses' => $journalEntry->reverses ? ['id' => $journalEntry->reverses->id, 'entry_number' => $journalEntry->reverses->entry_number] : null,
                'reversed_by' => $journalEntry->reversedBy ? ['id' => $journalEntry->reversedBy->id, 'entry_number' => $journalEntry->reversedBy->entry_number] : null,
                'total_debit' => $journalEntry->totalDebit(),
                'total_credit' => $journalEntry->totalCredit(),
                'lines' => $journalEntry->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'account' => "{$l->account->code} {$l->account->name}",
                    'debit' => $l->debit,
                    'credit' => $l->credit,
                    'description' => $l->description,
                ]),
            ],
            'canManage' => $user->can('accounting-manage'),
        ]);
    }

    public function update(Request $request, JournalEntry $journalEntry): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('accounting-manage'), 403);
        abort_unless($journalEntry->school_id === $user->school_id, 403);
        abort_unless($journalEntry->status === 'draft', 422);

        $validated = $request->validate($this->linesRules($user));

        $journalEntry->update([
            'branch_id' => $validated['branch_id'] ?? null,
            'journal_id' => $validated['journal_id'],
            'entry_date' => $validated['entry_date'],
            'reference' => $validated['reference'] ?? null,
            'description' => $validated['description'] ?? null,
        ]);
        Ledger::replaceLines($journalEntry, $validated['lines']);

        return back()->with('success', 'Draft journal entry updated.');
    }

    public function post(Request $request, JournalEntry $journalEntry): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('accounting-manage'), 403);
        abort_unless($journalEntry->school_id === $user->school_id, 403);

        Ledger::post($journalEntry, $user);

        return back()->with('success', "Journal entry {$journalEntry->entry_number} posted.");
    }

    public function reverse(Request $request, JournalEntry $journalEntry): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('accounting-manage'), 403);
        abort_unless($journalEntry->school_id === $user->school_id, 403);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $reversal = Ledger::reverse($journalEntry, $user, $validated['reason'] ?? null);

        return redirect()->route('journal-entries.show', $reversal)->with('success', "Reversing entry {$reversal->entry_number} posted.");
    }

    public function destroy(Request $request, JournalEntry $journalEntry): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('accounting-manage'), 403);
        abort_unless($journalEntry->school_id === $user->school_id, 403);
        abort_unless($journalEntry->status === 'draft', 422);

        $journalEntry->delete();

        return redirect()->route('journal-entries.index')->with('success', 'Draft journal entry removed.');
    }
}
