<?php

namespace App\Support\Localization;

/**
 * Amharic and Tigrinya verbs/pronouns referring to a person agree with that person's grammatical
 * gender (unlike English, and unlike Oromo's verb forms for the same sentences) -- a notification
 * about a student that always used the masculine form was simply wrong for every girl it was sent
 * about. A call site picks the base translation key (e.g. 'notifications.student_marked_absent');
 * this appends '_male'/'_female' so lang/{locale}/notifications.php can hold the correct form of
 * each, the same "two outcomes, two keys" shape asset_repair_completed's body_fixed/body_damaged
 * already uses.
 */
class GenderedText
{
    public static function key(string $base, ?string $gender): string
    {
        return $gender === 'female' ? "{$base}_female" : "{$base}_male";
    }
}
