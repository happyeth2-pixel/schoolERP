<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Automatic audit trail for a model: every create / change / delete made by a
 * signed-in person is written to the audit log with the actor, the record, and
 * the before/after values of just the fields that changed (never passwords or
 * tokens).
 *
 * A model refines what is written by overriding:
 *   auditLabel()     a readable name for the record ("STU-014 Almaz Tesfaye")
 *   auditExcluded()  extra fields to keep out of the trail
 *   auditAction()    a more specific action name for a given change
 *   auditReason()    the reason stored with a given change
 *   auditsCreation() false when creations are recorded by one summary entry instead
 */
trait Audited
{
    public static function bootAudited(): void
    {
        static::created(function ($model) {
            if ($model->auditsCreation()) {
                $model->writeAudit($model->auditName().'.created', null, $model->auditSnapshot(array_keys($model->getAttributes())), $model->auditReason([]));
            }
        });

        static::updated(function ($model) {
            $fields = array_values(array_diff(array_keys($model->getChanges()), $model->auditIgnored()));
            if ($fields === []) {
                return;
            }

            $before = [];
            foreach ($fields as $field) {
                $before[$field] = $model->auditValue($model->getOriginal($field));
            }

            $model->writeAudit($model->auditAction($fields), $before, $model->auditSnapshot($fields), $model->auditReason($fields));
        });

        static::deleted(function ($model) {
            $archived = in_array(SoftDeletes::class, class_uses_recursive($model), true);
            $model->writeAudit(
                $model->auditName().'.'.($archived ? 'archived' : 'deleted'),
                $model->auditSnapshot(array_keys($model->getAttributes())),
                null,
                $archived ? 'Archived (kept for history)' : null,
            );
        });
    }

    /** e.g. FeeStructure -> "fee_structure" */
    public function auditName(): string
    {
        return Str::snake(class_basename($this));
    }

    public function auditLabel(): string
    {
        return (string) ($this->name ?? $this->title ?? class_basename($this).' #'.$this->getKey());
    }

    public function auditExcluded(): array
    {
        return [];
    }

    public function auditsCreation(): bool
    {
        return true;
    }

    /** @param list<string> $changed */
    public function auditAction(array $changed): string
    {
        return $this->auditName().(in_array('status', $changed, true) ? '.status_changed' : '.updated');
    }

    /** @param list<string> $changed */
    public function auditReason(array $changed): ?string
    {
        return null;
    }

    private function auditIgnored(): array
    {
        return array_merge(AuditLog::NEVER_LOGGED, $this->auditExcluded());
    }

    private function auditSnapshot(array $fields): array
    {
        $out = [];
        foreach (array_diff($fields, $this->auditIgnored()) as $field) {
            $out[$field] = $this->auditValue($this->getAttribute($field));
        }

        return $out;
    }

    private function auditValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value;
    }

    private function writeAudit(string $action, ?array $before, ?array $after, ?string $reason): void
    {
        $actor = Auth::user();

        // Nothing is recorded for changes with no signed-in person behind them
        // (seeders, console), or while a caller writes its own summary entry.
        if (! $actor || AuditLog::isSuspended()) {
            return;
        }

        AuditLog::record($actor, $action, $this, $before, $after, $reason);
    }
}
