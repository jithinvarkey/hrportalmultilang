<?php

namespace Tests\Feature;

use App\Http\Controllers\API\EmployeeController;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinanceEmployeeScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Isolated schema avoids unrelated MySQL-only migrations in the SQLite suite.
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('model_id');
            $table->string('model_type');
        });
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->softDeletes();
        });
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('status')->default('active');
            $table->date('hire_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::table('roles')->insert(['id' => 1, 'name' => 'finance_manager']);
        DB::table('model_has_roles')->insert(['role_id' => 1, 'model_id' => 1, 'model_type' => User::class]);
        DB::table('departments')->insert([['id' => 10], ['id' => 20]]);
        foreach ([1 => 10, 2 => 10, 3 => 20, 4 => null] as $id => $department) {
            DB::table('employees')->insert([
                'id' => $id, 'department_id' => $department,
                'first_name' => "Employee {$id}", 'last_name' => 'Test',
            ]);
        }
    }

    private function signIn(?Employee $employee): void
    {
        $user = new User();
        $user->id = 1;
        $user->setRelation('employee', $employee);
        $this->actingAs($user);
    }

    public function test_list_and_counts_only_include_active_and_probation_employees(): void
    {
        foreach (['probation', 'inactive', 'terminated', 'on_leave'] as $status) {
            DB::table('employees')->insert([
                'department_id' => 10, 'first_name' => $status,
                'last_name' => 'Test', 'status' => $status,
            ]);
        }
        $this->signIn(Employee::findOrFail(1));
        $controller = app(EmployeeController::class);
        $request = Request::create('/api/v1/employees');
        $data = $controller->index($request)->getData(true);
        $this->assertSame(2, $data['meta']['total']);
        $this->assertEqualsCanonicalizing(['active', 'probation'], array_column($data['data'], 'status'));
        $this->assertSame(2, $controller->stats($request)->getData(true)['total']);
        foreach (['inactive', 'terminated', 'on_leave'] as $status) {
            $filtered = Request::create('/api/v1/employees', 'GET', ['status' => $status]);
            $this->assertSame([], $controller->index($filtered)->getData(true)['data']);
        }
    }

    public function test_finance_list_and_counts_are_limited_to_own_department(): void
    {
        $this->signIn(Employee::findOrFail(1));
        $controller = app(EmployeeController::class);
        $request = Request::create('/api/v1/employees');
        $data = $controller->index($request)->getData(true);
        $this->assertSame([2], array_column($data['data'], 'id'));
        $this->assertSame(1, $controller->stats($request)->getData(true)['total']);

        $dashboard = Request::create('/api/v1/employees', 'GET', ['dashboard_scope' => '1']);
        $this->assertSame(2, $controller->index($dashboard)->getData(true)['meta']['total']);
        $this->assertSame(2, $controller->stats($dashboard)->getData(true)['total']);
    }

    public function test_hr_roles_can_list_and_filter_every_employee_status(): void
    {
        foreach (['probation', 'inactive', 'resigned', 'terminated', 'on_leave'] as $status) {
            DB::table('employees')->insert([
                'department_id' => 20, 'first_name' => $status,
                'last_name' => 'Test', 'status' => $status,
            ]);
        }
        $this->signIn(Employee::findOrFail(1));
        $controller = app(EmployeeController::class);
        foreach (['hr_manager', 'hr_staff', 'super_admin'] as $role) {
            DB::table('roles')->where('id', 1)->update(['name' => $role]);
            $request = Request::create('/api/v1/employees');
            $this->assertSame(9, $controller->index($request)->getData(true)['meta']['total']);
            $this->assertSame(9, $controller->stats($request)->getData(true)['total']);
            foreach (['probation', 'inactive', 'resigned', 'terminated', 'on_leave'] as $status) {
                $filtered = Request::create('/api/v1/employees', 'GET', ['status' => $status]);
                $data = $controller->index($filtered)->getData(true);
                $this->assertSame([$status], array_column($data['data'], 'status'));
            }
        }
    }

    public function test_missing_employee_or_department_does_not_expose_other_employees(): void
    {
        $controller = app(EmployeeController::class);
        $request = Request::create('/api/v1/employees');
        $this->signIn(null);
        $this->assertSame([], $controller->index($request)->getData(true)['data']);
        $this->assertSame(0, $controller->stats($request)->getData(true)['total']);

        $this->signIn(Employee::findOrFail(4));
        $this->assertSame([4], array_column($controller->index($request)->getData(true)['data'], 'id'));
        $this->assertSame(1, $controller->stats($request)->getData(true)['total']);
    }
}
