<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceDevice;
use App\Models\AttendanceLocation;
use App\Models\AttendanceLog;
use App\Models\DeviceAttendanceLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\AttendanceDeviceService;
use App\Services\Attendance\AttendancePolicyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Check-in / check-out from a phone, limited to the radius around an attendance location.
 *
 * @group attendance
 */
class MobilePunchTest extends TestCase
{
    use RefreshDatabase;

    private const OFFICE = ['latitude' => 24.7136, 'longitude' => 46.6753];

    /** About 445 m north of the office. */
    private const INSIDE = ['latitude' => 24.7176, 'longitude' => 46.6753, 'accuracy' => 20];

    /** About 556 m north of the office. */
    private const OUTSIDE = ['latitude' => 24.7186, 'longitude' => 46.6753, 'accuracy' => 20];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-11-04 08:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The policy reads a real file in storage, so tests supply the switch instead of touching it. */
    private function mobilePunching(bool $enabled): void
    {
        $this->partialMock(AttendancePolicyService::class, function ($mock) use ($enabled): void {
            $mock->shouldReceive('settings')->andReturn(array_merge(
                AttendancePolicyService::DEFAULTS,
                ['mobile_punch_enabled' => $enabled]
            ));
        });
    }

    private function office(array $overrides = []): AttendanceLocation
    {
        return AttendanceLocation::create(array_merge(self::OFFICE, ['name' => 'Head Office'], $overrides));
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Employee::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['mobile_punch_enabled' => true])->save();

        return $user->fresh();
    }

    /** @test */
    public function new_users_start_with_mobile_punching_disabled(): void
    {
        $this->assertFalse(User::factory()->create()->fresh()->mobile_punch_enabled);
    }

    /** @test */
    public function employee_can_check_in_and_out_inside_the_radius(): void
    {
        $this->mobilePunching(true);
        $office = $this->office();
        $user = $this->userWithRole('employee');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/checkin', self::INSIDE)
            ->assertOk()
            ->assertJsonPath('log.source', 'mobile')
            ->assertJsonPath('log.check_in_location_id', $office->id);

        Carbon::setTestNow(Carbon::parse('2026-11-04 17:00:00'));

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/checkout', self::INSIDE)
            ->assertOk()
            ->assertJsonPath('log.total_minutes', 540)
            ->assertJsonPath('log.check_out_location_id', $office->id);

        $log = AttendanceLog::firstOrFail();
        $this->assertEqualsWithDelta(445, $log->check_in_distance_meters, 2);
    }

    /** @test */
    public function department_manager_gets_the_check_in_card_on_the_dashboard(): void
    {
        $this->mobilePunching(true);
        $this->office();
        $manager = $this->userWithRole('department_manager');

        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/attendance/checkin', self::INSIDE)->assertOk();

        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/attendance/dashboard')
            ->assertOk()
            ->assertJsonPath('type', 'department')
            ->assertJsonPath('punch_access.can_punch', true)
            ->assertJsonPath('punch_access.requires_location', true)
            ->assertJsonPath('today_log.source', 'mobile');
    }

    /** @test */
    public function department_manager_switched_off_individually_cannot_punch(): void
    {
        $this->mobilePunching(true);
        $this->office();
        $manager = $this->userWithRole('department_manager');
        $manager->forceFill(['mobile_punch_enabled' => false])->save();

        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/attendance/dashboard')
            ->assertJsonPath('punch_access.can_punch', false);
    }

    /** @test */
    public function the_latest_check_out_of_the_day_is_the_one_kept(): void
    {
        $this->mobilePunching(true);
        $this->office();
        $user = $this->userWithRole('employee');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/checkin', self::INSIDE)->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-11-04 12:00:00'));
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/checkout', self::INSIDE)
            ->assertOk()
            ->assertJsonPath('log.total_minutes', 240);

        Carbon::setTestNow(Carbon::parse('2026-11-04 17:00:00'));
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/checkout', self::INSIDE)
            ->assertOk()
            ->assertJsonPath('log.check_out', '17:00:00')
            ->assertJsonPath('log.total_minutes', 540);

        $this->assertSame(1, AttendanceLog::count());
    }

    /** @test */
    public function punch_outside_the_radius_is_refused_and_not_recorded(): void
    {
        $this->mobilePunching(true);
        $this->office();
        $user = $this->userWithRole('employee');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/checkin', self::OUTSIDE)
            ->assertStatus(422)
            ->assertJsonPath('distance_meters', 556);

        $this->assertSame(0, AttendanceLog::count());
    }

    /** @test */
    public function check_out_outside_the_radius_is_refused(): void
    {
        $this->mobilePunching(true);
        $this->office();
        $user = $this->userWithRole('employee');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/checkin', self::INSIDE)->assertOk();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/checkout', self::OUTSIDE)->assertStatus(422);

        $this->assertNull(AttendanceLog::firstOrFail()->check_out);
    }

    /** @test */
    public function imprecise_location_is_refused_even_when_inside_the_radius(): void
    {
        $this->mobilePunching(true);
        $this->office();

        $this->actingAs($this->userWithRole('employee'), 'sanctum')
            ->postJson('/api/v1/attendance/checkin', array_merge(self::INSIDE, ['accuracy' => 151]))
            ->assertStatus(422);

        $this->assertSame(0, AttendanceLog::count());
    }

    /** @test */
    public function punch_without_coordinates_is_refused(): void
    {
        $this->mobilePunching(true);
        $this->office();

        $this->actingAs($this->userWithRole('employee'), 'sanctum')
            ->postJson('/api/v1/attendance/checkin', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude', 'longitude', 'accuracy']);
    }

    /** @test */
    public function punch_is_refused_when_no_active_location_exists(): void
    {
        $this->mobilePunching(true);
        $this->office(['is_active' => false]);

        $this->actingAs($this->userWithRole('employee'), 'sanctum')
            ->postJson('/api/v1/attendance/checkin', self::INSIDE)
            ->assertStatus(422);
    }

    /** @test */
    public function a_larger_radius_at_a_farther_site_still_counts(): void
    {
        $this->mobilePunching(true);
        $this->office(['radius_meters' => 100]);
        $wide = $this->office(['name' => 'Yard', 'latitude' => 24.7236, 'radius_meters' => 1000]);

        $this->actingAs($this->userWithRole('employee'), 'sanctum')
            ->postJson('/api/v1/attendance/checkin', self::INSIDE)
            ->assertOk()
            ->assertJsonPath('log.check_in_location_id', $wide->id);
    }

    /** @test */
    public function employee_cannot_punch_when_the_company_switch_is_off(): void
    {
        $this->mobilePunching(false);
        $this->office();
        $user = $this->userWithRole('employee');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/checkin', self::INSIDE)->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/attendance/today')
            ->assertJsonPath('punch_access.can_punch', false);
    }

    /** @test */
    public function employee_switched_off_individually_cannot_punch(): void
    {
        $this->mobilePunching(true);
        $this->office();
        $user = $this->userWithRole('employee');
        $user->forceFill(['mobile_punch_enabled' => false])->save();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/checkin', self::INSIDE)->assertForbidden();
    }

    /** @test */
    public function hr_keeps_the_plain_check_in_while_mobile_punching_is_off(): void
    {
        $this->mobilePunching(false);

        $this->actingAs($this->userWithRole('hr_manager'), 'sanctum')
            ->postJson('/api/v1/attendance/checkin')
            ->assertOk()
            ->assertJsonPath('log.source', 'api');
    }

    /** @test */
    public function hr_must_be_inside_the_radius_once_mobile_punching_is_on(): void
    {
        $this->mobilePunching(true);
        $this->office();

        $this->actingAs($this->userWithRole('hr_manager'), 'sanctum')
            ->postJson('/api/v1/attendance/checkin', self::OUTSIDE)
            ->assertStatus(422);
    }

    /** @test */
    public function check_out_without_a_check_in_is_refused(): void
    {
        $this->mobilePunching(true);
        $this->office();

        $this->actingAs($this->userWithRole('employee'), 'sanctum')
            ->postJson('/api/v1/attendance/checkout', self::INSIDE)
            ->assertStatus(422);
    }

    /** @test */
    public function only_hr_can_manage_locations(): void
    {
        $payload = array_merge(self::OFFICE, ['name' => 'Branch', 'radius_meters' => 500, 'is_active' => true]);

        $this->actingAs($this->userWithRole('employee'), 'sanctum')
            ->postJson('/api/v1/attendance/locations', $payload)
            ->assertForbidden();

        $hr = $this->userWithRole('hr_manager');

        $id = $this->actingAs($hr, 'sanctum')
            ->postJson('/api/v1/attendance/locations', $payload)
            ->assertCreated()
            ->json('location.id');

        $this->actingAs($hr, 'sanctum')
            ->putJson("/api/v1/attendance/locations/{$id}", array_merge($payload, ['radius_meters' => 300]))
            ->assertOk();
        $this->assertSame(300, AttendanceLocation::findOrFail($id)->radius_meters);

        $this->actingAs($hr, 'sanctum')->deleteJson("/api/v1/attendance/locations/{$id}")->assertOk();
        $this->assertSame(0, AttendanceLocation::count());
    }

    /** @test */
    public function user_admin_can_switch_mobile_punching_for_a_user(): void
    {
        $target = $this->userWithRole('employee');

        $this->actingAs($this->userWithRole('super_admin'), 'sanctum')
            ->putJson("/api/v1/admin/users/{$target->id}/mobile-punch", ['mobile_punch_enabled' => false])
            ->assertOk()
            ->assertJsonPath('user.mobile_punch_enabled', false);
    }

    /** @test */
    public function biometric_sync_keeps_a_mobile_check_in(): void
    {
        $user = $this->userWithRole('employee');
        $log = AttendanceLog::create([
            'employee_id' => $user->employee->id,
            'date' => '2026-11-04',
            'check_in' => '08:00:00',
            'status' => 'present',
            'source' => 'mobile',
        ]);

        $device = AttendanceDevice::create(['name' => 'Gate', 'ip_address' => '10.0.0.1']);
        DeviceAttendanceLog::create([
            'device_id' => $device->id,
            'device_employee_number' => '1',
            'employee_id' => $user->employee->id,
            'punch_time' => '2026-11-04 17:00:00',
        ]);

        app(AttendanceDeviceService::class)->processDeviceLogs($device);

        $log->refresh();
        $this->assertSame('08:00:00', $log->check_in);
        $this->assertSame('17:00:00', $log->check_out);
        $this->assertSame(540, $log->total_minutes);
        $this->assertSame('present', $log->status);
        $this->assertSame('mobile', $log->source);
    }
}
