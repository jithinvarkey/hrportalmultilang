<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reports page endpoints. They expose salary and payroll data, so only payroll-level roles may use them.
 *
 * @group reports
 */
class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Employee::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    /** @test */
    public function hr_manager_can_preview_every_report(): void
    {
        $hr = $this->userWithRole('hr_manager');

        // The Reports page always sends the selected month (payroll and attendance require it).
        $month = now()->format('Y-m');
        foreach (['employees', 'payroll', 'leave-balance', 'leave-requests', 'attendance', 'loans'] as $type) {
            $this->actingAs($hr, 'sanctum')
                ->getJson("/api/v1/reports/{$type}?month={$month}")
                ->assertOk()
                ->assertJsonStructure(['data']);
        }
    }

    /** @test */
    public function hr_manager_can_download_csv(): void
    {
        $this->actingAs($this->userWithRole('hr_manager'), 'sanctum')
            ->get('/api/v1/reports/download/employees/csv')
            ->assertOk();
    }

    /** @test */
    public function staff_without_payroll_access_cannot_use_reports(): void
    {
        foreach (['employee', 'department_manager', 'hr_staff', 'finance_manager'] as $role) {
            $this->actingAs($this->userWithRole($role), 'sanctum')
                ->getJson('/api/v1/reports/employees')
                ->assertForbidden();
        }
    }

    /** @test */
    public function unknown_report_type_is_not_routed(): void
    {
        $this->actingAs($this->userWithRole('hr_manager'), 'sanctum')
            ->get('/api/v1/reports/download/secrets/csv')
            ->assertNotFound();
    }
}
