<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Designation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature tests for the Department (/api/v1/departments) and
 * Designation (/api/v1/designations) admin APIs that back the
 * Admin → Departments / Designations management tabs.
 *
 * @group admin
 * @group departments
 * @group designations
 */
class DepartmentDesignationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'employee',    'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super_admin');
    }

    // ── Departments ───────────────────────────────────────────────────────

    /** @test */
    public function unauthenticated_user_cannot_list_departments(): void
    {
        $this->getJson('/api/v1/departments')->assertStatus(401);
    }

    /** @test */
    public function admin_can_create_a_department(): void
    {
        Sanctum::actingAs($this->admin);

        $payload = [
            'name'             => 'Human Resources',
            'code'             => 'HR',
            'description'      => 'People operations',
            'headcount_budget' => 25,
            'is_active'        => true,
        ];

        $this->postJson('/api/v1/departments', $payload)
            ->assertStatus(201)
            ->assertJsonPath('department.name', 'Human Resources')
            ->assertJsonPath('department.code', 'HR');

        $this->assertDatabaseHas('departments', ['code' => 'HR', 'name' => 'Human Resources']);
    }

    /** @test */
    public function department_code_must_be_unique(): void
    {
        Sanctum::actingAs($this->admin);
        Department::create(['name' => 'Finance', 'code' => 'FIN', 'is_active' => true]);

        $this->postJson('/api/v1/departments', ['name' => 'Finance 2', 'code' => 'FIN'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    /** @test */
    public function department_creation_requires_name_and_code(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/departments', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'code']);
    }

    /** @test */
    public function admin_can_update_a_department(): void
    {
        Sanctum::actingAs($this->admin);
        $dept = Department::create(['name' => 'IT', 'code' => 'IT', 'is_active' => true]);

        $this->putJson("/api/v1/departments/{$dept->id}", [
            'name'      => 'Information Technology',
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('department.name', 'Information Technology');

        $this->assertDatabaseHas('departments', ['id' => $dept->id, 'is_active' => false]);
    }

    /** @test */
    public function admin_can_soft_delete_a_department(): void
    {
        Sanctum::actingAs($this->admin);
        $dept = Department::create(['name' => 'Temp', 'code' => 'TMP', 'is_active' => true]);

        $this->deleteJson("/api/v1/departments/{$dept->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Department deleted');

        $this->assertSoftDeleted('departments', ['id' => $dept->id]);
    }

    // ── Designations ────────────────────────────────────────────────────────

    /** @test */
    public function admin_can_create_a_designation(): void
    {
        Sanctum::actingAs($this->admin);
        $dept = Department::create(['name' => 'Engineering', 'code' => 'ENG', 'is_active' => true]);

        $this->postJson('/api/v1/designations', [
            'title'         => 'Software Engineer',
            'level'         => 'mid',
            'department_id' => $dept->id,
            'min_salary'    => 8000,
            'max_salary'    => 14000,
            'is_active'     => true,
        ])
            ->assertStatus(201)
            ->assertJsonPath('designation.title', 'Software Engineer');

        $this->assertDatabaseHas('designations', ['title' => 'Software Engineer', 'level' => 'mid']);
    }

    /** @test */
    public function designation_max_salary_must_be_gte_min_salary(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/designations', [
            'title'      => 'Bad Range',
            'min_salary' => 10000,
            'max_salary' => 5000,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['max_salary']);
    }

    /** @test */
    public function designation_rejects_invalid_level(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/designations', [
            'title' => 'Wizard',
            'level' => 'archmage',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['level']);
    }

    /** @test */
    public function admin_can_update_and_delete_a_designation(): void
    {
        Sanctum::actingAs($this->admin);
        $desig = Designation::create(['title' => 'Analyst', 'is_active' => true]);

        $this->putJson("/api/v1/designations/{$desig->id}", ['title' => 'Senior Analyst', 'level' => 'senior'])
            ->assertOk()
            ->assertJsonPath('designation.title', 'Senior Analyst');

        $this->deleteJson("/api/v1/designations/{$desig->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Position deleted');

        $this->assertDatabaseMissing('designations', ['id' => $desig->id]);
    }
}
