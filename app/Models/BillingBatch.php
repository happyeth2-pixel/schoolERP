<?php

namespace App\Models;

use App\Models\Concerns\NeverHardDeleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A record of one "Create Bills" run -- see the migration for why this exists separately from the
 *  invoices it created. */
class BillingBatch extends Model
{
    use NeverHardDeleted;

    protected $fillable = [
        'school_id', 'branch_id', 'academic_year_id', 'initiated_by', 'generation_batch_id',
        'scope_type', 'scope_label', 'periods', 'due_date_override', 'status',
        'students_considered', 'students_billed', 'invoices_created', 'total_amount', 'message',
        'cancelled_at', 'cancelled_by',
    ];

    protected function casts(): array
    {
        return [
            'periods' => 'array',
            'due_date_override' => 'date',
            'total_amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
