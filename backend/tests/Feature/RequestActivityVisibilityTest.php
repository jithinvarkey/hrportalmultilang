<?php

namespace Tests\Feature;

use App\Http\Controllers\API\RequestManagementController;
use App\Models\Employee;
use App\Models\RequestComment;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RequestActivityVisibilityTest extends TestCase
{
    private const HR = 1;
    private const REQUESTER = 2;
    private const OTHER = 3;
    private const MANAGER = 4;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        // Isolated schema avoids unrelated MySQL-only migrations in the SQLite suite.
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('web');
        });
        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('model_id');
            $table->string('model_type');
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        foreach (['departments', 'designations'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->unsignedBigInteger('manager_id')->nullable();
                $table->softDeletes();
            });
        }
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('designation_id')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('arabic_name')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('request_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->string('category')->nullable();
            $table->unsignedBigInteger('handling_department_id')->nullable();
            $table->boolean('requires_manager_approval')->default(false);
            $table->boolean('requires_attachment')->default(false);
            $table->integer('sla_days')->default(2);
            $table->timestamps();
        });
        Schema::create('employee_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('leave_request_id')->nullable();
            $table->string('linked_service')->nullable();
            $table->unsignedBigInteger('request_type_id');
            $table->string('status');
            $table->text('details')->nullable();
            $table->text('hr_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->integer('copies_needed')->default(1);
            $table->string('attachment_path')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('completion_notes')->nullable();
            $table->string('completion_file')->nullable();
            $table->date('required_by')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('manager_approved_by')->nullable();
            $table->timestamp('manager_approved_at')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamps();
        });
        Schema::create('request_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('user_id');
            $table->text('comment');
            $table->boolean('is_internal')->default(false);
            $table->timestamps();
        });

        DB::table('roles')->insert(['id' => 1, 'name' => 'hr_manager']);
        DB::table('model_has_roles')->insert(['role_id' => 1, 'model_id' => self::HR, 'model_type' => User::class]);
        foreach ([self::HR, self::REQUESTER, self::OTHER, self::MANAGER] as $id) {
            DB::table('users')->insert(['id' => $id, 'name' => "User {$id}", 'email' => "user{$id}@example.test"]);
            DB::table('employees')->insert([
                'id' => $id, 'user_id' => $id, 'first_name' => 'User', 'last_name' => (string) $id,
                'manager_id' => $id === self::REQUESTER ? self::MANAGER : null,
            ]);
        }
        DB::table('request_types')->insert(['id' => 1, 'name' => 'Work From Home Request', 'code' => 'HR_WFH', 'category' => 'hr']);
        DB::table('employee_requests')->insert([
            'id' => 1, 'reference' => 'REQ-2026-00001', 'employee_id' => self::REQUESTER,
            'request_type_id' => 1, 'status' => 'pending', 'details' => 'One day from home',
        ]);
    }

    private function signIn(int $id): void
    {
        $user = User::findOrFail($id);
        $user->setRelation('employee', Employee::find($id));
        $this->actingAs($user);
    }

    private function controller(): RequestManagementController
    {
        return app(RequestManagementController::class);
    }

    private function visibleComments(int $userId): array
    {
        $this->signIn($userId);
        $response = $this->controller()->show(1);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        return array_column($response->getData(true)['request']['comments'], 'comment');
    }

    public function test_requester_sees_the_assignment_entry(): void
    {
        $this->signIn(self::HR);
        $response = $this->controller()->assign(new Request(['assigned_to' => self::OTHER]), 1);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $expected = ['Request assigned to User 3 and is now in progress.'];
        $this->assertSame($expected, $this->visibleComments(self::REQUESTER));
        $this->assertSame($expected, $this->visibleComments(self::HR));
    }

    public function test_requester_sees_the_wfh_manager_approval_entry(): void
    {
        DB::table('employee_requests')->update(['status' => 'pending_manager']);

        $this->signIn(self::MANAGER);
        $response = $this->controller()->managerApprove(new Request(), 1);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $this->assertSame(
            ['Approved by manager. Assigned to User 1 (HR Manager) for final approval.'],
            $this->visibleComments(self::REQUESTER)
        );
    }

    public function test_requester_sees_the_manager_approval_when_it_goes_to_the_hr_queue(): void
    {
        DB::table('request_types')->update(['code' => 'DOC_NOC']);
        DB::table('employee_requests')->update(['status' => 'pending_manager']);

        $this->signIn(self::MANAGER);
        $this->assertSame(200, $this->controller()->managerApprove(new Request(), 1)->getStatusCode());

        $this->assertSame(['Approved by manager. Forwarded to HR.'], $this->visibleComments(self::REQUESTER));
    }

    public function test_submission_is_logged(): void
    {
        DB::table('employee_requests')->delete();

        $this->signIn(self::REQUESTER);
        $response = $this->controller()->store(new Request(['request_type_id' => 1, 'details' => 'One day from home']));
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());

        $entry = RequestComment::sole();
        $this->assertSame('Request submitted.', $entry->comment);
        $this->assertSame(self::REQUESTER, (int) $entry->user_id);
        $this->assertSame($response->getData(true)['request']['id'], (int) $entry->request_id);
        $this->assertFalse($entry->is_internal);
    }

    public function test_completion_is_logged_with_and_without_notes(): void
    {
        $this->signIn(self::HR);
        $this->controller()->complete(new Request(), 1);
        DB::table('employee_requests')->update(['status' => 'in_progress']);
        $this->controller()->complete(new Request(['completion_notes' => 'Printed']), 1);

        $this->assertSame(
            ['Request completed.', 'Request completed. Printed'],
            $this->visibleComments(self::REQUESTER)
        );
    }

    public function test_rejection_is_logged_once(): void
    {
        $this->signIn(self::HR);
        $this->assertSame(200, $this->controller()->reject(new Request(['reason' => 'Not eligible']), 1)->getStatusCode());
        $this->assertSame(422, $this->controller()->reject(new Request(['reason' => 'Not eligible']), 1)->getStatusCode());

        $this->assertSame(['Request rejected. Not eligible'], $this->visibleComments(self::REQUESTER));
    }

    public function test_cancellation_is_logged(): void
    {
        $this->signIn(self::REQUESTER);
        $this->assertSame(200, $this->controller()->cancel(1)->getStatusCode());

        $this->assertSame(['Request cancelled.'], $this->visibleComments(self::REQUESTER));
    }

    public function test_request_created_from_annual_leave_is_logged(): void
    {
        $leave = new \App\Models\LeaveRequest();
        $leave->id = 9;
        $leave->employee_id = self::REQUESTER;
        $values = ['employee_id' => self::REQUESTER, 'request_type_id' => 1, 'status' => 'pending', 'details' => 'Ticket'];

        $this->signIn(self::HR);
        $controller = app(\App\Http\Controllers\API\LeaveController::class);
        $save = new \ReflectionMethod($controller, 'saveLinkedRequest');
        $save->invoke($controller, $leave, 'ticket', $values);
        $save->invoke($controller, $leave, 'ticket', $values);

        $this->assertSame([
            'Request created from annual leave request #9.',
            'Request updated from annual leave request #9.',
        ], RequestComment::orderBy('id')->pluck('comment')->all());
    }

    public function test_internal_notes_stay_hidden_from_the_requester(): void
    {
        $this->signIn(self::HR);
        $this->controller()->addComment(new Request(['comment' => 'Private note', 'is_internal' => true]), 1);
        $this->controller()->addComment(new Request(['comment' => 'Public reply']), 1);

        $this->assertSame(['Public reply'], $this->visibleComments(self::REQUESTER));
        $this->assertSame(['Private note', 'Public reply'], $this->visibleComments(self::HR));
    }

    public function test_unrelated_employee_cannot_open_the_request(): void
    {
        $this->signIn(self::OTHER);
        $this->assertSame(403, $this->controller()->show(1)->getStatusCode());
    }

    public function test_migration_reveals_only_existing_system_entries(): void
    {
        foreach ([
            'Request assigned to self and is now in progress.',
            'Approved by manager. Assigned to User 1 (HR Manager) for final approval.',
            'Approved by manager. Please double-check the dates.',
        ] as $text) {
            RequestComment::create(['request_id' => 1, 'user_id' => self::HR, 'comment' => $text, 'is_internal' => true]);
        }

        $migration = require database_path('migrations/2026_11_25_000001_show_request_activity_to_requester.php');
        $migration->up();

        $this->assertSame([false, false, true], RequestComment::orderBy('id')->get()->pluck('is_internal')->all());
    }
}
