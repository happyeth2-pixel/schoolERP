<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    use HasFactory;

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
        'emergency_contact',
        'previous_school',
        'enrollment_date',
        'status',
        'id_card_token',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'enrollment_date' => 'date',
        ];
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->father_name} {$this->grandfather_name}");
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
            ->where('status', 'active')
            ->whereHas('section.academicYear', fn ($q) => $q->where('is_current', true));
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
}
