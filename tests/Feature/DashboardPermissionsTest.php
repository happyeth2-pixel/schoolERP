<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSchool;
use Tests\TestCase;

/**
 * Found while testing the role-based home page: the branch-admin dashboard used to send the fee-collection
 * figures and the pending-payment count to EVERY branch admin, including a front-desk registrar who holds
 * neither the finance nor the payment-verification permissions. Money figures now follow the same
 * permissions as the finance screens; the home page only ever shows what the server sent.
 */
class DashboardPermissionsTest extends TestCase
{
    use BuildsSchool;
    use RefreshDatabase;

    private const MONEY_KEYS = ['fee_collection_rate', 'fees_total', 'fees_paid', 'outstanding_fees', 'fees_waived', 'fees_refunded'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchool();
    }

    private function stats($user): array
    {
        return $this->props($this->actingAs($user)->get('/dashboard')->assertOk())['stats'];
    }

    public function test_a_front_desk_branch_admin_gets_no_money_figures(): void
    {
        $registrar = $this->makeUser('branch_admin', $this->branchA->id, ['manage-students', 'manage-parents', 'manage-classes']);

        $stats = $this->stats($registrar);

        foreach (self::MONEY_KEYS as $key) {
            $this->assertNull($stats[$key], "{$key} must not be sent to someone without a finance permission");
        }
        $this->assertNull($stats['pending_payments_count'], 'the pending-proof count is for people who can verify payments');
        $this->assertArrayHasKey('total_students', $stats, 'their everyday figures are still there');
    }

    public function test_view_financial_reports_alone_shows_money_but_not_the_verification_queue(): void
    {
        $reader = $this->makeUser('branch_admin', $this->branchA->id, ['view-financial-reports']);

        $stats = $this->stats($reader);

        $this->assertNotNull($stats['fees_total']);
        $this->assertNull($stats['pending_payments_count']);
    }

    public function test_verifying_payments_alone_shows_the_queue_count_but_not_the_money_figures(): void
    {
        $cashier = $this->makeUser('branch_admin', $this->branchA->id, ['verify-payments']);

        $stats = $this->stats($cashier);

        $this->assertNotNull($stats['pending_payments_count']);
        $this->assertNull($stats['fees_total']);
    }

    public function test_owner_and_a_default_branch_admin_still_get_everything(): void
    {
        $fullAdmin = $this->makeUser('branch_admin', $this->branchA->id);

        foreach ([$this->owner, $fullAdmin] as $user) {
            $stats = $this->stats($user);
            // (the collection RATE is legitimately null while a school has no invoices, so it is not asserted here)
            foreach (['fees_total', 'fees_paid', 'outstanding_fees', 'fees_waived', 'fees_refunded', 'pending_payments_count'] as $key) {
                $this->assertNotNull($stats[$key] ?? null, "{$key} should still be sent to {$user->getRoleNames()->first()}");
            }
            $this->assertArrayHasKey('fee_collection_rate', $stats);
        }
    }
}
