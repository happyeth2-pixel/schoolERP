<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Exports\SimpleArrayExport;
use App\Models\AccountInvitation;
use App\Models\User;
use App\Rules\PhoneNumberRule;
use App\Models\SecurityEvent;
use App\Rules\EthiopianMobileRule;
use App\Rules\NotBasedOnIdentity;
use App\Security\LoginThrottle;
use App\Rules\UniqueAccountPhone;
use App\Support\PhoneNumber;
use App\Support\TemporaryPassword;
use App\Http\Controllers\Concerns\PaginatesLists;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Support\SchoolCalendar;
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
    use PaginatesLists;

    private const STAFF_ROLES = ['owner', 'branch_admin', 'teacher', 'accountant', 'auditor'];

    /**
     * Shared scope + search for both the on-screen list and the Excel export, so a search typed on screen and
     * the spreadsheet downloaded right after it always describe the same people. `search` matches name, email,
     * title, the role's own (English) slug, and a phone number however it was typed -- the same fields the page
     * used to filter over client-side, now resolved by the database instead of in a fully-loaded array.
     */
    private function staffQuery(Request $request, $user): Builder
    {
        $query = User::query()
            ->where('school_id', $user->school_id)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES));

        if (! $user->isOrgWide()) {
            $query->where('branch_id', $user->branch_id);
        }

        if ($term = trim((string) $request->query('search', ''))) {
            $like = '%'.$term.'%';
            $phoneKey = PhoneNumber::searchKey($term);
            $query->where(function ($w) use ($like, $phoneKey) {
                $w->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhereHas('roles', fn ($r) => $r->where('name', 'like', $like));
                if (strlen($phoneKey) >= 3) {
                    $w->orWhere('phone', 'like', '%'.$phoneKey.'%');
                }
            });
        }

        return $query;
    }

    public function index(Request $request): Response
    {
        $request->user()->canViewOrManage('manage-staff') || abort(403);
        $user = $request->user();

        $paginator = $this->staffQuery($request, $user)
            // 'roles.permissions' and 'permissions' (direct) are what getAllPermissions() below reads for each
            // row -- eager-loaded so this is a handful of queries for the whole page, not one or more per person
            ->with(['roles.permissions', 'permissions', 'branch'])
            ->orderBy('name')
            ->paginate($this->perPage($request))
            ->withQueryString();

        // the throttle status is now only ever looked up for the people on this one page, not every staff member
        $throttle = app(LoginThrottle::class);

        $staff = $paginator->getCollection()->map(fn (User $u) => [
            'id' => $u->id,
            'must_change_password' => (bool) $u->must_change_password,
            'can_sign_in_with_phone' => $u->login_phone !== null,
            'sign_in_lock' => $throttle->status($u),
            'name' => $u->name,
            'photo_url' => $u->profile_photo_url,
            'email' => $u->email,
            'title' => $u->title,
            'phone' => \App\Support\PhoneNumber::display($u->phone),
            'status' => $u->status,
            'last_login_at' => $u->last_login_at?->diffForHumans(),
            'role' => $u->getRoleNames()->first(),
            'branch' => $u->branch?->name,
            'permissions' => $u->getAllPermissions()->pluck('name'),
        ])->values();

        return Inertia::render('Staff/Index', [
            'staff' => $staff,
            'staffMeta' => $this->paginationMeta($paginator),
            'search' => $request->query('search', ''),
            'canManage' => $user->can('manage-staff'),
            'canView' => $user->canViewOrManage('manage-staff'),
            ...$this->accessProps($user),
        ]);
    }

    /** "Add a staff member" as three short steps: who they are, what they may do, then a check and the invitation. */
    public function create(Request $request): Response
    {
        $request->user()->can('manage-staff') || abort(403);

        return Inertia::render('Staff/Wizard', $this->accessProps($request->user()));
    }

    /** What the screens need to explain roles and permissions -- and to stop at what THIS person may give out. */
    private function accessProps(User $user): array
    {
        $canAssignClass = $user->can('manage-classes');

        return [
            'canAssignClass' => $canAssignClass,
            'classesWithoutTeacher' => $canAssignClass ? $this->classesWithoutTeacher($user) : [],
            'assignableRoles' => $this->assignableRoles($user),
            'availablePermissions' => RolesAndPermissionsSeeder::PERMISSIONS,
            'permissionGroups' => RolesAndPermissionsSeeder::PERMISSION_GROUPS,
            'tierDefaults' => collect(self::STAFF_ROLES)->mapWithKeys(fn ($r) => [$r => RolesAndPermissionsSeeder::DEFAULT_PERMISSIONS[$r]])->all(),
            'myPermissions' => $user->getAllPermissions()->pluck('name'),
            'branches' => $user->isOrgWide()
                ? $user->school->branches()->orderBy('name')->get(['id', 'name'])
                : $user->school->branches()->where('id', $user->branch_id)->get(['id', 'name']),
        ];
    }

    /** Sections of the current school year, in the actor's scope, that have no class teacher yet. */
    private function classesWithoutTeacher(User $actor): array
    {
        return \App\Models\Section::query()
            ->whereIn('branch_id', $actor->school->branches()->pluck('id'))
            ->when(! $actor->isOrgWide(), fn ($q) => $q->where('branch_id', $actor->branch_id))
            ->whereNull('class_teacher_id')
            ->whereHas('academicYear', fn ($q) => $q->where('is_current', true))
            ->with(['grade', 'branch'])
            ->get()
            ->sortBy(fn ($s) => [$s->grade->sort_order, $s->name])
            ->map(fn ($s) => ['id' => $s->id, 'branch_id' => $s->branch_id, 'label' => trim("{$s->grade->name} {$s->name}")])
            ->values()
            ->all();
    }

    /** Always the complete filtered set (same search as the screen), never limited to one page. */
    public function export(Request $request)
    {
        $request->user()->canViewOrManage('manage-staff') || abort(403);
        $user = $request->user();

        $query = $this->staffQuery($request, $user)->with(['roles', 'branch']);

        $rows = [
            [$user->school->name],
            ['Staff List — '.SchoolCalendar::date(SchoolCalendar::today(), $user)],
            ['Name', 'Phone', 'Email', 'Title', 'Tier', 'Branch', 'Status'],
        ];

        foreach ($query->orderBy('name')->get() as $u) {
            $rows[] = [
                $u->name,
                PhoneNumber::display($u->phone),
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
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'title' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50', new EthiopianMobileRule, new UniqueAccountPhone],
            'temporary_password' => ['nullable', 'string', 'max:72', \Illuminate\Validation\Rules\Password::min(8)->letters()->mixedCase()->numbers(), new NotBasedOnIdentity($request->input('name'), $request->input('phone'))],
            'class_teacher_section_id' => ['nullable', 'integer'],
            'role' => ['required', Rule::in($this->assignableRoles($actor))],
            'branch_id' => ['nullable', 'integer'],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ]);

        $branchId = $this->resolveBranchId($actor, $validated['role'], $validated['branch_id'] ?? null);
        $section = $this->classForNewTeacher($actor, $validated, $branchId);

        // The administrator hands the person a temporary password (made here, or typed by the administrator; shown
        // once, below); it must be replaced at the first sign-in. The phone number is what they sign in with.
        $temporary = $validated['temporary_password'] ?? TemporaryPassword::make();

        try {
            $user = DB::transaction(function () use ($actor, $branchId, $validated, $temporary, $section) {
                $user = User::create([
                    'school_id' => $actor->school_id,
                    'branch_id' => $branchId,
                    'name' => $validated['name'],
                    'email' => $validated['email'] ?? null,
                    'phone' => $validated['phone'],
                    'login_phone' => $validated['phone'],
                    'password' => $temporary,
                    'must_change_password' => true,
                    'status' => 'active',
                    'email_verified_at' => ($validated['email'] ?? null) ? now() : null,
                ]);

                $user->assignTier($validated['role'], $validated['title'] ?? null);

                // Only the branch_admin tier supports per-account permission customization (that's the whole point of
                // titles like Vice Principal vs Registrar). Every other tier keeps the fixed default set assignTier()
                // just applied -- overwriting it with an empty/unsent `permissions` field would silently wipe out a
                // Teacher's or Accountant's actual capabilities.
                if ($validated['role'] === 'branch_admin') {
                    $user->syncPermissions($this->clampPermissions($actor, $validated['permissions'] ?? []));
                }

                if ($section) {
                    $section->update(['class_teacher_id' => $user->id]);
                }

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // two people adding the same number at the same instant: the database key let only one through
            throw ValidationException::withMessages(['phone' => 'This phone number already belongs to another account. Check the number, or find that person in the staff or parent list.']);
        }

        AuditLog::record($actor, 'staff.role_assigned', $user, null, [
            'role' => $validated['role'],
            'title' => $validated['title'] ?? null,
            'branch_id' => $branchId,
            'permissions' => $user->getPermissionNames()->sort()->values()->all(),
            'class_teacher_of' => $section ? trim($section->grade->name.' '.$section->name) : null,
        ]);

        AuditLog::record($actor, 'account.temporary_password_issued', $user, null, ['must_change_password' => true, 'chosen_by_administrator' => isset($validated['temporary_password'])], 'Temporary password handed over by the administrator');
        TemporaryPassword::handOver($user, $temporary, ['class' => $section ? trim($section->grade->name.' '.$section->name) : null]);

        return back()->with('success', "{$user->name} added. Give them the sign-in details below.");
    }

    /**
     * The class a new teacher is made class teacher of, if one was chosen: only for a teacher, only by someone who may
     * manage classes, only a class of the current school year in the teacher's own branch that has no class teacher yet
     * (this never silently replaces someone).
     */
    private function classForNewTeacher(User $actor, array $validated, ?int $branchId): ?\App\Models\Section
    {
        if (empty($validated['class_teacher_section_id'])) {
            return null;
        }

        $fail = fn (string $why) => throw ValidationException::withMessages(['class_teacher_section_id' => $why]);

        if ($validated['role'] !== 'teacher') {
            $fail('Only a teacher can be a class teacher.');
        }
        $actor->can('manage-classes') || $fail('You are not allowed to choose classes.');

        $section = \App\Models\Section::with(['grade', 'academicYear'])->find($validated['class_teacher_section_id']);
        if (! $section || $section->branch_id !== $branchId || ! $actor->school->branches()->whereKey($section->branch_id)->exists()
            || (! $actor->isOrgWide() && $section->branch_id !== $actor->branch_id) || ! $section->academicYear?->is_current) {
            $fail('Choose a class of this school year in the teacher\'s branch.');
        }
        if ($section->class_teacher_id !== null) {
            $fail('That class already has a class teacher. Change it under Who teaches what.');
        }

        return $section;
    }

    /** Switches an account off. Nothing is deleted: everything the person ever did stays attributed to them. */
    public function deactivate(Request $request, User $staff): RedirectResponse
    {
        $request->user()->can('manage-staff') || abort(403);
        $actor = $request->user();
        $this->assertManageable($actor, $staff);

        if ($staff->id === $actor->id) {
            return back()->withErrors(['status' => 'You cannot switch off your own account.']);
        }

        if ($staff->status === 'inactive') {
            return back()->with('success', "{$staff->name}'s account is already switched off.");
        }

        $this->signOutEverywhere($staff->forceFill(['status' => 'inactive']));

        SecurityEvent::log('account_disabled', $staff, $staff->login_phone ?? $staff->email, $actor);

        return back()->with('success', "{$staff->name}'s account is switched off. They can no longer sign in. Everything they did is kept.");
    }

    /** Saves the account as switched off and ends every open sign-in (EnsureAccountIsActive refuses any that remain). */
    private function signOutEverywhere(User $staff): void
    {
        $staff->forceFill(['remember_token' => str()->random(60)])->save();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $staff->id)->delete();
        }
    }

    /**
     * Switches an account back on. By default their existing password still works; the administrator can instead ask
     * for a fresh temporary password (e.g. the account was off for a security reason), shown once exactly like a
     * normal reset, in which case the person must choose a new password again at their next sign-in.
     */
    public function reactivate(Request $request, User $staff): RedirectResponse
    {
        $request->user()->can('manage-staff') || abort(403);
        $actor = $request->user();
        $this->assertManageable($actor, $staff);

        if ($staff->status === 'active') {
            return back()->with('success', "{$staff->name}'s account is already on.");
        }

        $staff->update(['status' => 'active']);
        app(LoginThrottle::class)->clear($staff);
        SecurityEvent::log('account_enabled', $staff, $staff->login_phone ?? $staff->email, $actor);

        if ($request->boolean('reset_password')) {
            $temporary = TemporaryPassword::make();
            $staff->forceMustChangePassword = true;
            $staff->forceFill(['password' => $temporary, 'must_change_password' => true, 'remember_token' => str()->random(60)])->save();
            AuditLog::record($actor, 'staff.password_reset', $staff, null, ['must_change_password' => true], 'Temporary password handed over by the administrator');
            TemporaryPassword::handOver($staff, $temporary, ['reset' => true]);

            return back()->with('success', "{$staff->name}'s account is switched on again. A new temporary password is ready below.");
        }

        return back()->with('success', "{$staff->name}'s account is switched on again. They can sign in with their existing password.");
    }

    /**
     * Gives someone a new temporary password (when they forgot theirs, or a phone was lost). Their old password stops
     * working at once, every place they are signed in is signed out, and they must choose their own at the next
     * sign-in. The administrator sees the new temporary password once -- never the old one, which nobody can read.
     */
    public function resetPassword(Request $request, User $staff): RedirectResponse
    {
        $request->user()->can('manage-staff') || abort(403);
        $actor = $request->user();
        $this->assertManageable($actor, $staff);

        if ($staff->id === $actor->id) {
            return back()->withErrors(['reset' => 'You cannot reset your own password here. Use Profile to change it, or ask another administrator.']);
        }

        if (! $staff->login_phone && ! $staff->email) {
            return back()->withErrors(['reset' => "{$staff->name} has no phone number to sign in with. Add one under Change first."]);
        }

        $temporary = TemporaryPassword::make();
        $staff->forceMustChangePassword = true;
            $staff->forceFill(['password' => $temporary, 'must_change_password' => true, 'remember_token' => str()->random(60)])->save();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $staff->id)->delete();
        }

        AuditLog::record($actor, 'staff.password_reset', $staff, null, ['must_change_password' => true], 'A new temporary password was issued');
        SecurityEvent::log('password_reset_by_admin', $staff, $staff->login_phone ?? $staff->email, $actor);
        // a new secret was issued by someone allowed to, so failures counted against the old situation are cleared
        app(LoginThrottle::class)->clear($staff);
        TemporaryPassword::handOver($staff, $temporary, ['reset' => true]);

        return back()->with('success', "A new temporary password was made for {$staff->name}. Give it to them below.");
    }

    /**
     * Lets a person try again after a cool-down or temporary lock (when they simply got it wrong and remember their
     * password). Needs the same permission and scope as every other staff action, changes nothing else about the
     * account, and is written to the security log.
     */
    public function unlockSignIn(Request $request, User $staff): RedirectResponse
    {
        $request->user()->can('manage-staff') || abort(403);
        $actor = $request->user();
        $this->assertManageable($actor, $staff);

        app(LoginThrottle::class)->clear($staff);
        SecurityEvent::log('sign_in_unlocked_by_admin', $staff, $staff->login_phone ?? $staff->email, $actor);

        return back()->with('success', "{$staff->name} can try to sign in again.");
    }

    public function update(Request $request, User $staff): RedirectResponse
    {
        $request->user()->can('manage-staff') || abort(403);
        $actor = $request->user();
        $this->assertManageable($actor, $staff);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', new EthiopianMobileRule, new UniqueAccountPhone($staff->id)],
            'status' => ['required', 'in:active,inactive'],
            'role' => ['sometimes', 'required', Rule::in($this->assignableRoles($actor))],
            'branch_id' => ['nullable', 'integer'],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ]);

        if ($validated['status'] === 'inactive' && $staff->status === 'active' && $staff->id === $actor->id) {
            throw ValidationException::withMessages(['status' => 'You cannot switch off your own account.']);
        }

        $roleBefore = $staff->getRoleNames()->first();
        $newRole = $validated['role'] ?? null;
        if ($newRole !== null && $newRole !== $roleBefore && $staff->id === $actor->id) {
            throw ValidationException::withMessages(['role' => 'You cannot change your own system role.']);
        }

        $permissionsBefore = $staff->getPermissionNames()->sort()->values()->all();

        $phoneBefore = $staff->login_phone;
        $newPhone = ($validated['phone'] ?? null) ? PhoneNumber::normalizeMobile($validated['phone']) : $staff->login_phone;

        try {
            $staff->update([
                'name' => $validated['name'] ?? $staff->name,
                'title' => array_key_exists('title', $validated) ? $validated['title'] : $staff->title,
                'phone' => $newPhone ?? $staff->phone,
                'login_phone' => $newPhone,
                'status' => $validated['status'],
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['phone' => 'This phone number already belongs to another account.']);
        }

        if ($staff->wasChanged('status')) {
            if ($staff->status === 'inactive') {
                $this->signOutEverywhere($staff);
            }
            SecurityEvent::log($staff->status === 'active' ? 'account_enabled' : 'account_disabled', $staff, $staff->login_phone ?? $staff->email, $actor);
        }

        if ($phoneBefore !== $staff->login_phone) {
            AuditLog::record($actor, 'staff.phone_changed', $staff, ['phone' => $phoneBefore], ['phone' => $staff->login_phone]);
        }

        // Changing the system role resets permissions to that role's defaults (assignTier(), the same call
        // creation uses) -- never a blend of the old role's leftover grants and the new role's defaults. A
        // custom branch_admin permission set is then layered back on top, below, exactly like creation does.
        if ($newRole !== null && $newRole !== $roleBefore) {
            $branchId = $this->resolveBranchId($actor, $newRole, $validated['branch_id'] ?? $staff->branch_id);
            $staff->update(['branch_id' => $branchId]);
            $staff->assignTier($newRole, $validated['title'] ?? $staff->title);
            AuditLog::record($actor, 'staff.role_changed', $staff, ['role' => $roleBefore], ['role' => $newRole]);
        }

        if ($staff->fresh()->hasRole('branch_admin')) {
            $staff->syncPermissions($this->clampPermissions($actor, $validated['permissions'] ?? []));

            $permissionsAfter = $staff->getPermissionNames()->sort()->values()->all();
            if ($permissionsBefore !== $permissionsAfter) {
                AuditLog::record($actor, 'staff.permissions_changed', $staff,
                    ['permissions' => $permissionsBefore],
                    ['permissions' => $permissionsAfter, 'added' => array_values(array_diff($permissionsAfter, $permissionsBefore)), 'removed' => array_values(array_diff($permissionsBefore, $permissionsAfter))]
                );
            }
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
