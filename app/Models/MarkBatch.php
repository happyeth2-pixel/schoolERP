<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Exceptions\LockedRecordException;
use App\Models\Concerns\NeverHardDeleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarkBatch extends Model
{
    use NeverHardDeleted;

    use HasFactory;

    /** The only legal moves. published -> draft is possible only through reopenPublished(). */
    public const TRANSITIONS = [
        'draft' => ['submitted'],
        'submitted' => ['approved', 'draft'],
        'approved' => ['published', 'draft'],
        'published' => ['draft'],
    ];

    /** Set only by reopenPublished(); lets the state machine allow published -> draft. */
    public bool $reopening = false;

    protected static function booted(): void
    {
        static::updating(function (self $batch) {
            $from = $batch->getOriginal('status');
            $to = $batch->status;

            if ($from !== $to) {
                if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                    throw new LockedRecordException("A mark batch cannot go from {$from} to {$to}.");
                }
                if ($from === 'published' && ! $batch->reopening) {
                    throw new LockedRecordException('Published results can only be changed by reopening them, with a reason.');
                }
            } elseif ($from === 'published' && $batch->getDirty() !== []) {
                throw new LockedRecordException('Published results cannot be edited. Reopen them first, with a reason.');
            }
        });
    }

    protected $fillable = [
        'exam_id', 'section_id', 'subject_id', 'teacher_id', 'status',
        'submitted_at', 'approved_at', 'approved_by', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function marks(): HasMany
    {
        return $this->hasMany(Mark::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(MarkBatchPublication::class)->orderBy('version');
    }

    /** The version that is currently live (published and not yet reopened), if any. */
    public function livePublication(): ?MarkBatchPublication
    {
        return $this->publications()->whereNull('reopened_at')->latest('version')->first();
    }

    /** The teacher who currently holds the teaching assignment for this section and subject. */
    public function currentTeacherId(): ?int
    {
        return TeachingAssignment::where('section_id', $this->section_id)->where('subject_id', $this->subject_id)->value('teacher_id');
    }

    /**
     * approved -> published. Records a new publication version with a snapshot of the exact scores being
     * published, and who published them. The caller has checked permission and holds the batch lock.
     */
    public function publishBy(User $actor): MarkBatchPublication
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($actor) {
            $marks = $this->marks()->get(['enrollment_id', 'score', 'max_score'])->map(fn ($m) => [
                'enrollment_id' => $m->enrollment_id, 'score' => $m->score, 'max_score' => $m->max_score,
            ])->all();

            $this->update(['status' => 'published', 'published_at' => now()]);

            return $this->publications()->create([
                'version' => ((int) $this->publications()->max('version')) + 1,
                'published_by' => $actor->id,
                'published_at' => $this->published_at,
                'marks_snapshot' => $marks,
            ]);
        });
    }

    /**
     * published -> draft, the ONLY way published results become editable. Closes the live publication
     * version with who/when/why (it stays on record); the marks can then be corrected and published
     * again as a new version.
     */
    public function reopenPublished(User $actor, string $reason): ?MarkBatchPublication
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($actor, $reason) {
            $live = $this->livePublication();

            $this->reopening = true;
            try {
                // the batch goes back to the teacher who CURRENTLY teaches it (assignments may have changed since)
                $this->update([
                    'status' => 'draft', 'published_at' => null, 'approved_at' => null, 'approved_by' => null,
                    'submitted_at' => null, 'teacher_id' => $this->currentTeacherId() ?? $this->teacher_id,
                ]);
            } finally {
                $this->reopening = false;
            }

            $live?->update(['reopened_by' => $actor->id, 'reopened_at' => now(), 'reopen_reason' => $reason]);

            return $live;
        });
    }
}
