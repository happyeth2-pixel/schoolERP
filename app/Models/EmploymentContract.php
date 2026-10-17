<?php

namespace App\Models;

use App\Exceptions\DeletionBlockedException;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employment contract for an Employee -- a school keeps the full history (a renewed contract is a NEW
 * row, the old one moves to 'expired' or 'terminated'; nothing here is ever silently overwritten). A
 * pre-commitment `draft` contract can still be removed outright; once it has ever been `active` it is kept
 * forever, the same "immutable once real" rule PurchaseOrder/Quotation already follow elsewhere in this app.
 */
class EmploymentContract extends Model
{
    use Audited;

    public const STATUSES = ['draft', 'active', 'expiring', 'expired', 'terminated'];

    public const CONTRACT_TYPES = ['permanent', 'fixed_term', 'probationary', 'part_time'];

    protected $fillable = [
        'employee_id', 'contract_type', 'start_date', 'end_date', 'status', 'salary_reference',
        'working_schedule', 'document_path', 'document_original_name', 'renewal_date',
        'expiry_notified', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'renewal_date' => 'date',
            'salary_reference' => 'decimal:2',
            'expiry_notified' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (EmploymentContract $contract) {
            if ($contract->status !== 'draft') {
                throw new DeletionBlockedException('Only a draft contract can be removed -- once a contract has taken effect it stays in the employee\'s history.');
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditLabel(): string
    {
        return "{$this->contract_type} contract for {$this->employee->full_name}";
    }

    public function auditAction(array $changed): string
    {
        return in_array('status', $changed, true) ? 'employment_contract.status_changed' : 'employment_contract.updated';
    }

    public function auditExcluded(): array
    {
        return ['document_path', 'document_original_name', 'expiry_notified'];
    }
}
