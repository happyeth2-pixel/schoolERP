<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id', 'academic_year_id', 'term_id', 'period_label', 'frequency', 'period_start',
        'invoice_number', 'discount_amount', 'discount_reason', 'is_waived',
    ];

    protected function casts(): array
    {
        return [
            'is_waived' => 'boolean',
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

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * These are always computed live from the actual line items and
     * verified payments — never cached — so there is no separate
     * "status" field that can drift out of sync with reality.
     */
    public function subtotal(): float
    {
        return (float) $this->items->sum('amount');
    }

    public function totalAmount(): float
    {
        return max(0, $this->subtotal() - (float) $this->discount_amount);
    }

    public function paidAmount(): float
    {
        return (float) $this->payments->where('status', 'verified')->sum('amount');
    }

    public function balance(): float
    {
        return round($this->totalAmount() - $this->paidAmount(), 2);
    }

    public function statusLabel(): string
    {
        if ($this->is_waived) {
            return 'waived';
        }

        $balance = $this->balance();
        if ($balance <= 0) {
            return 'paid';
        }

        return $this->paidAmount() > 0 ? 'partially_paid' : 'unpaid';
    }
}
