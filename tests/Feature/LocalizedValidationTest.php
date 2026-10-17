<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Production-readiness audit finding: this project had no am/om/ti copies of Laravel's own
 * `validation.php`/`passwords.php`/`pagination.php` at all (only `attendance.php`, `auth.php`,
 * `notifications.php` existed) -- so no matter what language a user had chosen, every ordinary
 * form validation error ("The first name field is required.") was always English. `SetLocale`
 * middleware already correctly drives the backend locale from the signed-in user's own
 * `locale` column; the missing piece was simply having something translated for it to select.
 */
class LocalizedValidationTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    public function test_a_required_field_error_is_shown_in_the_users_own_chosen_language(): void
    {
        $admin = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);
        $admin->forceFill(['locale' => 'am'])->save();

        $response = $this->actingAs($admin)->post('/students', []);

        $response->assertSessionHasErrors();
        $errors = session('errors')->getBag('default')->get('first_name');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('ያስፈልጋል', $errors[0], 'an Amharic-speaking admin must see the Amharic validation message, not the English default');
    }

    public function test_an_english_locale_user_still_sees_the_english_message(): void
    {
        $admin = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);
        $admin->forceFill(['locale' => 'en'])->save();

        $response = $this->actingAs($admin)->post('/students', []);

        $errors = session('errors')->getBag('default')->get('first_name');
        $this->assertStringContainsString('is required', $errors[0]);
    }

    public function test_the_three_new_locale_files_are_syntactically_valid_and_fully_cover_english(): void
    {
        $en = require base_path('lang/en/validation.php');
        $enKeys = $this->flattenKeys($en);

        foreach (['am', 'om', 'ti'] as $locale) {
            $translated = require base_path("lang/{$locale}/validation.php");
            $this->assertSame($enKeys, $this->flattenKeys($translated), "{$locale}/validation.php must define exactly the same keys as the English reference");

            // passwords.php / pagination.php exist and are non-empty for all three locales too
            $this->assertNotEmpty(require base_path("lang/{$locale}/passwords.php"));
            $this->assertNotEmpty(require base_path("lang/{$locale}/pagination.php"));
        }
    }

    private function flattenKeys(array $arr, string $prefix = ''): array
    {
        $out = [];
        foreach ($arr as $key => $value) {
            if (in_array($key, ['custom', 'attributes'], true)) {
                continue;
            }
            $full = $prefix ? "{$prefix}.{$key}" : (string) $key;
            if (is_array($value)) {
                $out = array_merge($out, $this->flattenKeys($value, $full));
            } else {
                $out[] = $full;
            }
        }
        sort($out);

        return $out;
    }
}
