<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    use Audited;

    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'logo_path',
        'region',
        'city',
        'subcity',
        'woreda',
        'phone',
        'email',
        'calendar_preference',
        'discount_limit_percent',
        'purchase_approval_threshold',
        'waiver_limit_amount',
        'allow_overlapping_terms',
        'allow_negative_stock',
        'missing_mark_policy',
        'enabled_languages',
        'default_ar_account_id',
        'default_ap_account_id',
        'default_cash_account_id',
        'default_revenue_account_id',
        'default_expense_account_id',
        'default_discount_account_id',
        'default_waiver_account_id',
        'pension_employee_rate',
        'pension_employer_rate',
        'default_salary_expense_account_id',
        'default_pension_expense_account_id',
        'default_tax_payable_account_id',
        'default_pension_payable_account_id',
        'default_salary_payable_account_id',
        'library_loan_days',
        'library_fine_per_day',
    ];

    protected function casts(): array
    {
        return [
            'enabled_languages' => 'array',
            'allow_overlapping_terms' => 'boolean',
            'allow_negative_stock' => 'boolean',
            'phone' => \App\Casts\Phone::class,
            'pension_employee_rate' => 'decimal:4',
            'pension_employer_rate' => 'decimal:4',
        ];
    }

    /** What the school has chosen to do when a published exam has no score for a student. */
    public const MISSING_MARK_POLICIES = [
        'exclude' => 'Leave it out. Only exams the student has a score for count, and their weights are re-adjusted to add up to 100%.',
        'zero' => 'Count it as 0%. A published exam with no score for the student pulls their average down.',
    ];

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function academicYears(): HasMany
    {
        return $this->hasMany(AcademicYear::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    public function gradeBands(): HasMany
    {
        return $this->hasMany(GradeBand::class);
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class);
    }

    public function paymentAccounts(): HasMany
    {
        return $this->hasMany(PaymentAccount::class)->orderBy('sort_order');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function journals(): HasMany
    {
        return $this->hasMany(Journal::class);
    }

    public function accountingPeriods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class);
    }

    public function defaultArAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_ar_account_id');
    }

    public function defaultApAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_ap_account_id');
    }

    public function defaultCashAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_cash_account_id');
    }

    public function defaultRevenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_revenue_account_id');
    }

    public function defaultExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_expense_account_id');
    }

    public function defaultDiscountAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_discount_account_id');
    }

    public function defaultWaiverAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_waiver_account_id');
    }

    public function defaultSalaryExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_salary_expense_account_id');
    }

    public function defaultPensionExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_pension_expense_account_id');
    }

    public function defaultTaxPayableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_tax_payable_account_id');
    }

    public function defaultPensionPayableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_pension_payable_account_id');
    }

    public function defaultSalaryPayableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_salary_payable_account_id');
    }

    public function payrollTaxBrackets(): HasMany
    {
        return $this->hasMany(PayrollTaxBracket::class)->orderBy('min_amount');
    }

    public function payrollRuns(): HasMany
    {
        return $this->hasMany(PayrollRun::class);
    }

    /** Whatever accounting period covers a date, open or closed -- a draft entry may belong to a closed
     * period (it hasn't moved anything yet); only App\Services\Ledger::post() insists the period be open. */
    public function periodFor(string $date): ?AccountingPeriod
    {
        return $this->accountingPeriods()->where('start_date', '<=', $date)->where('end_date', '>=', $date)->first();
    }

    public function auditAction(array $changed): string
    {
        return 'school.settings_changed';
    }

    /** The logo as a self-contained `data:` URI, or null if none is set -- for PDF templates, which
     * dompdf renders with remote image fetching disabled (config/dompdf.php `enable_remote`), so a
     * plain public-disk URL would silently fail to render. Embedding the bytes directly sidesteps
     * that entirely, and the file's own content (never its extension) decides the MIME type. */
    public function logoDataUri(): ?string
    {
        if (! $this->logo_path || ! \Illuminate\Support\Facades\Storage::disk('public')->exists($this->logo_path)) {
            return null;
        }

        [, $mime] = \App\Support\SafeUpload::servingType($this->logo_path);
        $bytes = \Illuminate\Support\Facades\Storage::disk('public')->get($this->logo_path);

        return "data:{$mime};base64,".base64_encode($bytes);
    }
}
