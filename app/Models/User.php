<?php

namespace App\Models;

use App\Models\Concerns\Audited;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use Audited;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    /**
     * Never persisted (a plain PHP property, not a database column): set this to true immediately before saving a
     * new password when must_change_password must end up true even if it already was (see the `updating` hook
     * below). Leave it alone for every other save.
     */
    public bool $forceMustChangePassword = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'school_id',
        'branch_id',
        'name',
        'title',
        'email',
        'phone',
        'login_phone',
        'password',
        'must_change_password',
        'password_changed_at',
        'status',
        'profile_photo_path',
        'locale',
    ];

    protected $appends = ['profile_photo_url'];

    protected static function booted(): void
    {
        // Once a password has been set some other way (e.g. Forgot password), an old
        // invitation link must not linger. (Accepting an invitation marks it used first.)
        // A password that changes on its own (Forgot password, the profile page, accepting an invitation) is by
        // definition not a temporary one any more. An administrator issuing a temporary password sets
        // must_change_password in the same save, which this leaves alone -- EXCEPT when the flag was already true
        // (e.g. reactivating an account that was disabled before its last temporary password was ever used): then
        // old value == new value, so isDirty() sees no change even though the intent was explicit, and would wrongly
        // clear it. $forceMustChangePassword is a transient (never persisted) way for that caller to say so.
        static::updating(function (User $user) {
            if ($user->isDirty('password')) {
                $user->password_changed_at = now();
                if ($user->forceMustChangePassword) {
                    $user->must_change_password = true;
                } elseif (! $user->isDirty('must_change_password')) {
                    $user->must_change_password = false;
                }
            }
        });

        static::updated(function (User $user) {
            if ($user->wasChanged('password')) {
                AccountInvitation::revokePending($user->id);
            }
        });
    }

    /** The canonical Ethiopian mobile number this account signs in with; anything that is not one is stored as null. */
    protected function loginPhone(): Attribute
    {
        return Attribute::set(fn ($value) => $value === null || trim((string) $value) === '' ? null : \App\Support\PhoneNumber::normalizeMobile((string) $value));
    }

    /** Sign-in identifier: a phone number (any everyday form) or, for accounts that have only an email, that email. */
    public static function findForLogin(string $identifier): ?self
    {
        $identifier = trim($identifier);

        if (str_contains($identifier, '@')) {
            return static::where('email', mb_strtolower($identifier))->first();
        }

        $canonical = \App\Support\PhoneNumber::normalizeMobile($identifier);

        return $canonical ? static::where('login_phone', $canonical)->first() : null;
    }

    protected function profilePhotoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->profile_photo_path ? Storage::disk('public')->url($this->profile_photo_path) : null);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * For Parent-tier accounts: the students this account is a
     * guardian of. A parent can have multiple children; a child can
     * have multiple guardians.
     */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'parent_student', 'parent_id', 'student_id')
            ->withPivot('relationship')
            ->withTimestamps();
    }

    /**
     * THE definition of "this parent may see this student's private data":
     * a parent-tier account that is linked to the student through the
     * guardian relationship (parent_student). Every parent-facing policy goes
     * through this -- never a hand-written check in a controller.
     */
    public function isGuardianOf(Student|int|null $student): bool
    {
        if ($student === null || ! $this->hasRole('parent')) {
            return false;
        }

        return $this->children()->whereKey($student instanceof Student ? $student->id : $student)->exists();
    }

    /**
     * For Teacher-tier accounts: section+subject combinations they teach.
     */
    public function teachingAssignments(): HasMany
    {
        return $this->hasMany(TeachingAssignment::class, 'teacher_id');
    }

    /** For Teacher-tier accounts: their own weekly timetable slots. Academic expansion, Phase 1. */
    public function timetableSlots(): HasMany
    {
        return $this->hasMany(TimetableSlot::class, 'teacher_id');
    }

    /** For Teacher/staff accounts: their own library loan history (a self-service borrower). */
    public function libraryLoans()
    {
        return $this->morphMany(LibraryLoan::class, 'borrower');
    }

    /** The HR employee record linked to this login account, if any -- see App\Models\Employee's own
     * docblock for why these are deliberately separate concepts. */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * Org-wide accounts (Owner, Auditor-at-org-level) have no branch_id.
     */
    public function isOrgWide(): bool
    {
        return is_null($this->branch_id);
    }

    /**
     * True if this user can perform the given management action, OR is
     * an Auditor just looking. Use this on every read-only index/show
     * endpoint so "Auditor sees everything, changes nothing" is
     * actually true — Auditor never gets this on a store/update/destroy
     * check, only on the read side, since they hold none of the
     * manage-* permissions themselves.
     */
    public function canViewOrManage(string $permission): bool
    {
        return $this->can($permission) || $this->hasRole('auditor');
    }

    /**
     * Assign this user's tier (one of the 7 roles) and seed their
     * individual permissions from that tier's default set. From this
     * point on, permissions live on the USER, not the role — so two
     * branch_admin accounts (e.g. "Vice Principal" and "Registrar")
     * can end up with completely different capabilities even though
     * they share the same tier. Call this once, at account creation;
     * afterwards, toggle permissions directly on the user
     * ($user->givePermissionTo() / revokePermissionTo()).
     */
    public function assignTier(string $role, ?string $title = null): void
    {
        $this->syncRoles([$role]);

        $defaults = \Database\Seeders\RolesAndPermissionsSeeder::DEFAULT_PERMISSIONS[$role] ?? [];
        $this->syncPermissions($defaults);

        if ($title !== null) {
            $this->title = $title;
            $this->save();
        }
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'phone' => \App\Casts\Phone::class,
        ];
    }

    public function auditExcluded(): array
    {
        return ['password_changed_at', 'last_login_at'];
    }

    public function auditLabel(): string
    {
        return "{$this->name} (".($this->email ?: $this->phone ?: 'no contact details').')';
    }
}
