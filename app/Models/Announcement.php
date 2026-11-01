<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Support\SchoolCalendar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A notice from the school: title, message, who it is for (everyone / parents /
 * staff), which branch (none = every branch) and the day it appears.
 */
class Announcement extends Model
{
    use Audited;
    use SoftDeletes;

    public const AUDIENCES = [
        'everyone' => 'Everyone',
        'parents' => 'Parents',
        'staff' => 'Staff',
    ];

    /** Who counts as "staff" for the audience 'everyone'/'staff' -- the same tiers StaffController manages. */
    private const STAFF_ROLES = ['owner', 'branch_admin', 'teacher', 'accountant', 'auditor'];

    protected $fillable = ['school_id', 'branch_id', 'created_by', 'title', 'body', 'audience', 'publish_on', 'notified_at'];

    protected function casts(): array
    {
        return ['publish_on' => 'date', 'notified_at' => 'datetime'];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditLabel(): string
    {
        return (string) $this->title;
    }

    /**
     * What this person is meant to see: their school, their branch (or every branch), their
     * audience -- and only from the day it is published. Managers additionally see what is
     * scheduled for later within their scope, and every audience of it.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query->where('school_id', $user->school_id);

        $isParent = $user->hasRole('parent');
        $manages = $user->can('manage-announcements');

        if ($isParent) {
            $branchIds = $user->children()->pluck('students.branch_id')->unique()->all();
            $query->where(fn ($q) => $q->whereNull('branch_id')->orWhereIn('branch_id', $branchIds));
        } elseif (! $user->isOrgWide()) {
            $query->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $user->branch_id));
        }

        if (! $manages) {
            $query->whereDate('publish_on', '<=', SchoolCalendar::today()->toDateString())
                ->whereIn('audience', $isParent ? ['everyone', 'parents'] : ['everyone', 'staff']);
        }

        return $query;
    }

    /**
     * Everyone this announcement is actually for, right now: active accounts of the right kind (parents and/or
     * staff, following `audience`) in the right branch (every branch of the school when `branch_id` is null). A
     * parent counts by their CHILDREN's branch, exactly like scopeVisibleTo above -- a parent is not itself
     * branch-scoped, since one family can have children in different branches.
     */
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

    /** Tells the audience, once. Safe to call more than once: a second call is a no-op (notified_at is already set). */
    public function notifyAudience(): void
    {
        if ($this->notified_at !== null) {
            return;
        }

        \Illuminate\Support\Facades\Notification::send($this->recipients(), new \App\Notifications\AnnouncementPublished($this));
        $this->forceFill(['notified_at' => now()])->save();
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
