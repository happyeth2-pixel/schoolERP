<?php

namespace App\Services;

use App\Models\TimetableSlot;

/**
 * The three conflict rules the brief asks for, each checked independently against the SAME (term, day,
 * period) slot: a teacher cannot be in two classes at once, a room cannot host two classes at once, a
 * section cannot have two subjects at once. Returns an associative array keyed by the field the conflict is
 * about, ready to feed straight into `ValidationException::withMessages()` -- empty array = no conflicts.
 */
class TimetableConflicts
{
    public static function detect(array $attrs, ?int $excludingId = null): array
    {
        $conflicts = [];

        $base = fn () => TimetableSlot::where('term_id', $attrs['term_id'])
            ->where('day_of_week', $attrs['day_of_week'])
            ->where('period_id', $attrs['period_id'])
            ->when($excludingId, fn ($q) => $q->where('id', '!=', $excludingId));

        $teacherConflict = $base()->where('teacher_id', $attrs['teacher_id'])->with('section')->first();
        if ($teacherConflict) {
            $conflicts['teacher_id'] = "This teacher is already teaching {$teacherConflict->section->auditLabel()} at this time.";
        }

        if (! empty($attrs['room_id'])) {
            $roomConflict = $base()->where('room_id', $attrs['room_id'])->with('section')->first();
            if ($roomConflict) {
                $conflicts['room_id'] = "This room is already in use by {$roomConflict->section->auditLabel()} at this time.";
            }
        }

        $sectionConflict = $base()->where('section_id', $attrs['section_id'])->with('subject')->first();
        if ($sectionConflict) {
            $conflicts['section_id'] = "This class already has {$sectionConflict->subject->name} at this time.";
        }

        return $conflicts;
    }
}
