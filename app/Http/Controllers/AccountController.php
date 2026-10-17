<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\User;
use App\Support\ChartOfAccountsTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    private function canView(User $user): bool
    {
        return $user->can('accounting-view') || $user->can('accounting-manage') || $user->hasRole('auditor');
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $accounts = Account::where('school_id', $user->school_id)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'normal_balance', 'parent_id', 'branch_id', 'is_header', 'is_system', 'is_active']);

        return Inertia::render('Accounting/Accounts/Index', [
            'accounts' => $accounts,
            'installed' => ChartOfAccountsTemplate::isInstalled($user->school),
            'canManage' => $user->can('accounting-manage'),
            'types' => Account::TYPES,
        ]);
    }

    public function installTemplate(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('accounting-manage'), 403);

        ChartOfAccountsTemplate::install($user->school);

        return back()->with('success', 'Default chart of accounts installed. Edit or add to it as needed.');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('accounting-manage'), 403);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('accounts')->where('school_id', $user->school_id)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(Account::TYPES)],
            'parent_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('school_id', $user->school_id)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('school_id', $user->school_id)],
            'is_header' => ['boolean'],
        ]);

        Account::create([
            'school_id' => $user->school_id,
            'branch_id' => $validated['branch_id'] ?? null,
            'parent_id' => $validated['parent_id'] ?? null,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'type' => $validated['type'],
            'is_header' => $validated['is_header'] ?? false,
        ]);

        return back()->with('success', 'Account added.');
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('accounting-manage'), 403);
        abort_unless($account->school_id === $user->school_id, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'different:id', Rule::exists('accounts', 'id')->where('school_id', $user->school_id)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('school_id', $user->school_id)],
            'is_active' => ['boolean'],
        ]);

        if (($validated['parent_id'] ?? null) === $account->id) {
            return back()->withErrors(['parent_id' => 'An account cannot be its own parent.']);
        }

        $account->update([
            'name' => $validated['name'],
            'parent_id' => $validated['parent_id'] ?? null,
            'branch_id' => $validated['branch_id'] ?? null,
            'is_active' => $validated['is_active'] ?? $account->is_active,
        ]);

        return back()->with('success', 'Account updated.');
    }

    public function destroy(Request $request, Account $account): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('accounting-manage'), 403);
        abort_unless($account->school_id === $user->school_id, 403);

        $account->delete();

        return back()->with('success', 'Account removed.');
    }
}
