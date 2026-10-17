<?php

namespace App\Models;

use App\Exceptions\DeletionBlockedException;
use App\Models\Concerns\NeverHardDeleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The audit trail: who did what to which record, when, and what it looked like
 * before and after. Entries are append-only -- never edited, never deleted.
 *
 * Most entries are written automatically for any model using the Audited trait;
 * multi-record actions (promotion, invoice generation, a role change) write one
 * explicit summary entry with record().
 */
class AuditLog extends Model
{
    use NeverHardDeleted;

    public $timestamps = false;

    /** Never written to the trail, whatever model they are on. */
    public const NEVER_LOGGED = [
        'password', 'remember_token', 'id_card_token', 'token', 'token_hash',
        'created_at', 'updated_at', 'deleted_at', 'email_verified_at',
    ];

    private static int $suspended = 0;

    protected $fillable = [
        'school_id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'subject_label',
        'before', 'after', 'note', 'ip_address', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new DeletionBlockedException('Audit log entries cannot be changed.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record one audit entry. $note is the reason / free-text explanation.
     */
    public static function record(
        User $actor,
        string $action,
        Model $subject,
        ?array $before = null,
        ?array $after = null,
        ?string $note = null,
    ): void {
        // Accounts that belong to no school (a platform-level account) have no school trail to write to.
        if ($actor->school_id === null) {
            return;
        }

        static::create([
            'school_id' => $actor->school_id,
            'user_id' => $actor->id,
            'action' => $action,
            'auditable_type' => $subject::class,
            'auditable_id' => $subject->getKey(),
            'subject_label' => method_exists($subject, 'auditLabel') ? mb_substr((string) $subject->auditLabel(), 0, 255) : null,
            'before' => $before,
            'after' => $after,
            'note' => $note,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Run a block whose model changes are described by ONE explicit entry the
     * caller writes itself, so the automatic per-model entries would only repeat it.
     */
    public static function suspended(callable $callback): mixed
    {
        self::$suspended++;

        try {
            return $callback();
        } finally {
            self::$suspended--;
        }
    }

    public static function isSuspended(): bool
    {
        return self::$suspended > 0;
    }
}
