<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PaymentAccount extends Model
{
    use Audited;

    use SoftDeletes;

    protected $fillable = [
        'school_id',
        'branch_id',
        'type',
        'bank_key',
        'display_name',
        'account_number',
        'account_name',
        'logo_path',
        'sort_order',
    ];

    /**
     * The banks + mobile money providers we ship logos for out of the
     * box (public/images/banks/{key}.png). An owner isn't limited to
     * this list -- picking "Other" lets them type a name and upload
     * their own logo instead.
     */
    public const PRESETS = [
        'cbe' => ['label' => 'Commercial Bank of Ethiopia', 'type' => 'bank'],
        'awash' => ['label' => 'Awash Bank', 'type' => 'bank'],
        'abyssinia' => ['label' => 'Bank of Abyssinia', 'type' => 'bank'],
        'amhara' => ['label' => 'Amhara Bank', 'type' => 'bank'],
        'oromia' => ['label' => 'Oromia Bank', 'type' => 'bank'],
        'coop_oromia' => ['label' => 'Cooperative Bank of Oromia', 'type' => 'bank'],
        'nib' => ['label' => 'Nib International Bank', 'type' => 'bank'],
        'telebirr' => ['label' => 'Telebirr', 'type' => 'mobile_money'],
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** NULL branch_id = a school-wide account. */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** The accounts a student of this branch can be told to pay into: that branch's own plus the school-wide ones. */
    public function scopeAvailableForBranch($query, ?int $branchId)
    {
        return $query->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($w) => $w->orWhere('branch_id', $branchId)));
    }

    public function logoUrl(): ?string
    {
        if ($this->bank_key && isset(self::PRESETS[$this->bank_key])) {
            return asset("images/banks/{$this->bank_key}.png");
        }

        if ($this->logo_path) {
            return Storage::disk('public')->url($this->logo_path);
        }

        return null;
    }

    public function auditLabel(): string
    {
        return (string) $this->display_name;
    }
}
