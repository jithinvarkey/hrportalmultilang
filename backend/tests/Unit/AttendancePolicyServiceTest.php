<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Attendance\AttendancePolicyService;
use PHPUnit\Framework\TestCase;

class AttendancePolicyServiceTest extends TestCase
{
    private array $settings = [
        'work_start' => '09:00',
        'late_after_minutes' => 15,
    ];

    public function test_check_in_before_work_start_is_present(): void
    {
        $policy = new AttendancePolicyService();

        $this->assertSame('present', $policy->statusForCheckIn('08:49:48', $this->settings));
    }

    public function test_check_in_at_late_threshold_is_present(): void
    {
        $policy = new AttendancePolicyService();

        $this->assertSame('present', $policy->statusForCheckIn('09:15:00', $this->settings));
    }

    public function test_check_in_after_late_threshold_is_late(): void
    {
        $policy = new AttendancePolicyService();

        $this->assertSame('late', $policy->statusForCheckIn('09:15:01', $this->settings));
    }

    public function test_report_recalculates_automatic_present_and_late_rows(): void
    {
        $policy = new AttendancePolicyService();

        $this->assertSame('present', $policy->statusForReport('09:10:00', 'late', 'api', $this->settings));
        $this->assertSame('late', $policy->statusForReport('09:20:00', 'present', 'biometric', $this->settings));
    }

    public function test_report_preserves_manual_and_special_statuses(): void
    {
        $policy = new AttendancePolicyService();

        $this->assertSame('present', $policy->statusForReport('09:20:00', 'present', 'manual', $this->settings));
        $this->assertSame('half_day', $policy->statusForReport('09:20:00', 'half_day', 'api', $this->settings));
    }
}
