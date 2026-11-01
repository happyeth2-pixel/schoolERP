<?php

namespace App\Models;

use App\Support\SchoolCalendar;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\NeverHardDeleted;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Student extends Model
{
    use Audited;

    use NeverHardDeleted;

    use HasFactory;

    /** Lifecycle statuses in which a student can hold an active enrollment. */
    public const ENROLLED_STATUSES = ['active', 'suspended'];

    /** Student status -> the status their open enrollment is closed with. */
    public const ENROLLMENT_CLOSED_AS = [
        'transferred' => 'transferred',
        'graduated' => 'completed',
        'withdrawn' => 'withdrawn',
        'expelled' => 'withdrawn',
        'inactive' => 'withdrawn',
    ];

    protected $fillable = [
        'school_id',
        'branch_id',
        'student_id_number',
        'first_name',
        'father_name',
        'grandfather_name',
        'mother_name',
        'gender',
        'date_of_birth',
        'photo_path',
        'address',
        'kebele',
        'city',
        'emergency_contact',
        'previous_school',
        'enrollment_date',
        'status',
        'withdrawn_on',
        'withdrawal_reason',
        'id_card_token',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'enrollment_date' => 'date',
            'withdrawn_on' => 'date',
            'emergency_contact' => \App\Casts\Phone::class,
        ];
    }

    /**
     * A new ID-card token: 40 characters from random_bytes (about 238 bits),
     * so it can't be guessed and reveals nothing (no sequence, no student data).
     */
    public static function newCardToken(): string
    {
        do {
            $token = Str::random(40);
        } while (static::where('id_card_token', $token)->exists());

        return $token;
    }

    /**
     * How a guardian relates to the student. A guardian is a relationship, not a
     * requirement of the child's biological family: a student may have any mix
     * (or none yet), each with their own contact and login. Values are stored;
     * labels are for display.
     */
    public const GUARDIAN_RELATIONSHIPS = [
        'father' => 'Father',
        'mother' => 'Mother',
        'stepfather' => 'Stepfather',
        'stepmother' => 'Stepmother',
        'grandparent' => 'Grandparent',
        'aunt' => 'Aunt',
        'uncle' => 'Uncle',
        'sibling' => 'Older sibling',
        'foster_parent' => 'Foster parent',
        'guardian' => 'Legal guardian',
        'other' => 'Other',
    ];

    public static function relationshipLabel(?string $value): string
    {
        return self::GUARDIAN_RELATIONSHIPS[$value] ?? ucfirst(str_replace('_', ' ', (string) $value));
    }

    /** How long after its academic year ends an ID card stays valid (new-year paperwork takes time). */
    public const ID_CARD_GRACE_DAYS = 30;

    /**
     * ID-CARD VALIDITY, calculated -- never stored -- from the student's
     * lifecycle status AND their enrollment, so it can't drift out of date:
     *
     *   suspended  status is `suspended`
     *   expired    the card's period is over, not the student in trouble:
     *              status `graduated`; OR status `active` whose enrollment is
     *              in an academic year that is no longer current or ended more
     *              than ID_CARD_GRACE_DAYS ago; OR status `active` with only a
     *              finished (completed) enrollment and none in the current year yet
     *   valid      status `active` AND an active enrollment in the current
     *              academic year (within its dates + grace)
     *   invalid    everything else: withdrawn, expelled, transferred, inactive,
     *              active but never enrolled -- deliberately no finer detail
     *
     * @return array{state: string, reason: string}  reason is for staff only
     */
    public function idCardValidity(): array
    {
        switch ($this->status) {
            case 'suspended':
                return ['state' => 'suspended', 'reason' => 'The student is suspended.'];
            case 'graduated':
                return ['state' => 'expired', 'reason' => 'The student has graduated.'];
            case 'active':
                break;
            default:
                return ['state' => 'invalid', 'reason' => "The student's status is {$this->status}."];
        }

        $enrollment = $this->enrollments()->where('status', 'active')->with('section.academicYear')->latest('id')->first();

        if (! $enrollment) {
            return $this->enrollments()->where('status', 'completed')->exists()
                ? ['state' => 'expired', 'reason' => 'Their last enrollment has ended and they are not enrolled in the current year yet (promote them to renew the card).']
                : ['state' => 'invalid', 'reason' => 'The student has no enrollment, so the card cannot be valid.'];
        }

        $year = $enrollment->section->academicYear;
        $lastDay = $year->end_date->copy()->addDays(self::ID_CARD_GRACE_DAYS);

        if (! $year->is_current || SchoolCalendar::today()->gt($lastDay)) {
            return ['state' => 'expired', 'reason' => "Their academic year ({$year->name}) is over — the card was valid until {$this->cardDate($lastDay)}."];
        }

        return ['state' => 'valid', 'reason' => "Active student enrolled for {$year->name}; valid until {$this->cardDate($lastDay)}."];
    }

    private function cardDate($date): string
    {
        return SchoolCalendar::date($date, $this->school);
    }

    public function idCardState(): string
    {
        return $this->idCardValidity()['state'];
    }

    /**
     * Age in whole years. The date of birth is the only stored value; age is
     * always calculated from it, so the two can never disagree. With no $on it
     * is the age today (Addis Ababa school day); pass a date for "age on that
     * day" (e.g. at a given exam or on the first day of the year).
     * If a genuine business rule ever needs the age AT ENROLLMENT to be fixed
     * for the record, snapshot that separately at that moment -- don't store a
     * live age.
     */
    public function ageOn(\Carbon\CarbonInterface|string|null $on = null): ?int
    {
        if (! $this->date_of_birth) {
            return null;
        }

        // Compare as plain calendar dates (no time of day, no timezone), so a
        // birthday is a birthday for the whole day wherever the server clock is.
        $on = \Carbon\Carbon::parse(($on === null ? SchoolCalendar::today() : \Carbon\Carbon::parse($on))->format('Y-m-d'), 'UTC');
        $born = \Carbon\Carbon::parse($this->date_of_birth->format('Y-m-d'), 'UTC');

        if ($on->lt($born)) {
            return null;   // asked about a day before they were born
        }

        return $born->diffInYears($on);
    }

    public function age(): ?int
    {
        return $this->ageOn();
    }

    /** Joins whichever name parts exist, with single spaces (any of them may be missing). */
    private function joinNames(array $parts): string
    {
        return trim(preg_replace('/\s+/u', ' ', implode(' ', array_filter($parts, fn ($p) => $p !== null && trim($p) !== ''))));
    }

    /** The only name shown on the public verification page: first + father's name. */
    public function publicName(): string
    {
        return $this->joinNames([$this->first_name, $this->father_name]);
    }

    public function fullName(): string
    {
        return $this->joinNames([$this->first_name, $this->father_name, $this->grandfather_name]);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function currentEnrollment()
    {
        return $this->hasOne(Enrollment::class)
            ->active()
            ->whereHas('section.academicYear', fn ($q) => $q->where('is_current', true));
    }

    public function medicalRecord()
    {
        return $this->hasOne(StudentMedicalRecord::class);
    }

    public function libraryLoans()
    {
        return $this->morphMany(LibraryLoan::class, 'borrower');
    }

    public function disciplineIncidents()
    {
        return $this->hasMany(DisciplineIncident::class);
    }

    /** Students who hold a current-year enrollment right now. */
    public function scopeEnrolled($query)
    {
        return $query->whereHas('currentEnrollment');
    }

    public function isEnrolled(): bool
    {
        return $this->currentEnrollment !== null;
    }

    /**
     * Formally withdraws the student as of a given date. Closes the open
     * enrollment, records who/why, and stops billing after that date:
     * invoice runs skip any period that starts after it, and invoices
     * that already exist for later periods with no payment (verified or
     * pending) are WAIVED -- never deleted -- so they stop counting as
     * money owed. Anything already invoiced for on or before the date,
     * and every payment, is left exactly as it is.
     *
     * @return array{waived:int, kept_with_payments:int}
     */
    public function withdraw(\Carbon\Carbon $effectiveOn, string $reason, User $actor): array
    {
        return AuditLog::suspended(fn () => DB::transaction(function () use ($effectiveOn, $reason, $actor) {
            $before = ['status' => $this->status, 'withdrawn_on' => $this->withdrawn_on?->toDateString()];

            $this->changeStatus('withdrawn');
            $this->update(['withdrawn_on' => $effectiveOn->toDateString(), 'withdrawal_reason' => $reason]);

            $waived = 0;
            $kept = 0;
            $later = $this->invoices()
                ->whereDate('period_start', '>', $effectiveOn->toDateString())
                ->with('payments')
                ->get();

            foreach ($later as $invoice) {
                if ($invoice->is_waived) {
                    continue;
                }
                if ($invoice->payments->isNotEmpty()) {
                    $kept++;

                    continue;
                }
                $invoice->update([
                    'is_waived' => true,
                    'waiver_reason' => 'Student withdrawn effective '.$effectiveOn->toDateString(),
                    'waived_by' => $actor->id,
                    'waived_at' => now(),
                ]);
                $waived++;
            }

            AuditLog::record($actor, 'student.withdrawn', $this, $before, [
                'status' => 'withdrawn',
                'withdrawn_on' => $effectiveOn->toDateString(),
                'invoices_waived' => $waived,
                'invoices_kept_with_payments' => $kept,
            ], $reason);

            return ['waived' => $waived, 'kept_with_payments' => $kept];
        }));
    }

    /**
     * Changes the lifecycle status and keeps the enrollment record in
     * step: statuses that end enrollment close the open enrollment
     * (graduated -> completed, transferred -> transferred, the rest ->
     * withdrawn); returning to active reopens an enrollment that was
     * closed as withdrawn this year, if there is one.
     */
    public function changeStatus(string $status): void
    {
        DB::transaction(function () use ($status) {
            $open = $this->enrollments()->where('enrollments.status', 'active')->first();
            $this->update(['status' => $status]);

            if (isset(self::ENROLLMENT_CLOSED_AS[$status])) {
                $open?->update(['status' => self::ENROLLMENT_CLOSED_AS[$status]]);

                return;
            }

            if ($status === 'active') {
                $this->update(['withdrawn_on' => null, 'withdrawal_reason' => null]);
            }

            if ($status === 'active' && ! $open) {
                $this->enrollments()
                    ->where('enrollments.status', 'withdrawn')
                    ->whereHas('section.academicYear', fn ($q) => $q->where('is_current', true))
                    ->latest('id')
                    ->first()
                    ?->update(['status' => 'active']);
            }
        });
    }

    /** The enrolment that going back to "active" would re-open (mirrors changeStatus), or null. */
    public function reopenableEnrollment(): ?Enrollment
    {
        if ($this->enrollments()->where('enrollments.status', 'active')->exists()) {
            return null;
        }

        return $this->enrollments()
            ->where('enrollments.status', 'withdrawn')
            ->whereHas('section.academicYear', fn ($q) => $q->where('is_current', true))
            ->with('section.grade')
            ->latest('enrollments.id')
            ->first();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'parent_student', 'student_id', 'parent_id')
            ->withPivot('relationship')
            ->withTimestamps();
    }

    public function parentMessages(): HasMany
    {
        return $this->hasMany(ParentMessage::class);
    }

    public function optionalServices(): BelongsToMany
    {
        return $this->belongsToMany(OptionalService::class, 'student_optional_service')
            ->withPivot('enrolled_on', 'custom_amount')
            ->withTimestamps();
    }

    public function bookPurchases(): HasMany
    {
        return $this->hasMany(StudentBookPurchase::class);
    }

    public function auditLabel(): string
    {
        return trim("{$this->student_id_number} ".$this->fullName());
    }

    public function auditReason(array $changed): ?string
    {
        return in_array('withdrawal_reason', $changed, true) ? $this->withdrawal_reason : null;
    }
}
