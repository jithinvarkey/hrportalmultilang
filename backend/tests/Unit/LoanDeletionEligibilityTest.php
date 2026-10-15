<?php

namespace Tests\Unit;

use App\Models\Loan;
use Tests\TestCase;

class LoanDeletionEligibilityTest extends TestCase
{
    public function test_only_pending_requests_without_any_approval_are_eligible(): void
    {
        foreach (['pending_manager', 'pending_hr'] as $status) {
            $loan = new Loan(['status' => $status]);
            $this->assertTrue($loan->canDeleteRequest());

            foreach (['manager', 'hr', 'finance'] as $stage) {
                foreach (['by' => 1, 'at' => '2026-09-15 12:00:00'] as $suffix => $value) {
                    $approved = clone $loan;
                    $approved->setRawAttributes(['status' => $status, "{$stage}_approved_{$suffix}" => $value]);
                    $this->assertFalse($approved->canDeleteRequest());
                }
            }
        }

        foreach (['pending_finance', 'approved', 'disbursed', 'completed', 'rejected', 'cancelled'] as $status) {
            $this->assertFalse((new Loan(['status' => $status]))->canDeleteRequest());
        }
    }
}
