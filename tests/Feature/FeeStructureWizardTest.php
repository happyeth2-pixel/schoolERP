<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\FeeStructure;
use App\Models\Grade;
use App\Models\Invoice;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Adding and changing a fee through the guided pages. The fee rules are the ones that already existed (name, amount,
 * how often it is billed; grade, year and branch checked against the user's school and branch; changing a fee never
 * touches bills already made); the one addition is that the same fee name cannot be added twice for a grade and year.
 */
class FeeStructureWizardTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
        $this->accountant = $this->makeUser('accountant', $this->branchA->id, ['manage-fee-structures', 'manage-invoices']);
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id, 'academic_year_id' => $this->year->id,
            'name' => 'Tuition', 'amount' => 2500, 'frequency' => 'monthly',
        ], $extra);
    }

    private function add(array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->accountant)->post('/fee-structures', $this->payload($extra));
    }

    private function fee(array $extra = []): FeeStructure
    {
        return FeeStructure::create($this->payload($extra));
    }

    private function arr($response): array
    {
        return json_decode(json_encode($this->props($response)), true);
    }

    // ------------------------------------------------------------------ create

    public function test_a_fee_is_created_with_its_grade_year_amount_and_frequency_and_the_page_gets_a_summary(): void
    {
        $this->add()->assertSessionHasNoErrors()->assertSessionHas('fee_saved');

        $fee = FeeStructure::firstOrFail();
        $this->assertSame(['Tuition', 'monthly', $this->grade->id, $this->year->id, $this->branchA->id], [$fee->name, $fee->frequency, $fee->grade_id, $fee->academic_year_id, $fee->branch_id]);
        $this->assertEquals(2500, $fee->amount);
        $saved = session('fee_saved');
        $this->assertSame(['added', 'Tuition', 'G1', '2026'], [$saved['action'], $saved['name'], $saved['grade'], $saved['academic_year']]);
        $this->assertNotNull(AuditLog::where('auditable_type', FeeStructure::class)->first(), 'fee changes are audited as before');
    }

    public function test_a_free_fee_is_allowed_and_every_frequency_works(): void
    {
        $this->add(['name' => 'Free', 'amount' => 0])->assertSessionHasNoErrors();
        foreach (['annual', 'termly', 'monthly'] as $f) {
            $this->add(['name' => "Fee $f", 'frequency' => $f])->assertSessionHasNoErrors();
        }
        $this->assertSame(4, FeeStructure::count());
    }

    public function test_the_billing_rules_still_use_a_fee_made_this_way(): void
    {
        $this->add(['name' => 'Tuition', 'amount' => 1200, 'frequency' => 'annual']);

        $this->actingAs($this->accountant)->post('/invoices/generate', ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id])->assertSessionHasNoErrors();

        $item = Invoice::where('student_id', $this->studentA->id)->firstOrFail()->items->first();
        $this->assertEquals(1200, $item->unit_amount);
        $this->assertSame('Tuition', $item->description);
    }

    // ------------------------------------------------------------------ validation

    public function test_invalid_fees_are_refused_and_nothing_is_saved(): void
    {
        $this->add(['name' => ''])->assertSessionHasErrors('name');
        $this->add(['name' => str_repeat('x', 256)])->assertSessionHasErrors('name');
        $this->add(['amount' => ''])->assertSessionHasErrors('amount');
        $this->add(['amount' => 'abc'])->assertSessionHasErrors('amount');
        $this->add(['amount' => -5])->assertSessionHasErrors('amount');
        $this->add(['amount' => 100000000])->assertSessionHasErrors('amount');   // more than the column holds: used to be a database error
        $this->add(['frequency' => 'weekly'])->assertSessionHasErrors('frequency');
        $this->add(['frequency' => ''])->assertSessionHasErrors('frequency');

        $this->assertSame(0, FeeStructure::count());
    }

    public function test_a_grade_year_or_branch_outside_the_users_reach_is_refused(): void
    {
        $other = \App\Models\School::create(['name' => 'Other', 'code' => 'OTH']);
        $foreignGrade = Grade::create(['school_id' => $other->id, 'name' => 'X', 'sort_order' => 1]);
        $foreignYear = AcademicYear::create(['school_id' => $other->id, 'name' => 'Y', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_current' => true]);

        $this->add(['grade_id' => $foreignGrade->id])->assertForbidden();
        $this->add(['academic_year_id' => $foreignYear->id])->assertForbidden();
        $this->add(['branch_id' => $this->branchB->id])->assertForbidden();   // a Main-branch accountant cannot add to East
        $this->assertSame(0, FeeStructure::count());

        $this->add(['branch_id' => $this->branchB->id], $this->owner)->assertSessionHasNoErrors();   // the owner works across branches
    }

    // ------------------------------------------------------------------ duplicates

    public function test_the_same_fee_name_cannot_be_added_twice_for_a_grade_and_year(): void
    {
        $this->add()->assertSessionHasNoErrors();

        $this->add(['amount' => 999])->assertSessionHasErrors('name');
        $this->add(['name' => '  tuition '])->assertSessionHasErrors('name');
        $this->add(['name' => 'TUITION', 'frequency' => 'annual'])->assertSessionHasErrors('name');

        $this->assertSame(1, FeeStructure::count());
        $this->assertEquals(2500, FeeStructure::first()->amount, 'the first fee is untouched');
    }

    public function test_the_same_name_is_fine_for_another_grade_year_or_branch_or_after_the_first_is_removed(): void
    {
        $first = $this->fee();
        $grade2 = Grade::create(['school_id' => $this->school->id, 'name' => 'G2', 'sort_order' => 2]);
        $year2 = AcademicYear::create(['school_id' => $this->school->id, 'name' => '2027', 'start_date' => '2027-09-01', 'end_date' => '2028-06-30', 'is_current' => false]);

        $this->add(['grade_id' => $grade2->id])->assertSessionHasNoErrors();
        $this->add(['academic_year_id' => $year2->id])->assertSessionHasNoErrors();
        $this->add(['branch_id' => $this->branchB->id], $this->owner)->assertSessionHasNoErrors();

        $this->actingAs($this->accountant)->delete("/fee-structures/{$first->id}")->assertSessionHasNoErrors();
        $this->add()->assertSessionHasNoErrors();

        $this->assertSame(4, FeeStructure::count());
    }

    public function test_renaming_a_fee_to_another_existing_name_is_refused_but_keeping_its_own_name_is_fine(): void
    {
        $tuition = $this->fee();
        $books = $this->fee(['name' => 'Books', 'amount' => 300, 'frequency' => 'annual']);

        $this->actingAs($this->accountant)->patch("/fee-structures/{$books->id}", ['name' => 'tuition', 'amount' => 300, 'frequency' => 'annual'])->assertSessionHasErrors('name');
        $this->actingAs($this->accountant)->patch("/fee-structures/{$tuition->id}", ['name' => 'Tuition', 'amount' => 2600, 'frequency' => 'monthly'])->assertSessionHasNoErrors();

        $this->assertSame('Books', $books->fresh()->name);
        $this->assertEquals(2600, $tuition->fresh()->amount);
    }

    // ------------------------------------------------------------------ edit

    public function test_editing_changes_only_the_name_amount_and_frequency(): void
    {
        $fee = $this->fee();
        $grade2 = Grade::create(['school_id' => $this->school->id, 'name' => 'G2', 'sort_order' => 2]);

        $this->actingAs($this->accountant)->patch("/fee-structures/{$fee->id}", ['name' => 'School fee', 'amount' => 3000, 'frequency' => 'termly', 'grade_id' => $grade2->id, 'branch_id' => $this->branchB->id])
            ->assertSessionHasNoErrors()->assertSessionHas('fee_saved');

        $fee->refresh();
        $this->assertSame(['School fee', 'termly', $this->grade->id, $this->branchA->id], [$fee->name, $fee->frequency, $fee->grade_id, $fee->branch_id], 'grade, year and branch cannot be changed here');
        $this->assertEquals(3000, $fee->amount);
        $this->assertSame('changed', session('fee_saved')['action']);
    }

    public function test_changing_a_fee_never_touches_bills_already_made(): void
    {
        $fee = $this->fee(['frequency' => 'annual', 'amount' => 1000]);
        $this->actingAs($this->accountant)->post('/invoices/generate', ['branch_id' => $this->branchA->id, 'grade_id' => $this->grade->id]);

        $this->actingAs($this->accountant)->patch("/fee-structures/{$fee->id}", ['name' => 'Tuition', 'amount' => 5000, 'frequency' => 'annual'])->assertSessionHasNoErrors();

        $this->assertEquals(1000, Invoice::where('student_id', $this->studentA->id)->first()->items->first()->unit_amount);
    }

    public function test_the_pages_carry_what_the_steps_need_and_only_the_users_own_branch(): void
    {
        $mine = $this->fee(['name' => 'Tuition']);
        $east = $this->fee(['name' => 'East only', 'branch_id' => $this->branchB->id]);
        Term::create(['academic_year_id' => $this->year->id, 'name' => 'T1', 'start_date' => '2026-09-01', 'end_date' => '2026-12-01']);

        $new = $this->arr($this->actingAs($this->accountant)->get('/fee-structures/new')->assertOk());
        $this->assertNull($new['fee']);
        $this->assertSame([$this->branchA->id], array_column($new['branches'], 'id'));
        $this->assertSame([$mine->id], array_column($new['existing'], 'id'), 'the fees already set are listed so a duplicate is visible, only for my branch');
        $this->assertSame(1, $new['termCounts'][(string) $this->year->id]);
        $this->assertContains('Tuition', $new['nameSuggestions']);
        $this->assertNotContains('East only', $new['nameSuggestions']);

        $edit = $this->arr($this->actingAs($this->accountant)->get("/fee-structures/{$mine->id}/edit")->assertOk());
        $this->assertSame(['Tuition', 'monthly', 'G1', '2026'], [$edit['fee']['name'], $edit['fee']['frequency'], $edit['fee']['grade'], $edit['fee']['academic_year']]);

        $this->actingAs($this->accountant)->get("/fee-structures/{$east->id}/edit")->assertForbidden();
        $this->actingAs($this->owner)->get("/fee-structures/{$east->id}/edit")->assertOk();
    }

    // ------------------------------------------------------------------ authorization

    public function test_only_people_who_manage_fees_in_the_right_branch_can_open_or_change_fees(): void
    {
        $fee = $this->fee();
        $noPermission = $this->makeUser('accountant', $this->branchA->id, ['manage-invoices']);
        $east = $this->makeUser('accountant', $this->branchB->id, ['manage-fee-structures']);
        $parent = $this->makeParent($this->studentA);

        foreach ([$noPermission, $parent] as $u) {
            $this->actingAs($u)->get('/fee-structures/new')->assertForbidden();
            $this->actingAs($u)->get("/fee-structures/{$fee->id}/edit")->assertForbidden();
            $this->add(['name' => 'Sneaky'], $u)->assertForbidden();
            $this->actingAs($u)->patch("/fee-structures/{$fee->id}", ['name' => 'Sneaky', 'amount' => 1, 'frequency' => 'annual'])->assertForbidden();
            $this->actingAs($u)->delete("/fee-structures/{$fee->id}")->assertForbidden();
        }
        $this->actingAs($east)->get("/fee-structures/{$fee->id}/edit")->assertForbidden();
        $this->actingAs($east)->patch("/fee-structures/{$fee->id}", ['name' => 'Sneaky', 'amount' => 1, 'frequency' => 'annual'])->assertForbidden();
        $this->actingAs($east)->delete("/fee-structures/{$fee->id}")->assertForbidden();

        $this->assertSame(['Tuition', 2500.0], [$fee->fresh()->name, (float) $fee->fresh()->amount]);
        $this->assertSame(1, FeeStructure::count());
    }
}
