<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class OptionalService extends Model
{
    use Audited;

    use SoftDeletes;

    use HasFactory;

    public const CATEGORIES = ['transport', 'asp', 'other'];

    protected $fillable = ['branch_id', 'academic_year_id', 'name', 'category', 'amount', 'frequency', 'revenue_account_id'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_optional_service')
            ->withPivot('enrolled_on', 'custom_amount')
            ->withTimestamps();
    }
}
