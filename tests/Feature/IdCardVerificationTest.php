<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Branch;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The public /verify/{token} page is reachable by anyone holding a QR code.
 * It answers with one of four states (valid / expired / suspended / invalid),
 * calculated from the student's status and enrollment, and shows anything about
 * the person ONLY when the card is valid.
 */
class IdCardVerificationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private Branch $branch;
    private Section $section;
    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 09:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->school = School::create(['name' => 'Test School', 'code' => 'TST']);
        $this->branch = Branch::create(['school_id' => $this->school->id, 'name' => 'Main', 'code' => 'MAIN', 'is_main' => true]);
        $this->year = $this->makeYear('2026/2027', '2026-09-01', '2027-07-01', true);
        $grade = Grade::create(['school_id' => $this->school->id, 'name' => 'Grade 4', 'sort_order' => 4]);
        $this->section = Section::create(['branch_id' => $this->branch->id, 'grade_id' => $grade->id, 'academic_year_id' => $this->year->id, 'name' => 'A']);
        RateLimiter::clear('id-verify-miss:127.0.0.1');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------- the four states

    public static function validityMatrix(): array
    {
        return [
            'active + enrolled this year'                   => ['active', 'active-current', 'valid'],
            'active but the year ended >30 days ago'        => ['active', 'active-late', 'expired'],
            'active, enrollment is in a past year'          => ['active', 'active-old-year', 'expired'],
            'active, only a completed (promoted) enrollment' => ['active', 'completed', 'expired'],
            'graduated'                                     => ['graduated', 'completed', 'expired'],
            'suspended (still enrolled)'                    => ['suspended', 'active-current', 'suspended'],
            'active but never enrolled'                     => ['active', 'none', 'invalid'],
            'withdrawn'                                     => ['withdrawn', 'withdrawn', 'invalid'],
            'expelled'                                      => ['expelled', 'withdrawn', 'invalid'],
            'transferred'                                   => ['transferred', 'transferred', 'invalid'],
            'inactive'                                      => ['inactive', 'withdrawn', 'invalid'],
        ];
    }

    /** @dataProvider validityMatrix */
    public function test_state_is_calculated_from_status_and_enrollment(string $status, string $enrollment, string $expected): void
    {
        $student = $this->student(['status' => $status], $enrollment);

        $this->assertSame($expected, $student->idCardState());
        $this->assertSame($expected, $this->pageProps($this->get("/verify/{$student->id_card_token}")->assertOk())['state']);
    }

    public function test_the_year_end_grace_period_is_exactly_thirty_days(): void
    {
        $student = $this->student([], 'active-current');           // year ends 2027-07-01

        Carbon::setTestNow('2027-07-31 12:00:00');                  // 30 days after
        $this->assertSame('valid', $student->fresh()->idCardState());

        Carbon::setTestNow('2027-08-01 12:00:00');                  // 31 days after
        $this->assertSame('expired', $student->fresh()->idCardState());
    }

    public function test_the_state_follows_the_student_when_things_change(): void
    {
        $student = $this->student([], 'active-current');
        $this->assertSame('valid', $student->idCardState());

        $student->update(['status' => 'suspended']);
        $this->assertSame('suspended', $student->fresh()->idCardState());

        $student->fresh()->changeStatus('withdrawn');               // closes the enrollment too
        $this->assertSame('invalid', $student->fresh()->idCardState());

        $student->fresh()->changeStatus('active');                  // reopens the withdrawn enrollment
        $this->assertSame('valid', $student->fresh()->idCardState());
    }

    // ------------------------------------------------- what the page shows

    public function test_only_a_valid_card_shows_anything_about_the_person(): void
    {
        $valid = $this->student(['photo_path' => 'student-photos/abc.jpg'], 'active-current');
        $response = $this->get("/verify/{$valid->id_card_token}")->assertOk();

        $this->assertSame(['state', 'name', 'photo_url', 'school'], array_keys($this->pageProps($response)));
        $this->assertSame('Almaz Tesfaye', $this->pageProps($response)['name']);
        foreach (['Gebre', '2014-03-09', 'Bole Subcity', '0911223344', 'Old School', 'Grade 4', 'STU-SECRET', 'Mother'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent(), "valid page leaked '{$private}'");
        }

        foreach (['expired' => ['graduated', 'completed'], 'suspended' => ['suspended', 'active-current'], 'invalid' => ['expelled', 'withdrawn']] as $state => [$status, $enr]) {
            $student = $this->student(['status' => $status, 'photo_path' => 'student-photos/x.jpg'], $enr);
            $page = $this->get("/verify/{$student->id_card_token}")->assertOk();

            $this->assertSame(['state' => $state], $this->pageProps($page), "{$state} page exposed more than its state");
            foreach (['Almaz', 'Tesfaye', 'Test School', 'student-photos', $status === 'expelled' ? 'expelled' : 'xx-none'] as $secret) {
                $this->assertStringNotContainsString($secret, $page->getContent(), "{$state} page leaked '{$secret}'");
            }
        }
    }

    public function test_withdrawn_expelled_and_unknown_codes_are_indistinguishable(): void
    {
        $unknown = $this->pageProps($this->get('/verify/'.Str::random(40)));

        foreach (['withdrawn', 'expelled', 'inactive', 'transferred'] as $status) {
            RateLimiter::clear('id-verify-miss:127.0.0.1');
            $student = $this->student(['status' => $status], 'withdrawn');
            $this->assertSame($unknown, $this->pageProps($this->get("/verify/{$student->id_card_token}")), "{$status} revealed itself");
        }
        $this->assertSame(['state' => 'invalid'], $unknown);
    }

    // ------------------------------------------------- token independence

    public function test_the_qr_token_is_independent_of_the_student_id(): void
    {
        $a = $this->student([], 'active-current');
        $b = $this->student([], 'active-current');

        foreach ([$a, $b] as $s) {
            $this->assertStringNotContainsStringIgnoringCase($s->student_id_number, $s->id_card_token);
            $this->assertStringNotContainsStringIgnoringCase(Str::slug($s->first_name), $s->id_card_token);
        }
        $this->assertNotSame($a->id_card_token, $b->id_card_token);

        // renumbering / editing / changing status never touches the token
        $before = $a->id_card_token;
        $a->update(['student_id_number' => 'STU-2099-000001', 'first_name' => 'Renamed', 'status' => 'suspended']);
        $this->assertSame($before, $a->fresh()->id_card_token);

        // and the page can't be reached by an ID or ID number instead of the token
        foreach ([(string) $a->id, $a->student_id_number] as $notAToken) {
            $this->get('/verify/'.rawurlencode($notAToken))->assertNotFound();
        }
    }

    public function test_tokens_are_long_random_and_unique(): void
    {
        $tokens = collect(range(1, 200))->map(fn () => Student::newCardToken());

        $this->assertCount(200, $tokens->unique());
        $tokens->each(fn ($t) => $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $t));
    }

    public function test_malformed_tokens_are_rejected_before_touching_the_database(): void
    {
        foreach (['1', 'short', str_repeat('a', 39), str_repeat('a', 41), str_repeat('!', 40), '1 OR 1=1'] as $bad) {
            $this->get('/verify/'.rawurlencode($bad))->assertNotFound();
        }
    }

    // ------------------------------------------------- headers / limits / https

    public function test_the_page_is_never_cached_indexed_or_leaked_through_referer(): void
    {
        $response = $this->get("/verify/{$this->student([], 'active-current')->id_card_token}");

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('noindex', $response->headers->get('X-Robots-Tag'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function test_it_is_rate_limited(): void
    {
        $token = $this->student([], 'active-current')->id_card_token;

        for ($i = 1; $i <= 30; $i++) {
            $this->get("/verify/{$token}")->assertOk();
        }

        $this->get("/verify/{$token}")->assertStatus(429);
    }

    public function test_only_unknown_codes_count_as_misses_so_a_guard_scanning_expired_cards_is_never_locked_out(): void
    {
        $expired = $this->student(['status' => 'graduated'], 'completed')->id_card_token;
        for ($i = 1; $i <= 12; $i++) {
            $this->get("/verify/{$expired}")->assertOk();
        }

        for ($i = 1; $i <= 8; $i++) {
            $this->get('/verify/'.Str::random(40))->assertOk();
        }
        $this->get('/verify/'.Str::random(40))->assertStatus(429);
    }

    public function test_https_is_required_and_remembered_when_forced(): void
    {
        config(['app.force_https' => true]);
        $path = "/verify/{$this->student([], 'active-current')->id_card_token}";

        $this->get('http://school.test'.$path)->assertStatus(301)->assertRedirect('https://school.test'.$path);

        $secure = $this->get('https://school.test'.$path)->assertOk();
        $this->assertStringContainsString('max-age=31536000', $secure->headers->get('Strict-Transport-Security'));
    }

    public function test_https_is_not_forced_in_local_development(): void
    {
        config(['app.force_https' => false]);

        $this->get("http://school.test/verify/{$this->student([], 'active-current')->id_card_token}")->assertOk();
    }

    // ------------------------------------------------- helpers

    private function makeYear(string $name, string $start, string $end, bool $current): AcademicYear
    {
        return AcademicYear::create(['school_id' => $this->school->id, 'name' => $name, 'start_date' => $start, 'end_date' => $end, 'is_current' => $current]);
    }

    /**
     * $enrollment: none | active-current | active-late | active-old-year | completed | withdrawn | transferred
     */
    private function student(array $overrides = [], string $enrollment = 'active-current'): Student
    {
        $student = Student::create(array_merge([
            'school_id' => $this->school->id, 'branch_id' => $this->branch->id,
            'student_id_number' => 'STU-SECRET-'.Str::random(4),
            'first_name' => 'Almaz', 'father_name' => 'Tesfaye', 'grandfather_name' => 'Gebre',
            'mother_name' => 'Mother', 'gender' => 'female', 'date_of_birth' => '2014-03-09',
            'address' => 'Bole Subcity', 'emergency_contact' => '0911223344', 'previous_school' => 'Old School',
            'enrollment_date' => '2026-09-01', 'status' => 'active', 'id_card_token' => Student::newCardToken(),
        ], $overrides));

        match ($enrollment) {
            'none' => null,
            'active-current' => $this->enroll($student, $this->section, 'active'),
            'active-late' => $this->enrollInYear($student, '2025/2026', '2025-09-01', '2026-01-01', false, 'active'),   // year "ended" >30 days before test-now
            'active-old-year' => $this->enrollInYear($student, '2024/2025', '2024-09-01', '2025-07-01', false, 'active'),
            'completed' => $this->enrollInYear($student, '2025/2026', '2025-09-01', '2026-07-01', false, 'completed'),
            'withdrawn' => $this->enroll($student, $this->section, 'withdrawn'),
            'transferred' => $this->enroll($student, $this->section, 'transferred'),
        };

        return $student;
    }

    private function enroll(Student $student, Section $section, string $status): Enrollment
    {
        return Enrollment::create(['student_id' => $student->id, 'section_id' => $section->id, 'enrolled_on' => '2026-09-01', 'status' => $status]);
    }

    private function enrollInYear(Student $student, string $name, string $start, string $end, bool $current, string $status): Enrollment
    {
        $year = $this->makeYear($name.'-'.Str::random(3), $start, $end, $current);
        $section = Section::create(['branch_id' => $this->branch->id, 'grade_id' => $this->section->grade_id, 'academic_year_id' => $year->id, 'name' => 'B']);

        return $this->enroll($student, $section, $status);
    }

    /** Only the page-specific props (not the shared ones every page carries). */
    private function pageProps($response): array
    {
        $props = $response->original->getData()['page']['props'];

        return array_diff_key($props, array_flip(['errors', 'locale', 'auth', 'flash', 'notifications', 'monitoringUserPseudonym', 'today', 'parentChildren']));
    }
}
