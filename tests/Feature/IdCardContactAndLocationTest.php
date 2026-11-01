<?php

namespace Tests\Feature;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Two real gaps found by hand while testing the ID card: the card's "Emergency" line was often blank
 * even for a student with a perfectly reachable linked guardian (the card only ever read the separate,
 * often-never-filled-in `emergency_contact` text field); and "Address" was too vague to be useful --
 * kebele and city are what an Ethiopian address actually needs. Covers IdCardController::cardPayload()'s
 * new location()/emergencyContact() resolution, and that registration now insists on SOME way to reach
 * someone for a child when no guardian is being linked.
 */
class IdCardContactAndLocationTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function card()
    {
        return $this->props($this->actingAs($this->owner)->get(route('id-card.show', $this->studentA))->assertOk())['student'];
    }

    // ------------------------------------------------------------------ location

    public function test_location_combines_kebele_and_city_when_given(): void
    {
        $this->studentA->update(['kebele' => 'Kebele 05', 'city' => 'Bahir Dar', 'address' => 'old free-text address']);

        $this->assertSame('Kebele 05, Bahir Dar', $this->card()['location']);
    }

    public function test_location_falls_back_to_the_old_address_field_when_no_kebele_or_city_is_on_record(): void
    {
        $this->studentA->update(['kebele' => null, 'city' => null, 'address' => 'old free-text address']);

        $this->assertSame('old free-text address', $this->card()['location']);
    }

    public function test_location_uses_whichever_of_kebele_or_city_is_actually_given(): void
    {
        $this->studentA->update(['kebele' => 'Kebele 05', 'city' => null]);
        $this->assertSame('Kebele 05', $this->card()['location']);

        $this->studentA->update(['kebele' => null, 'city' => 'Bahir Dar']);
        $this->assertSame('Bahir Dar', $this->card()['location']);
    }

    // ------------------------------------------------------------------ emergency contact

    public function test_a_linked_guardians_own_phone_is_preferred_over_the_plain_emergency_contact_field(): void
    {
        $this->studentA->update(['emergency_contact' => PhoneNumber::normalize('0900000000')]);
        $mother = $this->makeParent($this->studentA); // BuildsSchool::makeParent() links as 'mother'
        $mother->update(['phone' => '0911223344']);

        $this->assertSame(PhoneNumber::display('0911223344'), $this->card()['emergency_contact']);
    }

    public function test_the_plain_field_is_used_when_no_linked_guardian_has_a_phone(): void
    {
        $this->studentA->update(['emergency_contact' => PhoneNumber::normalize('0900000000')]);
        $guardian = $this->makeParent($this->studentA); // BuildsSchool::makeUser sets no phone by default

        $this->assertNull($guardian->phone);
        $this->assertSame(PhoneNumber::display('0900000000'), $this->card()['emergency_contact']);
    }

    public function test_mother_or_father_is_preferred_over_another_kind_of_guardian(): void
    {
        $aunt = $this->makeUser('parent', null);
        $this->studentA->guardians()->attach($aunt->id, ['relationship' => 'aunt']);
        $aunt->update(['phone' => '0922334455']);

        $father = $this->makeUser('parent', null);
        $this->studentA->guardians()->attach($father->id, ['relationship' => 'father']);
        $father->update(['phone' => '0911223344']);

        $this->assertSame(PhoneNumber::display('0911223344'), $this->card()['emergency_contact']);
    }

    public function test_emergency_contact_is_null_when_nothing_at_all_is_on_record(): void
    {
        $this->assertNull($this->studentA->emergency_contact);
        $this->assertTrue($this->studentA->guardians->isEmpty());

        $this->assertNull($this->card()['emergency_contact']);
    }

    // ------------------------------------------------------------------ registration requires SOME contact

    private function register(array $over = [])
    {
        return $this->actingAs($this->owner)->post('/students', $over + [
            'first_name' => 'Newcomer', 'gender' => 'male', 'enrollment_date' => '2026-09-20',
            'section_id' => $this->sectionA->id, 'guardian_mode' => 'none',
        ]);
    }

    public function test_no_guardian_and_no_emergency_contact_is_refused(): void
    {
        $this->register()->assertSessionHasErrors('emergency_contact');
        $this->assertSame(0, \App\Models\Student::where('first_name', 'Newcomer')->count());
    }

    public function test_no_guardian_but_an_emergency_contact_is_accepted(): void
    {
        $this->register(['emergency_contact' => '0911223344'])->assertSessionHasNoErrors();
        $this->assertSame(1, \App\Models\Student::where('first_name', 'Newcomer')->count());
    }

    public function test_linking_a_guardian_does_not_require_an_emergency_contact(): void
    {
        $this->register([
            'guardian_mode' => 'new', 'guardian_name' => 'Mum', 'guardian_phone' => '0911223344', 'guardian_relationship' => 'mother',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, \App\Models\Student::where('first_name', 'Newcomer')->count());
    }
}
