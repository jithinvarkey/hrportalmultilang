<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AnnualLeaveCarryForwardPolicy;
use PHPUnit\Framework\TestCase;

class AnnualLeaveCarryForwardPolicyTest extends TestCase
{
    public function test_it_carries_the_remaining_balance_below_the_limit(): void
    {
        $policy = new AnnualLeaveCarryForwardPolicy;

        $this->assertSame(7.5, $policy->calculate(true, 7.5, 10));
    }

    public function test_it_respects_a_configured_limit_above_ten_days(): void
    {
        $policy = new AnnualLeaveCarryForwardPolicy;

        $this->assertSame(18.0, $policy->calculate(true, 18, 20));
    }

    public function test_all_carries_the_entire_positive_balance(): void
    {
        $policy = new AnnualLeaveCarryForwardPolicy;
        $this->assertSame(25.5, $policy->calculate(true, 25.5, 10, true));
        $this->assertSame(0.0, $policy->calculate(true, -2, 10, true));
        $this->assertSame(0.0, $policy->calculate(false, 25, 10, true));
        $this->assertSame(0.0, $policy->calculate(true, 25, 0));
    }

    public function test_it_respects_a_lower_configured_limit(): void
    {
        $policy = new AnnualLeaveCarryForwardPolicy;

        $this->assertSame(5.0, $policy->calculate(true, 8, 5));
    }

    public function test_it_does_not_carry_when_policy_is_disabled(): void
    {
        $policy = new AnnualLeaveCarryForwardPolicy;

        $this->assertSame(0.0, $policy->calculate(false, 8, 10));
    }

    public function test_it_preserves_an_existing_positive_carry_forward_value(): void
    {
        $policy = new AnnualLeaveCarryForwardPolicy;

        $this->assertSame(7.5, $policy->preserveExisting(7.5, 10));
    }

    public function test_it_uses_the_calculated_value_when_existing_value_is_zero(): void
    {
        $policy = new AnnualLeaveCarryForwardPolicy;

        $this->assertSame(10.0, $policy->preserveExisting(0, 10));
    }
}
