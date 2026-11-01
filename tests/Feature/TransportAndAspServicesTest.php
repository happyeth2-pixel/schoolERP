<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\OptionalService;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Transport routes (one per student), the after-school programme, a student's own price (custom_amount), and
 * invoice generation that respects it -- while permissions, branch scope, audit and frozen invoice items stay as they were.
 */
class TransportAndAspServicesTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        // a branch-level user who may manage invoices (and so services) and students
        $this->accountant = $this->makeUser('branch_admin', $this->branchA->id, ['manage-invoices', 'manage-students', 'manage-fee-structures']);
    }

    private function service(string $name, string $category, float $amount, string $frequency = 'monthly', ?int $branchId = null): OptionalService
    {
        return OptionalService::create([
            'branch_id' => $branchId ?? $this->branchA->id, 'academic_year_id' => $this->year->id,
            'name' => $name, 'category' => $category, 'amount' => $amount, 'frequency' => $frequency,
        ]);
    }

    private function enroll(User $by, Student $student, OptionalService $service, array $extra = [])
    {
        return $this->actingAs($by)->post(route('students.optional-services.store', $student), ['optional_service_id' => $service->id] + $extra);
    }

    private function pivotAmount(Student $student, OptionalService $service): ?string
    {
        return $student->optionalServices()->whereKey($service->id)->first()?->pivot->custom_amount;
    }

    // ------------------------------------------------------------------ invoices

    public function test_generation_uses_the_students_own_price_when_set_and_the_standard_price_otherwise(): void
    {
        $kela = $this->service('Transport - Kela', 'transport', 800);
        $second = $this->makeStudent($this->branchA, $this->sectionA, 'Second', 'STU-A2');

        $this->enroll($this->accountant, $this->studentA, $kela);                                  // standard price
        $this->enroll($this->accountant, $second, $kela, ['custom_amount' => '650']);              // own price

        $this->actingAs($this->accountant)->post(route('invoices.generate'), ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id])->assertSessionHasNoErrors();

        $amountFor = fn (Student $s) => Invoice::where('student_id', $s->id)->first()->items->firstWhere('optional_service_id', $kela->id);
        $this->assertEquals(800, $amountFor($this->studentA)->unit_amount);
        $this->assertEquals(650, $amountFor($second)->unit_amount);
        $this->assertEquals(650, $amountFor($second)->amount);
    }

    public function test_a_service_with_no_custom_price_bills_exactly_as_before(): void
    {
        $asp = $this->service('ASP Classes', 'asp', 600, 'termly');
        Term::create(['academic_year_id' => $this->year->id, 'name' => 'Term 1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-20']);
        $this->studentA->optionalServices()->attach($asp->id, ['enrolled_on' => '2026-09-01']);   // as the old code attached it

        $this->actingAs($this->accountant)->post(route('invoices.generate'), ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id]);

        $item = Invoice::where('student_id', $this->studentA->id)->firstOrFail()->items->first();
        $this->assertEquals(600, $item->unit_amount);
        $this->assertSame('ASP Classes', $item->description);
        $this->assertNull($this->pivotAmount($this->studentA, $asp));
    }

    public function test_changing_a_price_never_touches_invoices_already_generated(): void
    {
        $kela = $this->service('Transport - Kela', 'transport', 800);
        $this->enroll($this->accountant, $this->studentA, $kela, ['custom_amount' => '700']);
        $this->actingAs($this->accountant)->post(route('invoices.generate'), ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id]);

        $before = Invoice::where('student_id', $this->studentA->id)->count();

        $this->actingAs($this->accountant)->patch(route('students.optional-services.update', [$this->studentA, $kela]), ['custom_amount' => '100'])->assertSessionHasNoErrors();
        $kela->update(['amount' => 999]);
        $this->actingAs($this->accountant)->post(route('invoices.generate'), ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id]);   // a re-run

        $invoices = Invoice::where('student_id', $this->studentA->id)->with('items')->get();
        $this->assertGreaterThan(1, $before, 'a monthly service is billed once per month of the year');
        $this->assertCount($before, $invoices, 're-running never duplicates a period');
        $this->assertEquals([700.0], $invoices->flatMap->items->pluck('unit_amount')->map(fn ($v) => (float) $v)->unique()->values()->all(), 'every frozen line keeps the amount it was billed at');
    }

    public function test_enrolling_after_an_invoice_exists_adds_the_line_at_the_students_price(): void
    {
        $this->actingAs($this->accountant)->post(route('invoices.generate'), ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id]);
        FeeStructure::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'Tuition', 'amount' => 1000, 'frequency' => 'annual']);
        $this->actingAs($this->accountant)->post(route('invoices.generate'), ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id]);
        $annual = $this->service('Lunch', 'other', 500, 'annual');

        $this->enroll($this->accountant, $this->studentA, $annual, ['custom_amount' => '450'])->assertSessionHasNoErrors();

        $line = Invoice::where('student_id', $this->studentA->id)->firstOrFail()->items()->where('optional_service_id', $annual->id)->firstOrFail();
        $this->assertEquals(450, $line->unit_amount);
    }

    public function test_a_termly_fee_without_terms_is_a_readable_refusal_not_an_error_page(): void
    {
        FeeStructure::create(['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id, 'name' => 'ASP', 'amount' => 5000, 'frequency' => 'termly']);

        $this->actingAs($this->accountant)
            ->post(route('invoices.generate'), ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id])
            ->assertSessionHasErrors('generate');   // used to be a raw 422

        $this->assertSame(0, Invoice::count(), 'nothing is generated when the rule refuses');
    }

    // ------------------------------------------------------------------ one transport route

    public function test_choosing_another_transport_route_replaces_the_first_and_leaves_other_services_alone(): void
    {
        $kela = $this->service('Transport - Kela', 'transport', 800);
        $ashewa = $this->service('Transport - Ashewa Meda', 'transport', 950);
        $asp = $this->service('ASP Classes', 'asp', 600, 'termly');

        $this->enroll($this->accountant, $this->studentA, $kela);
        $this->enroll($this->accountant, $this->studentA, $asp);
        $this->enroll($this->accountant, $this->studentA, $ashewa)->assertSessionHasNoErrors();

        $names = $this->studentA->refresh()->optionalServices->pluck('name')->sort()->values()->all();
        $this->assertSame(['ASP Classes', 'Transport - Ashewa Meda'], $names);

        $log = AuditLog::where('action', 'student.service_withdrawn')->latest('id')->first();
        $this->assertSame('Transport - Kela', $log->before['service'] ?? null);
    }

    public function test_replacing_a_route_already_on_this_periods_invoice_warns_and_keeps_the_old_charge(): void
    {
        $kela = $this->service('Transport - Kela', 'transport', 800);
        $ashewa = $this->service('Transport - Ashewa Meda', 'transport', 950);
        $this->enroll($this->accountant, $this->studentA, $kela);
        $this->actingAs($this->accountant)->post(route('invoices.generate'), ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id]);
        $invoice = Invoice::where('student_id', $this->studentA->id)->firstOrFail();

        // whichever monthly invoice covers today is the one the charge lands on
        $this->travelTo($invoice->period_start);
        $response = $this->enroll($this->accountant, $this->studentA, $ashewa);
        $this->travelBack();

        $response->assertSessionHas('warning');
        $this->assertTrue($invoice->items()->where('optional_service_id', $kela->id)->exists(), 'the charge already on the invoice is never removed silently');
    }

    public function test_two_non_transport_services_can_be_combined_and_one_service_cannot_be_taken_twice(): void
    {
        $asp = $this->service('ASP Classes', 'asp', 600, 'termly');
        $lunch = $this->service('Lunch', 'other', 500, 'monthly');

        $this->enroll($this->accountant, $this->studentA, $asp)->assertSessionHasNoErrors();
        $this->enroll($this->accountant, $this->studentA, $lunch)->assertSessionHasNoErrors();
        $this->enroll($this->accountant, $this->studentA, $asp)->assertSessionHasErrors('optional_service_id');
        $this->assertSame(2, $this->studentA->optionalServices()->count());
    }

    public function test_no_transport_is_a_plain_withdrawal_and_is_audited(): void
    {
        $kela = $this->service('Transport - Kela', 'transport', 800);
        $this->enroll($this->accountant, $this->studentA, $kela);

        $this->actingAs($this->accountant)->delete(route('students.optional-services.destroy', [$this->studentA, $kela]))->assertSessionHasNoErrors();

        $this->assertSame(0, $this->studentA->optionalServices()->count());
        $this->assertTrue(AuditLog::where('action', 'student.service_withdrawn')->exists());
    }

    // ------------------------------------------------------------------ the student's own price

    public function test_the_custom_price_is_validated_audited_and_can_be_cleared(): void
    {
        $kela = $this->service('Transport - Kela', 'transport', 800);
        $this->enroll($this->accountant, $this->studentA, $kela);
        $patch = fn ($v) => $this->actingAs($this->accountant)->patch(route('students.optional-services.update', [$this->studentA, $kela]), ['custom_amount' => $v]);

        $patch('-5')->assertSessionHasErrors('custom_amount');
        $patch('abc')->assertSessionHasErrors('custom_amount');
        $patch('1000000000')->assertSessionHasErrors('custom_amount');
        $this->assertNull($this->pivotAmount($this->studentA, $kela));

        $patch('725.50')->assertSessionHasNoErrors();
        $this->assertEquals(725.5, $this->pivotAmount($this->studentA, $kela));
        $log = AuditLog::where('action', 'student.service_price_changed')->latest('id')->firstOrFail();
        $this->assertEquals(725.5, $log->after['custom_amount']);

        $patch('')->assertSessionHasNoErrors();
        $this->assertNull($this->pivotAmount($this->studentA, $kela), 'empty means "use the standard price"');
    }

    // ------------------------------------------------------------------ permissions and scope

    public function test_services_need_manage_invoices_and_the_right_branch(): void
    {
        $kela = $this->service('Transport - Kela', 'transport', 800);
        $eastBus = $this->service('Transport - East', 'transport', 700, 'monthly', $this->branchB->id);
        $frontDesk = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $this->enroll($frontDesk, $this->studentA, $kela)->assertForbidden();
        $this->actingAs($frontDesk)->patch(route('students.optional-services.update', [$this->studentA, $kela]), ['custom_amount' => '1'])->assertForbidden();
        $this->actingAs($frontDesk)->delete(route('students.optional-services.destroy', [$this->studentA, $kela]))->assertForbidden();

        $this->enroll($this->accountant, $this->studentB, $eastBus)->assertForbidden();              // another branch's student
        $this->enroll($this->accountant, $this->studentA, $eastBus)->assertStatus(422);              // another branch's service
        $this->assertSame(0, $this->studentA->optionalServices()->count() + $this->studentB->optionalServices()->count());
    }

    public function test_only_fee_managers_set_a_services_category(): void
    {
        $noFees = $this->makeUser('branch_admin', $this->branchA->id, ['manage-invoices']);
        $payload = ['branch_id' => $this->branchA->id, 'academic_year_id' => $this->year->id, 'name' => 'Bus', 'category' => 'transport', 'amount' => 500, 'frequency' => 'monthly'];

        $this->actingAs($noFees)->post(route('optional-services.store'), $payload)->assertForbidden();
        $this->actingAs($this->accountant)->post(route('optional-services.store'), $payload)->assertSessionHasNoErrors();
        $this->assertSame('transport', OptionalService::where('name', 'Bus')->value('category'));
        $this->actingAs($this->accountant)->post(route('optional-services.store'), ['category' => 'rocket'] + $payload)->assertSessionHasErrors('category');
        $this->assertSame('other', OptionalService::create(['branch_id' => $this->branchA->id, 'academic_year_id' => $this->year->id, 'name' => 'Old', 'amount' => 1, 'frequency' => 'annual'])->refresh()->category, 'services made before categories existed are "other"');
    }

    public function test_a_service_cannot_become_transport_while_it_would_put_a_student_on_two_routes(): void
    {
        $kela = $this->service('Transport - Kela', 'transport', 800);
        $bus = $this->service('Shuttle', 'other', 300);
        $this->enroll($this->accountant, $this->studentA, $kela);
        $this->enroll($this->accountant, $this->studentA, $bus);
        $update = fn (string $category) => $this->actingAs($this->accountant)->patch(route('optional-services.update', $bus), ['name' => 'Shuttle', 'amount' => 300, 'frequency' => 'monthly', 'category' => $category]);

        $update('transport')->assertSessionHasErrors('category');
        $this->assertSame('other', $bus->refresh()->category);

        $this->studentA->optionalServices()->detach($kela->id);
        $update('transport')->assertSessionHasNoErrors();
        $this->assertSame('transport', $bus->refresh()->category);
    }

    // ------------------------------------------------------------------ registration wizard

    private function register(User $by, array $extra = [])
    {
        return $this->actingAs($by)->post('/students', array_merge([
            'first_name' => 'Bethlehem', 'father_name' => 'Girma', 'gender' => 'female', 'enrollment_date' => '2026-09-20',
            'section_id' => $this->sectionA->id, 'guardian_mode' => 'none', 'emergency_contact' => '0911223344',
        ], $extra));
    }

    public function test_registration_can_enrol_the_child_in_transport_and_asp_together(): void
    {
        $kela = $this->service('Transport - Kela', 'transport', 800);
        $asp = $this->service('ASP Classes', 'asp', 600, 'termly');

        $this->register($this->accountant, ['service_ids' => [$kela->id, $asp->id]])->assertSessionHasNoErrors();

        $student = Student::where('first_name', 'Bethlehem')->firstOrFail();
        $this->assertEqualsCanonicalizing(['Transport - Kela', 'ASP Classes'], $student->optionalServices->pluck('name')->all());
        $this->assertSame(2, AuditLog::where('action', 'student.service_joined')->count());
    }

    public function test_registration_without_services_is_unchanged_for_everyone_who_may_register(): void
    {
        $frontDesk = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students', 'manage-parents']);

        $this->register($frontDesk)->assertSessionHasNoErrors();
        $this->register($frontDesk, ['first_name' => 'Two', 'service_ids' => []])->assertSessionHasNoErrors();

        $this->assertSame(2, Student::whereIn('first_name', ['Bethlehem', 'Two'])->count());
    }

    public function test_registration_refuses_services_for_someone_who_may_not_manage_them_and_registers_nobody(): void
    {
        $kela = $this->service('Transport - Kela', 'transport', 800);
        $frontDesk = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $this->register($frontDesk, ['service_ids' => [$kela->id]])->assertForbidden();

        $this->assertSame(0, Student::where('first_name', 'Bethlehem')->count());
    }

    public function test_registration_rejects_two_transports_and_services_of_another_branch(): void
    {
        $kela = $this->service('Transport - Kela', 'transport', 800);
        $ashewa = $this->service('Transport - Ashewa Meda', 'transport', 950);
        $eastBus = $this->service('Transport - East', 'transport', 700, 'monthly', $this->branchB->id);

        $this->register($this->accountant, ['service_ids' => [$kela->id, $ashewa->id]])->assertSessionHasErrors('service_ids');
        $this->register($this->accountant, ['service_ids' => [$eastBus->id]])->assertSessionHasErrors('service_ids');

        $this->assertSame(0, Student::where('first_name', 'Bethlehem')->count());
    }

    public function test_the_create_page_lists_only_this_branchs_services_and_only_to_those_who_may_use_them(): void
    {
        $this->service('Transport - Kela', 'transport', 800);
        $this->service('Transport - East', 'transport', 700, 'monthly', $this->branchB->id);
        $frontDesk = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students']);

        $mine = $this->props($this->actingAs($this->accountant)->get('/students/create'));
        $this->assertSame(['Transport - Kela'], collect($mine['services'])->pluck('name')->all());
        $this->assertTrue($mine['canManageServices']);

        $theirs = $this->props($this->actingAs($frontDesk)->get('/students/create'));
        $this->assertSame([], $theirs['services']);
        $this->assertFalse($theirs['canManageServices']);
    }

    public function test_the_profile_carries_category_and_the_students_own_price(): void
    {
        $kela = $this->service('Transport - Kela', 'transport', 800);
        $this->enroll($this->accountant, $this->studentA, $kela, ['custom_amount' => '650']);

        $svc = collect($this->props($this->actingAs($this->accountant)->get(route('students.edit', $this->studentA)))['availableServices'])->firstWhere('id', $kela->id);

        $this->assertSame('transport', $svc['category']);
        $this->assertEquals(650, $svc['custom_amount']);
        $this->assertEquals(800, $svc['amount']);
        $this->assertTrue($svc['enrolled']);
    }
}
