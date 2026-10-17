<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Support\SchoolCalendar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Notification;

/**
 * A school calendar event -- holiday, exam period, meeting, activity or deadline. Academic expansion,
 * Phase 3. Deliberately mirrors `Announcement`'s own shape almost exactly (same 3-bucket audience, same
 * branch-or-every-branch scoping, same one-shot notify-the-audience guard) -- confirmed with the user as the
 * right level of granularity for "role-based visibility" rather than a finer per-role picker. Unlike
 * Announcement, there is no `publish_on` scheduling: an event is visible and notifies its audience the
 * moment it is created, since `start_date` already answers "when it happens," a separate question from
 * "when people are told about it."
 */
class CalendarEvent extends Model
{
    use Audited;
    use SoftDeletes;

    public const AUDIENCES = Announcement::AUDIENCES;

    public const EVENT_TYPES = [
        'holiday' => 'Holiday',
        'exam' => 'Exam',
        'meeting' => 'Meeting',
        'activity' => 'Activity',
        'deadline' => 'Deadline',
        'other' => 'Other',
    ];

    /** Who counts as "staff" for the audience 'everyone'/'staff' -- the same tiers Announcement already uses. */
    private const STAFF_ROLES = ['owner', 'branch_admin', 'teacher', 'accountant', 'auditor'];

    protected $fillable = ['school_id', 'branch_id', 'created_by', 'title', 'description', 'event_type', 'audience', 'start_date', 'end_date', 'notified_at'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'notified_at' => 'datetime'];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditLabel(): string
    {
        return (string) $this->title;
    }

    /** What this person is meant to see: their school, their branch (or every branch), their audience.
     * Managers additionally see every audience and branch in their scope, for oversight. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query->where('school_id', $user->school_id);

        $isParent = $user->hasRole('parent');
        $manages = $user->can('manage-calendar');

        if ($isParent) {
            $branchIds = $user->children()->pluck('students.branch_id')->unique()->all();
            $query->where(fn ($q) => $q->whereNull('branch_id')->orWhereIn('branch_id', $branchIds));
        } elseif (! $user->isOrgWide()) {
            $query->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $user->branch_id));
        }

        if (! $manages) {
            $query->whereIn('audience', $isParent ? ['everyone', 'parents'] : ['everyone', 'staff']);
        }

        return $query;
    }

    /** Everyone this event is actually for, right now -- the exact same shape as Announcement::recipients(). */
    public function recipients(): \Illuminate\Support\Collection
    {
        $branchIds = $this->branch_id ? [$this->branch_id] : $this->school->branches()->pluck('id')->all();

        $parents = collect();
        if (in_array($this->audience, ['everyone', 'parents'], true)) {
            $parents = User::where('school_id', $this->school_id)
                ->where('status', 'active')
                ->whereHas('roles', fn ($q) => $q->where('name', 'parent'))
                ->whereHas('children', fn ($q) => $q->whereIn('students.branch_id', $branchIds))
                ->get();
        }

        $staff = collect();
        if (in_array($this->audience, ['everyone', 'staff'], true)) {
            $staff = User::where('school_id', $this->school_id)
                ->where('status', 'active')
                ->whereHas('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES))
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhereIn('branch_id', $branchIds))
                ->get();
        }

        return $parents->merge($staff)->unique('id')->values();
    }

    /** Tells the audience, once. Safe to call more than once: a second call is a no-op. */
    public function notifyAudience(): void
    {
        if ($this->notified_at !== null) {
            return;
        }

        Notification::send($this->recipients(), new \App\Notifications\CalendarEventPublished($this));
        $this->forceFill(['notified_at' => now()])->save();
    }
}
