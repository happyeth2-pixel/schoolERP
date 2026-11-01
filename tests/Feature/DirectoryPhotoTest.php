<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * The Staff, Students and Parents lists each show a photo-or-fallback avatar (Components/Avatar) so a listing
 * is never a wall of plain text and a missing photo is never a blank gap -- but the list only ever shows one
 * if the page is actually given a `photo_url`. Covers that StaffController/StudentController/ParentController
 * index() all pass it through, null when there is no photo, a real disk URL when there is one.
 */
class DirectoryPhotoTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        Storage::fake('public');
    }

    public function test_the_staff_list_carries_a_photo_url_only_for_those_who_have_one(): void
    {
        $withPhoto = $this->makeUser('teacher', $this->branchA->id, []);
        $withPhoto->update(['profile_photo_path' => 'profile-photos/teacher.jpg']);
        Storage::disk('public')->put($withPhoto->profile_photo_path, 'x');
        $withoutPhoto = $this->makeUser('teacher', $this->branchA->id, []);

        $rows = collect($this->props($this->actingAs($this->owner)->get(route('staff.index')))['staff']);

        $this->assertSame(Storage::disk('public')->url('profile-photos/teacher.jpg'), $rows->firstWhere('id', $withPhoto->id)['photo_url']);
        $this->assertNull($rows->firstWhere('id', $withoutPhoto->id)['photo_url']);
    }

    public function test_the_parents_list_carries_a_photo_url_only_for_those_who_have_one(): void
    {
        $withPhoto = $this->makeParent($this->studentA);
        $withPhoto->update(['profile_photo_path' => 'profile-photos/parent.jpg']);
        Storage::disk('public')->put($withPhoto->profile_photo_path, 'x');
        $withoutPhoto = $this->makeParent($this->studentB);

        $rows = collect($this->props($this->actingAs($this->owner)->get(route('parents.index')))['parents']);

        $this->assertSame(Storage::disk('public')->url('profile-photos/parent.jpg'), $rows->firstWhere('id', $withPhoto->id)['photo_url']);
        $this->assertNull($rows->firstWhere('id', $withoutPhoto->id)['photo_url']);
    }

    public function test_the_students_list_carries_a_photo_url_only_for_those_who_have_one(): void
    {
        $this->studentA->update(['photo_path' => 'student-photos/almaz.jpg']);
        Storage::disk('public')->put($this->studentA->photo_path, 'x');

        $rows = collect($this->props($this->actingAs($this->owner)->get(route('students.index')))['students']);

        $this->assertSame(Storage::disk('public')->url('student-photos/almaz.jpg'), $rows->firstWhere('id', $this->studentA->id)['photo_url']);
        $this->assertNull($rows->firstWhere('id', $this->studentB->id)['photo_url']);
    }
}
