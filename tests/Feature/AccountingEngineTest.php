<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Journal;
use App\Support\ChartOfAccountsTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

class AccountingEngineTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    // ---------------------------------------------------------------- chart of accounts template

    public function test_installing_the_default_template_creates_the_hierarchy_and_wires_system_accounts(): void
    {
        ChartOfAccountsTemplate::install($this->school);

        $this->assertTrue(ChartOfAccountsTemplate::isInstalled($this->school->fresh()));
        $assets = Account::where('school_id', $this->school->id)->where('code', '1000')->firstOrFail();
        $this->assertTrue($assets->is_header);
        $cash = Account::where('school_id', $this->school->id)->where('code', '1100')->firstOrFail();
        $this->assertSame($assets->id, $cash->parent_id);

        $ar = Account::where('school_id', $this->school->id)->where('code', '1300')->firstOrFail();
        $this->assertTrue($ar->is_system);
        $school = $this->school->fresh();
        $this->assertSame($ar->id, $school->default_ar_account_id);
        $this->assertNotNull($school->default_ap_account_id);
        $this->assertNotNull($school->default_cash_account_id);
        $this->assertNotNull($school->default_revenue_account_id);
        $this->assertNotNull($school->default_expense_account_id);
        $this->assertNotNull($school->default_discount_account_id);
        $this->assertNotNull($school->default_waiver_account_id);

        $this->assertGreaterThanOrEqual(7, Journal::where('school_id', $this->school->id)->count());
    }

    public function test_installing_the_template_twice_does_not_duplicate_accounts(): void
    {
        ChartOfAccountsTemplate::install($this->school);
        $countAfterFirst = Account::where('school_id', $this->school->id)->count();

        ChartOfAccountsTemplate::install($this->school);
        $this->assertSame($countAfterFirst, Account::where('school_id', $this->school->id)->count());
    }

    // ---------------------------------------------------------------- accounts

    public function test_only_accounting_manage_can_create_an_account(): void
    {
        $bystander = $this->makeUser('accountant', null, []);
        $this->actingAs($bystander)->post('/accounting/accounts', [
            'code' => '9999', 'name' => 'Test', 'type' => 'asset',
        ])->assertForbidden();
    }

    public function test_an_account_code_must_be_unique_per_school(): void
    {
        $manager = $this->makeUser('accountant', null, ['accounting-manage']);
        Account::create(['school_id' => $this->school->id, 'code' => '1100', 'name' => 'Cash', 'type' => 'asset', 'normal_balance' => 'debit']);

        $this->actingAs($manager)->post('/accounting/accounts', [
            'code' => '1100', 'name' => 'Duplicate', 'type' => 'asset',
        ])->assertSessionHasErrors('code');
    }

    public function test_a_system_account_cannot_be_deleted(): void
    {
        ChartOfAccountsTemplate::install($this->school);
        $ar = Account::where('school_id', $this->school->id)->where('code', '1300')->firstOrFail();

        $this->expectException(\App\Exceptions\DeletionBlockedException::class);
        $ar->delete();
    }

    public function test_an_account_with_children_cannot_be_deleted(): void
    {
        ChartOfAccountsTemplate::install($this->school);
        $assets = Account::where('school_id', $this->school->id)->where('code', '1000')->firstOrFail();

        $this->expectException(\App\Exceptions\DeletionBlockedException::class);
        $assets->delete();
    }

    public function test_a_plain_unused_account_can_be_deleted(): void
    {
        $manager = $this->makeUser('accountant', null, ['accounting-manage']);
        $account = Account::create(['school_id' => $this->school->id, 'code' => '5999', 'name' => 'Spare', 'type' => 'expense', 'normal_balance' => 'debit']);

        $this->actingAs($manager)->delete("/accounting/accounts/{$account->id}")->assertSessionHasNoErrors();
        $this->assertNull(Account::find($account->id));
    }

    public function test_accounting_view_can_see_but_not_change_accounts(): void
    {
        $viewer = $this->makeUser('accountant', null, ['accounting-view']);
        $this->actingAs($viewer)->get('/accounting/accounts')->assertOk();
        $this->actingAs($viewer)->post('/accounting/accounts', ['code' => '9999', 'name' => 'X', 'type' => 'asset'])->assertForbidden();
    }

    public function test_accounting_permissions_are_separate_from_money_permissions(): void
    {
        // an accountant with the ordinary money permissions has NO accounting access at all by default
        $accountant = $this->makeUser('accountant', null, []);
        $this->actingAs($accountant)->get('/accounting/accounts')->assertForbidden();
        $this->actingAs($accountant)->get('/accounting/journal-entries')->assertForbidden();
    }

    // ---------------------------------------------------------------- accounting periods

    public function test_opening_an_overlapping_period_is_refused(): void
    {
        // the owner already holds accounting-period-manage by default
        AccountingPeriod::create(['school_id' => $this->school->id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);

        $this->actingAs($this->owner)->post('/accounting/periods', [
            'name' => 'Overlap', 'start_date' => '2026-06-01', 'end_date' => '2026-06-30',
        ])->assertSessionHasErrors('start_date');
    }

    public function test_closing_and_reopening_a_period_need_separate_permissions(): void
    {
        $period = AccountingPeriod::create(['school_id' => $this->school->id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $closer = $this->makeUser('accountant', null, ['accounting-period-manage']); // no reopen permission

        $this->actingAs($closer)->post("/accounting/periods/{$period->id}/close")->assertSessionHasNoErrors();
        $this->assertSame('closed', $period->fresh()->status);

        $this->actingAs($closer)->post("/accounting/periods/{$period->id}/reopen")->assertForbidden();

        $reopener = $this->makeUser('accountant', null, ['accounting-period-reopen']);
        $this->actingAs($reopener)->post("/accounting/periods/{$period->id}/reopen")->assertSessionHasNoErrors();
        $this->assertSame('open', $period->fresh()->status);
    }
}
