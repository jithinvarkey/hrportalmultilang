<?php

namespace Tests\Feature;

use App\Http\Controllers\API\LeaveController;
use App\Models\Employee;
use App\Models\User;
use App\Services\LeaveService;
use App\Services\RequestActivityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinanceLeaveAccessTest extends FinanceEmployeeScopeTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
        });
        Schema::create('users', fn (Blueprint $table) => $table->id());
        DB::table('users')->insert(['id' => 1]);
        Schema::create('leave_types', fn (Blueprint $table) => $table->id());
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('manager_approved_by')->nullable();
            $table->timestamp('manager_approved_at')->nullable();
            $table->text('manager_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('rejected_stage')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();
        });
        DB::table('employees')->where('id', 2)->update(['manager_id' => 1]);
        foreach ([1, 2, 3] as $id) {
            DB::table('leave_requests')->insert(['id' => $id, 'employee_id' => $id]);
        }
        $user = new User();
        $user->id = 1;
        $user->setRelation('employee', Employee::findOrFail(1));
        $this->actingAs($user);
        $this->mock(RequestActivityService::class)->shouldReceive('record')->zeroOrMoreTimes();
        $service = $this->mock(LeaveService::class);
        $service->shouldReceive('notifyEmployee')->zeroOrMoreTimes();
        $service->shouldReceive('notifyManager')->zeroOrMoreTimes();
        $service->shouldReceive('updateLeaveBalance')->zeroOrMoreTimes();
    }

    public function test_finance_can_approve_direct_report_but_not_self_other_team_or_hr_stage(): void
    {
        $controller = app(LeaveController::class);
        $request = Request::create('/', 'POST');
        $this->assertSame(403, $controller->approve($request, 1)->getStatusCode());
        $this->assertSame(403, $controller->approve($request, 3)->getStatusCode());
        $this->assertSame(200, $controller->approve($request, 2)->getStatusCode());
        $this->assertDatabaseHas('leave_requests', ['id' => 2, 'status' => 'manager_approved', 'manager_approved_by' => 1]);
        $this->assertSame(403, $controller->approve($request, 2)->getStatusCode());
    }

    public function test_finance_can_reject_only_direct_reports_at_manager_stage(): void
    {
        $controller = app(LeaveController::class);
        $request = Request::create('/', 'POST', ['reason' => 'Coverage required']);
        $this->assertSame(403, $controller->reject($request, 1)->getStatusCode());
        $this->assertSame(403, $controller->reject($request, 3)->getStatusCode());
        DB::table('leave_requests')->where('id', 2)->update(['status' => 'manager_approved']);
        $this->assertSame(403, $controller->reject($request, 2)->getStatusCode());
        DB::table('leave_requests')->where('id', 2)->update(['status' => 'pending']);
        $this->assertSame(200, $controller->reject($request, 2)->getStatusCode());
        $this->assertDatabaseHas('leave_requests', ['id' => 2, 'status' => 'rejected', 'rejected_stage' => 'manager']);
    }
}
