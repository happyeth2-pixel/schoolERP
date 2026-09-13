<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles;

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
        'password',
        'status',
    ];

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
     * For Teacher-tier accounts: section+subject combinations they teach.
     */
    public function teachingAssignments(): HasMany
    {
        return $this->hasMany(TeachingAssignment::class, 'teacher_id');
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
        ];
    }
}
