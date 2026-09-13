<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'school_id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'before', 'after', 'note', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record one audit entry. Called at every point where a mark score
     * changes or a result batch transitions status — the two places
     * where "who changed what, when" actually matters for a school.
     */
    public static function record(
        User $actor,
        string $action,
        Model $subject,
        ?array $before = null,
        ?array $after = null,
        ?string $note = null,
    ): void {
        static::create([
            'school_id' => $actor->school_id,
            'user_id' => $actor->id,
            'action' => $action,
            'auditable_type' => $subject::class,
            'auditable_id' => $subject->getKey(),
            'before' => $before,
            'after' => $after,
            'note' => $note,
            'created_at' => now(),
        ]);
    }
}
