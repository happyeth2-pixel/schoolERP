<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A school EMPLOYEE -- an HR record, deliberately separate from a login `User` account. The two are related
 * (an employee MAY have an account, via the nullable `user_id`), never the same thing: HR fields here
 * (contracts, leave, performance, payroll, once later phases add them) are controlled by `employee-manage`,
 * completely independent of `manage-staff` (which governs the login account itself) and of Spatie
 * roles/permissions (which govern what the linked account, if any, is allowed to DO in the system).
 */
class Employee extends Model
{
    use Audited;
    use SoftDeletes;

    public const EMPLOYMENT_STATUSES = ['active', 'on_leave', 'suspended', 'terminated'];

    public const EMPLOYMENT_TYPES = ['full_time', 'part_time', 'contract', 'intern'];

    protected $fillable = [
        'school_id', 'branch_id', 'user_id', 'employee_number', 'full_name', 'photo_path',
        'phone', 'email', 'address', 'emergency_contact_name', 'emergency_contact_phone',
        'gender', 'date_of_birth', 'hire_date', 'department_id', 'position_id',
        'employment_status', 'employment_type', 'qualifications', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'hire_date' => 'date',
        ];
    }

    /** Age in whole years, live from date_of_birth -- the same "one stored value, age always computed from
     * it" shape as Student::age(). Registration asks for an age, not an exact birthdate (most people know
     * their age, not everyone keeps track of the exact date), so date_of_birth here is itself only ever an
     * approximation (today minus N years at whatever moment it was set); nothing in this app depends on its
     * exact day/month, only on the whole-year age this method returns. */
    public function age(): ?int
    {
        if (! $this->date_of_birth) {
            return null;
        }

        // Compare as plain calendar dates (no time of day, no timezone) -- the exact same guard
        // Student::ageOn() uses and for the exact same reason: SchoolCalendar::today() carries the
        // business timezone's time-of-day, and comparing that directly against a UTC-midnight
        // date_of_birth can silently land on the wrong side of midnight and be off by a year.
        $today = \Illuminate\Support\Carbon::parse(\App\Support\SchoolCalendar::today()->format('Y-m-d'), 'UTC');
        $born = \Illuminate\Support\Carbon::parse($this->date_of_birth->format('Y-m-d'), 'UTC');

        return $born->diffInYears($today);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(EmploymentContract::class)->orderByDesc('start_date');
    }

    /** The contract currently in effect (active, or expiring soon but not yet expired) -- what payroll
     * reads for a basic salary. Null if the employee has never had one, or none is currently in effect. */
    public function currentContract(): ?EmploymentContract
    {
        return $this->contracts()->whereIn('status', ['active', 'expiring'])->first();
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class)->orderByDesc('created_at');
    }

    public function staffAttendances(): HasMany
    {
        return $this->hasMany(StaffAttendance::class)->orderByDesc('date');
    }

    public function leaveAllocations(): HasMany
    {
        return $this->hasMany(LeaveAllocation::class)->orderByDesc('year');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class)->orderByDesc('start_date');
    }

    public function performanceReviews(): HasMany
    {
        return $this->hasMany(PerformanceReview::class)->orderByDesc('finalized_at')->orderByDesc('created_at');
    }

    public function auditExcluded(): array
    {
        return ['photo_path'];
    }

    public function auditLabel(): string
    {
        return "{$this->employee_number} {$this->full_name}";
    }

    public function auditAction(array $changed): string
    {
        return match (true) {
            in_array('user_id', $changed, true) => $this->user_id ? 'employee.account_linked' : 'employee.account_unlinked',
            in_array('employment_status', $changed, true) => 'employee.status_changed',
            default => 'employee.updated',
        };
    }
}
