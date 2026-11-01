<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A textbook (or workbook/stationery set) the school sells for one grade, scoped exactly like
 * FeeStructure (branch, grade, academic year) -- different grades need different books at different
 * prices. Sold through BookSales, never billed automatically or repeated: a student buys each one at
 * most once (student_book_purchases.unique(student_id, book_item_id)).
 */
class BookItem extends Model
{
    use Audited;

    use SoftDeletes;

    use HasFactory;

    protected $fillable = ['branch_id', 'grade_id', 'academic_year_id', 'title', 'size', 'price', 'revenue_account_id'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(StudentBookPurchase::class);
    }
}
