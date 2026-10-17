<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\NeverHardDeleted;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Invoice extends Model
{
    use Audited;

    use NeverHardDeleted;

    use HasFactory;

    protected $fillable = [
        'student_id', 'academic_year_id', 'term_id', 'period_label', 'frequency', 'period_start',
        'invoice_number', 'discount_amount', 'discount_reason', 'discount_by', 'is_waived', 'waiver_reason', 'waived_by', 'waived_at',
    ];

    protected function casts(): array
    {
        return [
            'is_waived' => 'boolean',
            'waived_at' => 'datetime',
            'period_start' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by');
    }

    public function discountBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discount_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Journal entries posted against this invoice -- issuance, discount/waiver changes. See
     * App\Services\AccountingPostings. */
    public function journalEntries(): MorphMany
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }

    /**
     * Adds a line copied from a fee structure or optional service AS IT
     * IS RIGHT NOW. Name, quantity, unit amount and line total are
     * stored on the line itself, so editing or deleting the fee later
     * can never change what this invoice says was charged.
     */
    public function addLineFrom(FeeStructure|OptionalService $source, int $quantity = 1, ?float $unitOverride = null): InvoiceItem
    {
        // $unitOverride is a student's own price for a service (student_optional_service.custom_amount)
        $unit = round((float) ($unitOverride ?? $source->amount), 2);

        return $this->items()->create([
            'item_type' => $source instanceof FeeStructure ? 'fee' : 'service',
            'fee_structure_id' => $source instanceof FeeStructure ? $source->id : null,
            'optional_service_id' => $source instanceof OptionalService ? $source->id : null,
            'description' => $source->name,
            'quantity' => $quantity,
            'unit_amount' => $unit,
            'amount' => round($unit * $quantity, 2),
        ]);
    }

    /**
     * Money figures are computed live from the items, verified payments and refunds -- never
     * cached, so nothing can drift out of sync -- and ONLY by App\Services\Finance, which is
     * the single definition (see its docblock). These are thin views onto it.
     */
    public function figures(): array
    {
        return \App\Services\Finance::figures($this);
    }

    /** Sum of the items, before any discount. */
    public function subtotal(): float
    {
        return $this->figures()['gross'];
    }

    /** Billed: subtotal minus discount. */
    public function totalAmount(): float
    {
        return $this->figures()['net'];
    }

    /** Collected: verified payments minus refunds/reversals. */
    public function paidAmount(): float
    {
        return $this->figures()['collected'];
    }

    public function refundedAmount(): float
    {
        return $this->figures()['refunded'];
    }

    public function waivedAmount(): float
    {
        return $this->figures()['waived'];
    }

    /** Still owed. 0 for a waived invoice. */
    public function balance(): float
    {
        return $this->figures()['outstanding'];
    }

    public function statusLabel(): string
    {
        return $this->figures()['status'];
    }

    /** Invoices are generated in bulk; one summary entry per generation run is written instead of one per invoice. */
    public function auditsCreation(): bool
    {
        return false;
    }

    public function auditLabel(): string
    {
        return 'Invoice '.($this->invoice_number ?? '#'.$this->id);
    }

    public function auditAction(array $changed): string
    {
        return match (true) {
            in_array('is_waived', $changed, true) => $this->is_waived ? 'invoice.waived' : 'invoice.waiver_removed',
            in_array('discount_amount', $changed, true) => 'invoice.discount_changed',
            in_array('status', $changed, true) => 'invoice.status_changed',
            default => 'invoice.updated',
        };
    }

    public function auditReason(array $changed): ?string
    {
        return in_array('is_waived', $changed, true) ? $this->waiver_reason : $this->discount_reason;
    }
}
