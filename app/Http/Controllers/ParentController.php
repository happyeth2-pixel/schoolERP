<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SecurityEvent;
use App\Models\Student;
use App\Models\User;
use App\Rules\EthiopianMobileRule;
use App\Rules\NotBasedOnIdentity;
use App\Rules\UniqueAccountPhone;
use App\Security\LoginThrottle;
use App\Support\PhoneNumber;
use App\Support\TemporaryPassword;
use App\Http\Controllers\Concerns\PaginatesLists;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Parent accounts are OPTIONAL: a family that never signs in is served exactly as well at the school desk
 * (StudentDeskController). An account, when one is made, uses the same phone-number sign-in as staff -- a
 * temporary password shown once, and the person must choose their own at their first sign-in
 * (must_change_password / EnsurePasswordIsChanged, unchanged). Nothing here creates a student login: only
 * the guardian's own account, linked to the children they already have or are given here.
 */
class ParentController extends Controller
{
    use PaginatesLists;

    /**
     * Shared scope + search, the same idea as StaffController@staffQuery: name, email, phone (however typed),
     * or a linked child's name/student-id-number, resolved by the database rather than in a fully-loaded array.
     */
    private function parentsQuery(Request $request, User $user): Builder
    {
        $query = User::query()
            ->where('school_id', $user->school_id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'parent'));

        if ($term = trim((string) $request->query('search', ''))) {
            $like = '%'.$term.'%';
            $phoneKey = PhoneNumber::searchKey($term);
            $query->where(function ($w) use ($like, $phoneKey) {
                $w->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhereHas('children', fn ($c) => $c->where('first_name', 'like', $like)
                        ->orWhere('father_name', 'like', $like)
                        ->orWhere('grandfather_name', 'like', $like)
                        ->orWhere('student_id_number', 'like', $like));
                if (strlen($phoneKey) >= 3) {
                    $w->orWhere('phone', 'like', '%'.$phoneKey.'%');
                }
            });
        }

        return $query;
    }

    public function index(Request $request): Response
    {
        $request->user()->canViewOrManage('manage-parents') || abort(403);
        $user = $request->user();
        $throttle = app(LoginThrottle::class);

        $paginator = $this->parentsQuery($request, $user)
            ->with('children')
            ->orderBy('name')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $parents = $paginator->getCollection()
            ->map(fn (User $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'photo_url' => $p->profile_photo_url,
                'email' => $p->email,
                'phone' => $p->phone,
                'phone_display' => $p->phone ? PhoneNumber::display($p->phone) : null,
                'can_sign_in_with_phone' => $p->login_phone !== null,
                'status' => $p->status,
                'last_login_at' => $p->last_login_at?->diffForHumans(),
                'must_change_password' => (bool) $p->must_change_password,
                'sign_in_lock' => $throttle->status($p),
                'children' => $p->children->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->fullName(),
                    'student_id_number' => $c->student_id_number,
                    'relationship' => $c->pivot->relationship,
                    'relationship_label' => Student::relationshipLabel($c->pivot->relationship),
                ]),
            ])->values();

        return Inertia::render('Parents/Index', [
            'parents' => $parents,
            'parentsMeta' => $this->paginationMeta($paginator),
            'search' => $request->query('search', ''),
            'canOpenStudents' => $user->can('manage-students'),
            'canManage' => $user->can('manage-parents'),
            'canView' => $user->canViewOrManage('manage-parents'),
        ]);
    }

    /**
     * "Add a parent account": name, phone, an optional email, and optionally one or more of the school's own
     * students to link right away (a parent account can also be made with no child yet, and linked later from
     * the student's own profile). The person is given a temporary password once and must choose their own at
     * their first sign-in -- exactly like a staff account, and using the same phone number rules.
     */
    public function create(Request $request): Response
    {
        $user = $request->user();
        $user->can('manage-parents') || abort(403);

        return Inertia::render('Parents/Create', [
            'students' => $this->studentsInScope($user),
            'guardianRelationships' => Student::GUARDIAN_RELATIONSHIPS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $actor->can('manage-parents') || abort(403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50', new EthiopianMobileRule, new UniqueAccountPhone],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'temporary_password' => ['nullable', 'string', 'max:72', Password::min(8)->letters()->mixedCase()->numbers(), new NotBasedOnIdentity($request->input('name'), $request->input('phone'))],
            'student_ids' => ['array', 'max:20'],
            'student_ids.*' => ['integer', 'distinct'],
            'relationships' => ['array'],
            'relationships.*' => [Rule::in(array_keys(Student::GUARDIAN_RELATIONSHIPS))],
        ]);

        $studentIds = $validated['student_ids'] ?? [];
        $students = $studentIds ? $this->studentsInScopeQuery($actor)->whereIn('id', $studentIds)->get() : collect();
        if ($students->count() !== count($studentIds)) {
            throw ValidationException::withMessages(['student_ids' => 'One of the chosen students is not available to you.']);
        }
        foreach ($studentIds as $id) {
            if (empty($validated['relationships'][$id] ?? null)) {
                throw ValidationException::withMessages(['student_ids' => 'Choose how the parent is related to each child.']);
            }
        }

        $temporary = $validated['temporary_password'] ?? TemporaryPassword::make();

        try {
            $parent = DB::transaction(function () use ($actor, $validated, $students, $temporary) {
                $parent = User::create([
                    'school_id' => $actor->school_id,
                    'branch_id' => null,
                    'name' => $validated['name'],
                    'email' => $validated['email'] ?? null,
                    'phone' => $validated['phone'],
                    'login_phone' => $validated['phone'],
                    'password' => $temporary,
                    'must_change_password' => true,
                    'status' => 'active',
                    'email_verified_at' => ($validated['email'] ?? null) ? now() : null,
                ]);
                $parent->assignTier('parent');

                foreach ($students as $student) {
                    $relationship = $validated['relationships'][$student->id];
                    $student->guardians()->attach($parent->id, ['relationship' => $relationship]);
                    AuditLog::record($actor, 'student.guardian_linked', $student, null, ['guardian' => $parent->name, 'relationship' => $relationship]);
                }

                return $parent;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['phone' => 'This phone number already belongs to another account. Check the number, or find that person in the staff or parent list.']);
        }

        AuditLog::record($actor, 'parent.account_created', $parent, null, [
            'children' => $students->map(fn ($s) => $s->fullName())->values()->all(),
            'chosen_by_administrator' => isset($validated['temporary_password']),
        ], 'Temporary password handed over by the administrator');

        TemporaryPassword::handOver($parent, $temporary, ['children' => $students->map(fn ($s) => $s->fullName())->values()->all()]);

        return back()->with('success', "{$parent->name}'s account is ready. Give them the sign-in details below.");
    }

    public function update(Request $request, User $parent): RedirectResponse
    {
        $actor = $request->user();
        $actor->can('manage-parents') || abort(403);
        $this->assertIsManageableParent($actor, $parent);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', new EthiopianMobileRule, new UniqueAccountPhone($parent->id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($parent->id)],
        ]);

        $phoneBefore = $parent->login_phone;
        $newPhone = ($validated['phone'] ?? null) ? PhoneNumber::normalizeMobile($validated['phone']) : $parent->login_phone;

        try {
            $parent->update([
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'phone' => $newPhone ?? $parent->phone,
                'login_phone' => $newPhone,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['phone' => 'This phone number already belongs to another account.']);
        }

        if ($phoneBefore !== $parent->login_phone) {
            AuditLog::record($actor, 'staff.phone_changed', $parent, ['phone' => $phoneBefore], ['phone' => $parent->login_phone]);
        }

        return back()->with('success', "{$parent->name}'s details are saved.");
    }

    /** A new temporary password, shown once. The parent's old password stops working at once. */
    public function resetPassword(Request $request, User $parent): RedirectResponse
    {
        $actor = $request->user();
        $actor->can('manage-parents') || abort(403);
        $this->assertIsManageableParent($actor, $parent);

        if (! $parent->login_phone && ! $parent->email) {
            return back()->withErrors(['reset' => "{$parent->name} has no phone number or email to sign in with. Add one under Change first."]);
        }

        $temporary = TemporaryPassword::make();
        $parent->forceMustChangePassword = true;
        $parent->forceFill(['password' => $temporary, 'must_change_password' => true, 'remember_token' => str()->random(60)])->save();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $parent->id)->delete();
        }

        AuditLog::record($actor, 'staff.password_reset', $parent, null, [], 'Temporary password handed over by the administrator');
        TemporaryPassword::handOver($parent, $temporary, ['reset' => true, 'children' => $parent->children->map(fn ($c) => $c->fullName())->values()->all()]);

        return back()->with('success', "A new temporary password for {$parent->name} is ready below.");
    }

    /** Switches a parent account off. Nothing about the children's records changes; the family is still served at the school desk. */
    public function deactivate(Request $request, User $parent): RedirectResponse
    {
        $actor = $request->user();
        $actor->can('manage-parents') || abort(403);
        $this->assertIsManageableParent($actor, $parent);

        if ($parent->status === 'inactive') {
            return back()->with('success', "{$parent->name}'s account is already switched off.");
        }

        $parent->forceFill(['status' => 'inactive', 'remember_token' => str()->random(60)])->save();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $parent->id)->delete();
        }

        SecurityEvent::log('account_disabled', $parent, $parent->login_phone ?? $parent->email, $actor);

        return back()->with('success', "{$parent->name}'s account is switched off. They can no longer sign in. Their family's records are unchanged.");
    }

    public function reactivate(Request $request, User $parent): RedirectResponse
    {
        $actor = $request->user();
        $actor->can('manage-parents') || abort(403);
        $this->assertIsManageableParent($actor, $parent);

        if ($parent->status === 'active') {
            return back()->with('success', "{$parent->name}'s account is already on.");
        }

        $parent->update(['status' => 'active']);
        app(LoginThrottle::class)->clear($parent);
        SecurityEvent::log('account_enabled', $parent, $parent->login_phone ?? $parent->email, $actor);

        if ($request->boolean('reset_password')) {
            $temporary = TemporaryPassword::make();
            $parent->forceMustChangePassword = true;
        $parent->forceFill(['password' => $temporary, 'must_change_password' => true, 'remember_token' => str()->random(60)])->save();
            AuditLog::record($actor, 'staff.password_reset', $parent, null, ['must_change_password' => true], 'Temporary password handed over by the administrator');
            TemporaryPassword::handOver($parent, $temporary, ['reset' => true, 'children' => $parent->children->map(fn ($c) => $c->fullName())->values()->all()]);

            return back()->with('success', "{$parent->name}'s account is switched on again. A new temporary password is ready below.");
        }

        return back()->with('success', "{$parent->name}'s account is switched on again. They can sign in with their existing password.");
    }

    /**
     * Small lookup used by "link an existing parent" (the registration wizard, and the student profile): confirms a
     * parent account exists by email OR phone before the form submits.
     */
    public function search(Request $request)
    {
        $request->user()->can('manage-students') || abort(403);

        $query = User::where('school_id', $request->user()->school_id)->whereHas('roles', fn ($q) => $q->where('name', 'parent'));

        if ($phone = $request->query('phone')) {
            $canonical = PhoneNumber::normalizeMobile($phone);
            $guardian = $canonical ? (clone $query)->where('login_phone', $canonical)->first(['id', 'name', 'email']) : null;
        } else {
            $guardian = (clone $query)->where('email', $request->query('email'))->first(['id', 'name', 'email']);
        }

        return response()->json(['guardian' => $guardian]);
    }

    /** Students an actor may link a parent to: their school, and their own branch unless they are org-wide. */
    private function studentsInScopeQuery(User $actor)
    {
        return Student::query()
            ->where('school_id', $actor->school_id)
            ->when(! $actor->isOrgWide(), fn ($q) => $q->where('branch_id', $actor->branch_id));
    }

    private function studentsInScope(User $actor): array
    {
        return $this->studentsInScopeQuery($actor)
            ->with('currentEnrollment.section.grade')
            ->orderBy('first_name')
            ->get()
            ->map(fn (Student $s) => [
                'id' => $s->id,
                'name' => $s->fullName(),
                'student_id_number' => $s->student_id_number,
                'class' => $s->currentEnrollment ? "{$s->currentEnrollment->section->grade->name} {$s->currentEnrollment->section->name}" : null,
            ])
            ->values()
            ->all();
    }

    /** Same school; a parent account itself is not branch-scoped (a family can have children in several branches). */
    private function assertIsManageableParent(User $actor, User $parent): void
    {
        abort_unless($parent->school_id === $actor->school_id && $parent->hasRole('parent'), 403);
    }
}
