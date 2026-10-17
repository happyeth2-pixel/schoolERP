<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * The student's photo is taken or chosen by STAFF, at registration or later on the profile. The student uploads nothing
 * and gets no login. The photo is optional, checked by its content, re-encoded under a random name on the same disk as
 * before, and only people who may edit student records (inside their own branch) can add, replace or remove it.
 */
class StudentPhotoTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private User $registrar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        Storage::fake('public');
        $this->registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students', 'manage-parents']);
    }

    private function registration(array $over = []): array
    {
        return $over + ['first_name' => 'Photo', 'father_name' => 'Kid', 'gender' => 'female', 'enrollment_date' => '2026-09-10', 'section_id' => $this->sectionA->id, 'guardian_mode' => 'none'];
    }

    private function register(array $over = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->registrar)->post('/students', $this->registration($over));
    }

    private function jpg(int $w = 1600, int $h = 1200, string $name = 'camera.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, $w, $h);
    }

    private function newStudent(): ?Student
    {
        return Student::where('first_name', 'Photo')->first();
    }

    // ================================================================== registration

    public function test_a_photo_taken_at_registration_is_saved_with_the_student(): void
    {
        $this->register(['photo' => $this->jpg()])->assertSessionHasNoErrors()->assertRedirect(route('students.index'));

        $s = $this->newStudent();
        $this->assertMatchesRegularExpression('#^student-photos/[A-Za-z0-9]{40}\.jpg$#', $s->photo_path, 'random name, never the uploaded filename');
        Storage::disk('public')->assertExists($s->photo_path);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($s->photo_path));
        $this->assertLessThanOrEqual(800, max($w, $h), 'shrunk and re-encoded, as before');
    }

    public function test_the_photo_is_optional_and_registration_works_without_it(): void
    {
        $this->register()->assertSessionHasNoErrors();

        $this->assertNull($this->newStudent()->photo_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_registering_with_a_photo_creates_no_student_login_and_needs_no_account(): void
    {
        $users = User::count();
        $this->register(['photo' => $this->jpg()])->assertSessionHasNoErrors();

        $this->assertSame($users, User::count(), 'a student has no login; the guardian mode was "none"');
    }

    public function test_an_invalid_photo_is_refused_safely_and_nothing_is_registered(): void
    {
        $bad = [
            'disguised php' => UploadedFile::fake()->createWithContent('face.png', '<?php echo 1;'),
            'svg with a script' => UploadedFile::fake()->createWithContent('face.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'a pdf' => UploadedFile::fake()->createWithContent('face.jpg', "%PDF-1.4\n%%EOF"),
            'not an image at all' => UploadedFile::fake()->create('face.exe', 10, 'application/x-msdownload'),
        ];

        foreach ($bad as $why => $file) {
            $this->register(['photo' => $file])->assertSessionHasErrors('photo');
            $this->assertNull($this->newStudent(), "refused: {$why}");
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_photo_over_the_size_limit_is_refused(): void
    {
        $this->register(['photo' => UploadedFile::fake()->image('big.jpg', 300, 300)->size(3000)])->assertSessionHasErrors('photo');
        $this->assertNull($this->newStudent());
    }

    public function test_a_gif_is_not_accepted_only_jpg_and_png(): void
    {
        $gif = UploadedFile::fake()->createWithContent('face.gif', "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;");
        $this->register(['photo' => $gif])->assertSessionHasErrors('photo');
    }

    public function test_a_png_is_accepted_too(): void
    {
        $this->register(['photo' => UploadedFile::fake()->image('face.png', 400, 400)])->assertSessionHasNoErrors();

        $this->assertStringEndsWith('.png', $this->newStudent()->photo_path);
    }

    public function test_only_people_who_may_register_students_can_send_a_photo(): void
    {
        $this->register(['photo' => $this->jpg()], $this->makeUser('teacher', $this->branchA->id))->assertForbidden();
        $this->register(['photo' => $this->jpg()], $this->makeUser('accountant', $this->branchA->id))->assertForbidden();
        $this->register(['photo' => $this->jpg()], $this->makeUser('auditor', null))->assertForbidden();
        $this->register(['photo' => $this->jpg()], $this->makeParent($this->studentA))->assertForbidden();

        $this->assertNull($this->newStudent());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_photo_cannot_place_a_student_in_another_branch(): void
    {
        $this->register(['photo' => $this->jpg(), 'section_id' => $this->sectionB->id])->assertForbidden();
        $this->assertNull($this->newStudent());
        $this->assertSame([], Storage::disk('public')->allFiles(), 'nothing is stored for a refused registration');
    }

    // ================================================================== the profile: view, replace, remove

    public function test_the_profile_shows_the_current_photo_and_the_controls_only_to_people_who_manage_students(): void
    {
        $this->register(['photo' => $this->jpg()]);
        $s = $this->newStudent();

        $page = $this->props($this->actingAs($this->registrar)->get("/students/{$s->id}/edit")->assertOk());
        $this->assertNotNull($page['student']['photo_url']);
        $this->assertTrue($page['canManage']);

        $auditor = $this->props($this->actingAs($this->makeUser('auditor', null))->get("/students/{$s->id}/edit")->assertOk());
        $this->assertFalse($auditor['canManage'], 'an auditor may look but is given no controls');
    }

    public function test_replacing_the_photo_stores_the_new_one_and_deletes_the_old_file(): void
    {
        $this->register(['photo' => $this->jpg()]);
        $s = $this->newStudent();
        $old = $s->photo_path;

        $this->actingAs($this->registrar)->post("/students/{$s->id}/photo", ['photo' => $this->jpg(900, 900, 'retake.jpg')])->assertSessionHasNoErrors();

        $new = $s->fresh()->photo_path;
        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertExists($new);
        Storage::disk('public')->assertMissing($old);
        $this->assertCount(1, Storage::disk('public')->allFiles(), 'no orphan files pile up');
    }

    public function test_a_refused_replacement_keeps_the_photo_the_student_already_had(): void
    {
        $this->register(['photo' => $this->jpg()]);
        $s = $this->newStudent();
        $old = $s->photo_path;

        $this->actingAs($this->registrar)->post("/students/{$s->id}/photo", ['photo' => UploadedFile::fake()->createWithContent('x.png', '<?php')])->assertSessionHasErrors('photo');

        $this->assertSame($old, $s->fresh()->photo_path);
        Storage::disk('public')->assertExists($old);
    }

    public function test_a_photo_can_be_added_later_to_a_student_who_had_none(): void
    {
        $this->register();
        $s = $this->newStudent();

        $this->actingAs($this->registrar)->post("/students/{$s->id}/photo", ['photo' => $this->jpg()])->assertSessionHasNoErrors();

        $this->assertNotNull($s->fresh()->photo_path);
    }

    public function test_removing_the_photo_deletes_the_file_and_keeps_the_student(): void
    {
        $this->register(['photo' => $this->jpg()]);
        $s = $this->newStudent();
        $path = $s->photo_path;

        $this->actingAs($this->registrar)->delete("/students/{$s->id}/photo")->assertSessionHasNoErrors();

        $this->assertNull($s->fresh()->photo_path);
        Storage::disk('public')->assertMissing($path);
        $this->assertNotNull(Student::find($s->id), 'only the photo goes');
        $this->assertSame('active', $s->fresh()->status);
    }

    public function test_removing_when_there_is_no_photo_is_harmless(): void
    {
        $this->register();
        $s = $this->newStudent();

        $this->actingAs($this->registrar)->delete("/students/{$s->id}/photo")->assertSessionHas('success');
        $this->assertNull($s->fresh()->photo_path);
    }

    public function test_photo_changes_are_recorded_in_the_audit_trail_with_who_did_them(): void
    {
        $this->register(['photo' => $this->jpg()]);
        $s = $this->newStudent();
        AuditLog::query()->delete();

        $this->actingAs($this->registrar)->post("/students/{$s->id}/photo", ['photo' => $this->jpg(500, 500)]);
        $this->actingAs($this->registrar)->delete("/students/{$s->id}/photo");

        $logs = AuditLog::where('auditable_id', $s->id)->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame($this->registrar->id, $logs[0]->user_id);
        $this->assertArrayHasKey('photo_path', $logs[1]->after);
        $this->assertNull($logs[1]->after['photo_path']);
    }

    // ================================================================== who may change a photo

    public function test_only_people_who_manage_students_can_replace_or_remove_a_photo(): void
    {
        $this->register(['photo' => $this->jpg()]);
        $s = $this->newStudent();
        $path = $s->photo_path;

        foreach ([$this->makeUser('teacher', $this->branchA->id), $this->makeUser('accountant', $this->branchA->id), $this->makeUser('auditor', null), $this->makeParent($s)] as $who) {
            $this->actingAs($who)->post("/students/{$s->id}/photo", ['photo' => $this->jpg()])->assertForbidden();
            $this->actingAs($who)->delete("/students/{$s->id}/photo")->assertForbidden();
        }
        $this->assertSame($path, $s->fresh()->photo_path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_a_branch_admin_cannot_touch_another_branchs_student_photo(): void
    {
        $this->studentB->update(['photo_path' => 'student-photos/'.str_repeat('b', 40).'.jpg']);
        Storage::disk('public')->put($this->studentB->photo_path, 'x');

        $this->actingAs($this->registrar)->post("/students/{$this->studentB->id}/photo", ['photo' => $this->jpg()])->assertForbidden();
        $this->actingAs($this->registrar)->delete("/students/{$this->studentB->id}/photo")->assertForbidden();
        $this->assertNotNull($this->studentB->fresh()->photo_path);

        $this->actingAs($this->owner)->delete("/students/{$this->studentB->id}/photo")->assertSessionHasNoErrors();
        $this->assertNull($this->studentB->fresh()->photo_path);
    }

    // ================================================================== a bug found on the way: student numbers with a gap

    public function test_student_numbers_never_collide_when_the_existing_numbers_have_a_gap(): void
    {
        // three students with numbers 1, 2 and 44: counting them (3) and adding one would issue 4 ... then 5, and
        // the count-based rule collided as soon as it reached a number that already existed
        foreach ([1, 2, 44] as $n) {
            $s = $this->makeStudent($this->branchA, $this->sectionA, "Gap{$n}", sprintf('STU-2026-%06d', $n));
        }

        $this->register(['first_name' => 'Photo'])->assertSessionHasNoErrors();
        $this->register(['first_name' => 'Photo Two'])->assertSessionHasNoErrors();

        $numbers = Student::whereIn('first_name', ['Photo', 'Photo Two'])->orderBy('id')->pluck('student_id_number')->all();
        $this->assertSame(['STU-2026-000045', 'STU-2026-000046'], $numbers);
    }

    public function test_a_number_added_by_hand_after_the_counter_started_is_skipped(): void
    {
        $this->register(['first_name' => 'Photo'])->assertSessionHasNoErrors();
        $this->makeStudent($this->branchA, $this->sectionA, 'Hand', 'STU-2026-000002');

        $this->register(['first_name' => 'Photo Two'])->assertSessionHasNoErrors();

        $this->assertSame('STU-2026-000003', Student::where('first_name', 'Photo Two')->value('student_id_number'));
    }

    // ================================================================== the existing storage rules did not move

    public function test_the_stored_photo_is_on_the_same_disk_and_under_the_same_folder_as_before(): void
    {
        $this->register(['photo' => $this->jpg()]);
        $s = $this->newStudent();

        $this->assertStringStartsWith('student-photos/', $s->photo_path);
        Storage::disk('public')->assertExists($s->photo_path);
        Storage::disk('local')->assertMissing($s->photo_path);
    }

    public function test_the_original_upload_name_never_reaches_the_stored_path(): void
    {
        $this->register(['photo' => $this->jpg(600, 600, "../../evil name.jpg")]);

        $this->assertStringNotContainsString('evil', $this->newStudent()->photo_path);
        $this->assertStringNotContainsString('..', $this->newStudent()->photo_path);
    }

    public function test_the_student_gets_no_login_and_no_self_service_route_appears(): void
    {
        $this->register(['photo' => $this->jpg()]);
        $s = $this->newStudent();

        $this->assertSame(0, $s->guardians()->count());
        $this->assertNull(User::where('name', 'like', '%Photo%')->first());
    }
}
