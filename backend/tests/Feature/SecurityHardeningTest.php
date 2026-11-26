<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LoginOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regression tests for the November 2026 security review (findings H-1, H-2, H-3, H-7, M-4, M-5).
 *
 * @group security
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $hrManager;
    private User $hrStaff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('super_admin');

        $this->hrManager = User::factory()->create();
        $this->hrManager->assignRole('hr_manager');
        $this->hrManager->givePermissionTo(['admin.manage_users', 'admin.manage_roles']);

        $this->hrStaff = User::factory()->create();
        $this->hrStaff->assignRole('hr_staff');
    }

    // ── H-1: privilege escalation through user administration ────────────

    /** @test */
    public function hr_manager_cannot_make_themselves_super_admin(): void
    {
        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson("/api/v1/admin/users/{$this->hrManager->id}/assign-role", ['role' => 'super_admin'])
            ->assertForbidden();

        $this->assertFalse($this->hrManager->fresh()->hasRole('super_admin'));
    }

    /** @test */
    public function hr_manager_cannot_create_a_super_admin(): void
    {
        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson('/api/v1/admin/users', [
                'name' => 'Backdoor', 'email' => 'backdoor@hrms.test', 'password' => 'Secret@1234', 'role' => 'super_admin',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'backdoor@hrms.test']);
    }

    /** @test */
    public function hr_manager_cannot_reset_a_super_admin_password(): void
    {
        $original = $this->superAdmin->password;

        $this->actingAs($this->hrManager, 'sanctum')
            ->putJson("/api/v1/admin/users/{$this->superAdmin->id}", ['password' => 'Taken@12345'])
            ->assertForbidden();

        $this->assertSame($original, $this->superAdmin->fresh()->password);
    }

    /** @test */
    public function hr_manager_can_still_assign_ordinary_roles_to_others(): void
    {
        $user = User::factory()->create();
        $user->assignRole('employee');

        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson("/api/v1/admin/users/{$user->id}/assign-role", ['role' => 'hr_staff'])
            ->assertOk();

        $this->assertTrue($user->fresh()->hasRole('hr_staff'));
    }

    /** @test */
    public function super_admin_can_grant_super_admin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/v1/admin/users/{$user->id}/assign-role", ['role' => 'super_admin'])
            ->assertOk();

        $this->assertTrue($user->fresh()->hasRole('super_admin'));
    }

    // ── M-5: disabling a user really disables the account ────────────────

    /** @test */
    public function a_disabled_user_cannot_sign_in_until_enabled_again(): void
    {
        $user = User::factory()->create([
            'email' => 'disable-me@hrms.test', 'password' => Hash::make('Secret@123'), 'otp_exempt' => true,
        ]);
        $user->assignRole('employee');
        $login = fn () => $this->postJson('/api/v1/auth/login', ['email' => 'disable-me@hrms.test', 'password' => 'Secret@123']);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/v1/admin/users/{$user->id}/toggle-status")
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->app['auth']->forgetGuards();
        $login()->assertForbidden()->assertJsonMissingPath('token');

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/v1/admin/users/{$user->id}/toggle-status")
            ->assertJsonPath('is_active', true);

        $this->app['auth']->forgetGuards();
        $login()->assertOk();
    }

    // ── H-2: legacy import is super-admin only ──────────────────────────

    /** @test */
    public function hr_manager_cannot_run_the_legacy_import(): void
    {
        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson('/api/v1/admin/legacy-migration/import', ['source_folder' => base_path()])
            ->assertForbidden();
    }

    // ── H-3: OTP channels of privileged users are protected ─────────────

    /** @test */
    public function hr_staff_cannot_change_the_email_or_phone_of_an_hr_manager(): void
    {
        $managerRecord = Employee::factory()->create([
            'user_id' => $this->hrManager->id, 'email' => 'boss@hrms.test', 'phone' => '0500000001',
        ]);

        $this->actingAs($this->hrStaff, 'sanctum')
            ->putJson("/api/v1/employees/{$managerRecord->id}", ['email' => 'attacker@hrms.test'])
            ->assertForbidden();

        $this->actingAs($this->hrStaff, 'sanctum')
            ->putJson("/api/v1/employees/{$managerRecord->id}", ['phone' => '0599999999'])
            ->assertForbidden();

        $this->assertDatabaseHas('employees', ['id' => $managerRecord->id, 'email' => 'boss@hrms.test', 'phone' => '0500000001']);
    }

    /** @test */
    public function hr_staff_cannot_change_any_login_email_but_hr_manager_can(): void
    {
        $user = User::factory()->create(['email' => 'worker@hrms.test']);
        $user->assignRole('employee');
        $record = Employee::factory()->create(['user_id' => $user->id, 'email' => 'worker@hrms.test']);

        $this->actingAs($this->hrStaff, 'sanctum')
            ->putJson("/api/v1/employees/{$record->id}", ['email' => 'other@hrms.test'])
            ->assertForbidden();

        $this->actingAs($this->hrManager, 'sanctum')
            ->putJson("/api/v1/employees/{$record->id}", ['email' => 'worker2@hrms.test'])
            ->assertOk();

        $this->assertSame('worker2@hrms.test', $user->fresh()->email);
    }

    // ── H-7: pay fields are not sent to line managers ───────────────────

    /** @test */
    public function a_line_manager_does_not_receive_team_salaries_in_leave_responses(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('department_manager');
        $managerRecord = Employee::factory()->create(['user_id' => $manager->id]);

        $staff = User::factory()->create();
        $staff->assignRole('employee');
        $staffRecord = Employee::factory()->create([
            'user_id' => $staff->id, 'manager_id' => $managerRecord->id, 'salary' => 12345, 'housing_allowance' => 3000,
        ]);

        $type = LeaveType::factory()->create();
        $leave = LeaveRequest::factory()->create([
            'employee_id' => $staffRecord->id, 'leave_type_id' => $type->id, 'status' => 'pending',
        ]);

        $response = $this->actingAs($manager, 'sanctum')->getJson("/api/v1/leave/requests/{$leave->id}")->assertOk();
        $this->assertStringNotContainsString('"salary"', $response->getContent());
        $this->assertStringNotContainsString('housing_allowance', $response->getContent());

        $list = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/leave/requests')->assertOk();
        $this->assertStringNotContainsString('"salary"', $list->getContent());
    }

    /** @test */
    public function payroll_roles_and_the_employee_still_see_pay_fields(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('employee');
        $record = Employee::factory()->create(['user_id' => $staff->id, 'salary' => 12345]);

        $this->actingAs($this->hrManager, 'sanctum');
        $this->assertArrayHasKey('salary', $record->fresh()->toArray());

        $this->actingAs($staff, 'sanctum');
        $this->assertArrayHasKey('salary', $record->fresh()->toArray());

        $this->actingAs($this->hrStaff, 'sanctum');
        $this->assertArrayNotHasKey('salary', $record->fresh()->toArray());
    }

    // ── M-4: forgot-password does not reveal which emails exist ─────────

    /** @test */
    public function forgot_password_answers_the_same_for_known_and_unknown_emails(): void
    {
        $user = User::factory()->create(['email' => 'known@hrms.test']);
        Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'known@hrms.test'])->assertOk();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@hrms.test'])->assertOk();

        $this->assertSame(array_keys($known->json()), array_keys($unknown->json()));
        $this->assertSame($known->json('message'), $unknown->json('message'));
        $this->assertSame(1, LoginOtp::count()); // only the real account got an OTP
    }
}
