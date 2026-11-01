<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One book actually sold to one student -- the record BookSales reads to know which of a grade's books a
 * student still needs. Recorded explicitly by BookSales (like StudentServices does for AuditLog), not via
 * the Audited trait: a purchase is itself the audit-worthy event, there is nothing to "change" afterward.
 */
class StudentBookPurchase extends Model
{
    use HasFactory;

    protected $fillable = ['student_id', 'book_item_id', 'invoice_item_id', 'purchased_on', 'price_at_sale'];

    protected function casts(): array
    {
        return ['purchased_on' => 'date'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function bookItem(): BelongsTo
    {
        return $this->belongsTo(BookItem::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }
}
