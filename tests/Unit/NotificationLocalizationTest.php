<?php

namespace Tests\Unit;

use App\Support\Localization\NotificationLocale;
use Tests\TestCase;

/**
 * The backend counterpart to tests/js/i18n-parity.mjs: lang/{locale}/notifications.php and
 * attendance.php must define exactly the same keys as the English reference, and
 * NotificationLocale must actually resolve to the recipient's own language -- not whoever
 * triggered the action (see the doc-comment on NotificationLocale itself for why that
 * distinction is the entire point of this class existing).
 */
class NotificationLocalizationTest extends TestCase
{
    private const LOCALES = ['am', 'om', 'ti'];

    private const FILES = ['notifications', 'attendance'];

    public function test_every_locale_defines_the_same_keys_as_english(): void
    {
        foreach (self::FILES as $file) {
            $en = require lang_path("en/{$file}.php");
            $enKeys = $this->flatten($en);

            foreach (self::LOCALES as $locale) {
                $path = lang_path("{$locale}/{$file}.php");
                $this->assertFileExists($path, "lang/{$locale}/{$file}.php does not exist.");

                $translated = require $path;
                $translatedKeys = $this->flatten($translated);

                $missing = array_diff($enKeys, $translatedKeys);
                $this->assertEmpty($missing, "{$locale}/{$file}.php is missing keys: ".implode(', ', $missing));

                $extra = array_diff($translatedKeys, $enKeys);
                $this->assertEmpty($extra, "{$locale}/{$file}.php has stale keys no longer in English: ".implode(', ', $extra));
            }
        }
    }

    public function test_notification_locale_prefers_the_recipients_own_language_over_the_ambient_one(): void
    {
        // The ambient/request locale (e.g. set by whoever is currently signed in and
        // triggering the notification) must never leak into what the recipient sees.
        app()->setLocale('am');

        $recipient = (object) ['locale' => 'ti', 'school' => null];
        $this->assertSame('ti', NotificationLocale::for($recipient));
    }

    public function test_notification_locale_falls_back_to_the_schools_first_enabled_language(): void
    {
        $school = (object) ['enabled_languages' => ['om', 'en']];
        $recipient = (object) ['locale' => null, 'school' => $school];

        $this->assertSame('om', NotificationLocale::for($recipient));
    }

    public function test_notification_locale_falls_back_to_english_for_an_unsupported_value(): void
    {
        $recipient = (object) ['locale' => 'fr', 'school' => null];

        $this->assertSame('en', NotificationLocale::for($recipient));
    }

    /** Flattens a nested translation array to dot-notation keys, e.g. ['a']['b'] -> "a.b". */
    private function flatten(array $data, string $prefix = ''): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? $key : "{$prefix}.{$key}";
            if (is_array($value)) {
                $keys = array_merge($keys, $this->flatten($value, $path));
            } else {
                $keys[] = $path;
            }
        }

        return $keys;
    }
}
