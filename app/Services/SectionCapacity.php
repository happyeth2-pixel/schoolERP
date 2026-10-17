<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * What happens when a section has a capacity: nobody is placed beyond it, whether they
 * arrive by enrolment, transfer, promotion or re-activation -- unless an authorized
 * administrator (override-section-capacity) overrides it WITH a reason, which is
 * written to the audit trail.
 *
 * "Occupied" = active enrolments (Enrollment::active(): status active and the student
 * still enrolled). A section with no capacity set is unlimited.
 *
 * Every placement calls claim()/grant() INSIDE its transaction. They lock the section
 * row first, so two people filling the last seat at once are handled one after the other.
 */
class SectionCapacity
{
    public static function occupied(Section $section): int
    {
        return Enrollment::active()->where('section_id', $section->id)->count();
    }

    /** Seats still free, or null when the section has no capacity limit. */
    public static function seatsLeft(Section $section): ?int
    {
        return $section->capacity === null ? null : max(0, $section->capacity - self::occupied($section));
    }

    /** Fields the forms show next to a section ("Grade 1 A — 3 seats left"). */
    public static function describe(Section $section): array
    {
        $occupied = self::occupied($section);

        return [
            'capacity' => $section->capacity,
            'occupied' => $occupied,
            'seats_left' => $section->capacity === null ? null : max(0, $section->capacity - $occupied),
            'full' => $section->capacity !== null && $occupied >= $section->capacity,
        ];
    }

    /**
     * Reserve $count places, or refuse.
     *
     * @throws ValidationException naming $field when there is no room and no valid override
     */
    public static function claim(Section $section, int $count, User $actor, bool $override = false, ?string $reason = null, string $field = 'section_id'): void
    {
        $granted = self::grant($section, $count, $actor, $override, $reason);

        if ($granted < $count) {
            $left = self::seatsLeft($section);

            throw ValidationException::withMessages([$field => self::fullMessage($section, $left).self::overrideHint($actor)]);
        }
    }

    /**
     * How many of $wanted places can be given (all of them with a valid override).
     * Locks the section row; call inside a transaction.
     */
    public static function grant(Section $section, int $wanted, User $actor, bool $override = false, ?string $reason = null): int
    {
        $locked = Section::whereKey($section->id)->lockForUpdate()->firstOrFail();

        if ($locked->capacity === null) {
            return $wanted;
        }

        $occupied = self::occupied($locked);
        $left = max(0, $locked->capacity - $occupied);

        if ($wanted <= $left) {
            return $wanted;
        }

        if ($override && $actor->can('override-section-capacity') && strlen(trim((string) $reason)) >= 3) {
            AuditLog::record($actor, 'section.capacity_overridden', $locked,
                ['capacity' => $locked->capacity, 'occupied' => $occupied],
                ['occupied_after' => $occupied + $wanted, 'placed' => $wanted, 'over_by' => $occupied + $wanted - $locked->capacity],
                trim($reason)
            );

            return $wanted;
        }

        return $left;
    }

    public static function fullMessage(Section $section, ?int $left): string
    {
        $name = trim(($section->grade?->name ?? '').' '.$section->name);

        return $left === 0
            ? "{$name} is full ({$section->capacity} of {$section->capacity} places taken)."
            : "{$name} has only {$left} place(s) left.";
    }

    private static function overrideHint(User $actor): string
    {
        return $actor->can('override-section-capacity')
            ? ' Tick "Override capacity" and give a reason to place them anyway.'
            : ' Ask an owner or administrator to override the capacity if this is necessary.';
    }
}
