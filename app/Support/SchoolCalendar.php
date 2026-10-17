<?php

namespace App\Support;

use App\Models\School;
use App\Models\User;
use App\Services\EthiopianCalendar;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The presentation/business layer for dates. NOTHING here changes what is
 * stored: dates stay Gregorian DATE columns and timestamps stay UTC. This class
 * only decides
 *   - what "today" is for a school day (Addis Ababa time, not UTC), and
 *   - how a date is SHOWN, according to the school's calendar preference
 *     (ethiopian | gregorian | both).
 * The Gregorian<->Ethiopian arithmetic itself lives in EthiopianCalendar.
 */
class SchoolCalendar
{
    public static function timezone(): string
    {
        return config('app.business_timezone', 'Africa/Addis_Ababa');
    }

    /**
     * Today's date for school purposes (attendance day, "today" dashboards,
     * enrollment dates...). Using UTC here would make the school day roll over
     * at 03:00 Addis time instead of midnight.
     */
    public static function today(): Carbon
    {
        return Carbon::now(self::timezone())->startOfDay();
    }

    /**
     * The instant (in UTC, as timestamps are stored) at which the given
     * Gregorian date begins in the school's business timezone -- so a filter
     * for "15 Oct" means the Addis Ababa day, not the UTC day.
     */
    public static function businessDayStartUtc(string $isoDate): Carbon
    {
        return Carbon::parse($isoDate, self::timezone())->startOfDay()->utc();
    }

    public static function preferenceOf(School|User|null $context): string
    {
        $school = $context instanceof User ? $context->school : $context;

        return $school?->calendar_preference ?? 'both';
    }

    private static function localeOf(School|User|null $context): string
    {
        return ($context instanceof User ? $context->locale : null) ?? 'en';
    }

    /**
     * A calendar DATE (no time of day, no timezone shift), e.g. an enrollment
     * date or academic-year start.
     */
    public static function date(CarbonInterface|string|null $value, School|User|null $context = null, ?string $locale = null): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $iso = $value instanceof CarbonInterface ? $value->format('Y-m-d') : substr($value, 0, 10);

        return self::render($iso, self::preferenceOf($context), $locale ?? self::localeOf($context));
    }

    /**
     * A TIMESTAMP (stored in UTC): converted to Addis time, then shown as the
     * date in the school's calendar plus the clock time.
     */
    public static function dateTime(CarbonInterface|string|null $value, School|User|null $context = null, ?string $locale = null): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $local = Carbon::parse($value)->setTimezone(self::timezone());

        return self::render($local->format('Y-m-d'), self::preferenceOf($context), $locale ?? self::localeOf($context)).' '.$local->format('g:i A');
    }

    private static function render(string $iso, string $preference, string $locale): string
    {
        $gregorian = Carbon::parse($iso)->format('M j, Y');

        return match ($preference) {
            'gregorian' => $gregorian,
            'ethiopian' => EthiopianCalendar::format($iso, $locale),
            default => EthiopianCalendar::format($iso, $locale)." ({$gregorian})",
        };
    }
}
