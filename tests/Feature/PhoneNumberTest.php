<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Branch;
use App\Models\Grade;
use App\Models\School;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use App\Support\PhoneNumber;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhoneNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_common_way_of_typing_a_number_gives_the_same_canonical_form(): void
    {
        foreach (['0911223344', '+251911223344', '251911223344', '911223344', '00251911223344', '0911 22 33 44', '+251-911-22-33-44', '(0911) 223344', ' 0911.223.344 '] as $input) {
            $this->assertSame('+251911223344', PhoneNumber::normalize($input), "input: {$input}");
        }
        $this->assertSame('+251711223344', PhoneNumber::normalize('0711223344'));
        $this->assertSame('+251111234567', PhoneNumber::normalize('011 123 4567'), 'landline');
        $this->assertSame('+447700900123', PhoneNumber::normalize('+44 7700 900123'), 'foreign numbers stay international');
    }

    public function test_bad_input_is_rejected(): void
    {
        foreach (['abc', '0911', '09112233445566', '+251 011', '00112'] as $bad) {
            $this->assertNull(PhoneNumber::normalize($bad), "input: {$bad}");
        }
        $this->assertNull(PhoneNumber::normalize(null));
        $this->assertNull(PhoneNumber::normalize('  '));
    }

    public function test_display_and_search_key(): void
    {
        $this->assertSame('0911 22 33 44', PhoneNumber::display('+251911223344'));
        $this->assertSame('', PhoneNumber::display(null));
        $this->assertSame('911223344', PhoneNumber::searchKey('+251 911 22 33 44'));
        $this->assertSame(PhoneNumber::searchKey('0911223344'), PhoneNumber::searchKey('251911223344'));
        $this->assertSame('', PhoneNumber::searchKey('abc'));
    }

    public function test_models_store_the_canonical_form_and_the_same_number_is_found_however_it_is_typed(): void
    {
        $school = School::create(['name' => 'S', 'code' => 'S'.Str::random(3), 'phone' => '0911 22 33 44']);
        $this->assertSame('+251911223344', $school->fresh()->phone);

        $u = User::create(['school_id' => $school->id, 'name' => 'P', 'email' => 'p@t.test', 'password' => 'x', 'status' => 'active', 'phone' => '251911223344']);
        foreach (['0911223344', '+251911223344', '911223344'] as $typed) {
            $this->assertSame($u->id, User::where('phone', PhoneNumber::normalize($typed))->value('id'), "lookup by {$typed}");
        }
    }

    public function test_forms_reject_garbage_and_store_normalized(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $school = School::create(['name' => 'S', 'code' => 'S'.Str::random(3)]);
        $branch = Branch::create(['school_id' => $school->id, 'name' => 'Main', 'code' => 'MAIN', 'is_main' => true]);
        $owner = User::create(['school_id' => $school->id, 'name' => 'O', 'email' => 'o@t.test', 'password' => 'x', 'status' => 'active', 'email_verified_at' => now()]);
        $owner->assignTier('owner');
        $year = AcademicYear::create(['school_id' => $school->id, 'name' => '2026', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'is_current' => true]);
        $grade = Grade::create(['school_id' => $school->id, 'name' => 'G1', 'sort_order' => 1]);
        $section = Section::create(['branch_id' => $branch->id, 'grade_id' => $grade->id, 'academic_year_id' => $year->id, 'name' => 'A']);
        $base = ['gender' => 'male', 'enrollment_date' => '2026-09-20', 'section_id' => $section->id];

        $this->actingAs($owner)->post('/students', $base + ['first_name' => 'A', 'guardian_mode' => 'none', 'emergency_contact' => 'call me'])->assertSessionHasErrors('emergency_contact');

        $this->actingAs($owner)->post('/students', $base + ['first_name' => 'A', 'guardian_mode' => 'new', 'guardian_name' => 'Mum', 'guardian_email' => 'mum@t.test', 'guardian_relationship' => 'mother', 'guardian_phone' => '0911 22 33 44', 'emergency_contact' => '0922334455'])->assertSessionHasNoErrors();
        $this->assertSame('+251911223344', User::where('email', 'mum@t.test')->value('phone'));
        $this->assertSame('+251922334455', Student::where('first_name', 'A')->value('emergency_contact'));
        $this->assertNull(session('warning'));

        // a second, different account registered with the same number in another format -> warned, not blocked
        $this->actingAs($owner)->post('/students', $base + ['first_name' => 'B', 'guardian_mode' => 'new', 'guardian_name' => 'Mum again', 'guardian_email' => 'mum2@t.test', 'guardian_relationship' => 'mother', 'guardian_phone' => '+251911223344'])->assertSessionHasNoErrors();
        $this->assertStringContainsString('already used by Mum', session('warning'));
        $this->assertSame(2, User::where('phone', '+251911223344')->count());
    }
}
