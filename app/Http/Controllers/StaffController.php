<?php

namespace App\Http\Controllers;

use App\Exports\SimpleArrayExport;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Manages staff accounts: owner, branch_admin, teacher, accountant,
 * auditor. Parent/Student accounts are created through enrollment
 * (Phase 2), not here.
 *
 * The core rule enforced throughout: nobody can grant an account more
 * permissions than they themselves hold, and nobody below Owner can
 * create an Owner or Auditor account. This is what keeps the
 * "Branch Admin tier with configurable titles" model from being a
 * privilege-escalation hole.
 */
class StaffController extends Controller
{
    private const STAFF_ROLES = ['owner', 'branch_admin', 'teacher', 'accountant', 'auditor'];

    public function index(Request $request): Response
    {
        $request->user()->canViewOrManage('manage-staff') || abort(403);
        $user = $request->user();

        $query = User::query()
            ->where('school_id', $user->school_id)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES))
            ->with(['roles', 'branch']);

        if (! $user->isOrgWide()) {
            $query->where('branch_id', $user->branch_id);
        }

        $staff = $query->orderBy('name')->get()->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'title' => $u->title,
            'status' => $u->status,
            'role' => $u->getRoleNames()->first(),
            'branch' => $u->branch?->name,
            'permissions' => $u->getAllPermissions()->pluck('name'),
        ]);

        return Inertia::render('Staff/Index', [
            'staff' => $staff,
            'assignableRoles' => $this->assignableRoles($user),
            'availablePermissions' => RolesAndPermissionsSeeder::PERMISSIONS,
            'myPermissions' => $user->getAllPermissions()->pluck('name'),
            'canManage' => $user->can('manage-staff'),
            'branches' => $user->isOrgWide()
                ? $user->school->branches()->orderBy('name')->get(['id', 'name'])
                : $user->school->branches()->where('id', $user->branch_id)->get(['id', 'name']),
        ]);
    }

    public function export(Request $request)
    {
        $request->user()->canViewOrManage('manage-staff') || abort(403);
        $user = $request->user();

        $query = User::query()
            ->where('school_id', $user->school_id)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES))
            ->with(['roles', 'branch']);

        if (! $user->isOrgWide()) {
            $query->where('branch_id', $user->branch_id);
        }

        $rows = [
            [$user->school->name],
            ['Staff List — '.now()->format('M j, Y')],
            ['Name', 'Email', 'Title', 'Tier', 'Branch', 'Status'],
        ];

        foreach ($query->orderBy('name')->get() as $u) {
            $rows[] = [
                $u->name,
                $u->email,
                $u->title,
                $u->getRoleNames()->first(),
                $u->branch?->name ?? 'Org-wide',
                $u->status,
            ];
        }

        return Excel::download(new SimpleArrayExport($rows, 'Staff'), 'staff.xlsx');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('manage-staff') || abort(403);
        $actor = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'title' => ['nullable', 'string', 'max:255'],
            'role' => ['required', Rule::in($this->assignableRoles($actor))],
            'branch_id' => ['nullable', 'integer'],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ]);

        $branchId = $this->resolveBranchId($actor, $validated['role'], $validated['branch_id'] ?? null);

        $user = User::create([
            'school_id' => $actor->school_id,
            'branch_id' => $branchId,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make(str()->random(24)),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->assignTier($validated['role'], $validated['title'] ?? null);

        // Only the branch_admin tier supports per-account permission
        // customization (that's the whole point of titles like Vice
        // Principal vs Registrar). Every other tier keeps the fixed
        // default set assignTier() just applied — overwriting it here
        // with an empty/unsent `permissions` field would silently wipe
        // out a Teacher's or Accountant's actual capabilities.
        if ($validated['role'] === 'branch_admin') {
            $user->syncPermissions($this->clampPermissions($actor, $validated['permissions'] ?? []));
        }

        return back()->with('success', "{$user->name} added. They should use 'Forgot password' to set their own password.");
    }

    public function update(Request $request, User $staff): RedirectResponse
    {
        $request->user()->can('manage-staff') || abort(403);
        $actor = $request->user();
        $this->assertManageable($actor, $staff);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive'],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ]);

        $staff->update([
            'title' => $validated['title'],
            'status' => $validated['status'],
        ]);

        if ($staff->hasRole('branch_admin')) {
            $staff->syncPermissions($this->clampPermissions($actor, $validated['permissions'] ?? []));
        }

        return back()->with('success', "{$staff->name}'s account updated.");
    }

    private function assignableRoles(User $actor): array
    {
        return $actor->hasRole('owner')
            ? self::STAFF_ROLES
            : ['branch_admin', 'teacher', 'accountant'];
    }

    private function resolveBranchId(User $actor, string $role, ?int $requestedBranchId): ?int
    {
        if (in_array($role, ['owner', 'auditor'], true)) {
            return null;
        }

        if (! $actor->isOrgWide()) {
            return $actor->branch_id;
        }

        $branch = $actor->school->branches()->whereKey($requestedBranchId)->first();
        abort_unless($branch, 422, 'Choose a valid branch.');

        return $branch->id;
    }

    /**
     * Nobody can hand out a permission they don't hold themselves.
     */
    private function clampPermissions(User $actor, array $requested): array
    {
        $ownPermissions = $actor->getAllPermissions()->pluck('name')->all();

        return array_values(array_intersect($requested, $ownPermissions, RolesAndPermissionsSeeder::PERMISSIONS));
    }

    private function assertManageable(User $actor, User $target): void
    {
        abort_unless($target->school_id === $actor->school_id, 403);
        abort_unless(in_array($target->getRoleNames()->first(), self::STAFF_ROLES, true), 403);

        if (! $actor->isOrgWide()) {
            abort_unless($target->branch_id === $actor->branch_id, 403);
        }

        if ($target->hasRole('owner')) {
            abort_unless($actor->hasRole('owner'), 403);
        }
    }
}
