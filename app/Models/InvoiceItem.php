<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\NeverHardDeleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    use NeverHardDeleted;

    use HasFactory;

    protected $fillable = [
        'invoice_id', 'item_type', 'fee_structure_id', 'optional_service_id', 'book_item_id',
        'description', 'quantity', 'unit_amount', 'amount',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class);
    }

    public function optionalService(): BelongsTo
    {
        return $this->belongsTo(OptionalService::class);
    }

    public function bookItem(): BelongsTo
    {
        return $this->belongsTo(BookItem::class);
    }
}
