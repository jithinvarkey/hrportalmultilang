<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_delete_unapproved_requests_in_both_workflows(): void
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        foreach (['pending_manager', 'pending_hr'] as $status) {
            $loan = Loan::factory()->create(['employee_id' => $employee->id, 'status' => $status]);
            $this->actingAs($user, 'sanctum')->getJson("/api/v1/loans/{$loan->id}")
                ->assertOk()->assertJsonPath('loan.can_delete', true);
            $this->deleteJson("/api/v1/loans/{$loan->id}")->assertOk();
            $this->assertDatabaseMissing('loans', ['id' => $loan->id]);
            $this->assertDatabaseHas('activity_log', ['subject_id' => $loan->id, 'event' => 'deleted']);
        }
    }

    public function test_every_approval_field_blocks_deletion_even_with_stale_pending_status(): void
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);
        $approver = User::factory()->create();

        foreach (['manager', 'hr', 'finance'] as $stage) {
            foreach (['by' => $approver->id, 'at' => now()] as $suffix => $value) {
                $loan = Loan::factory()->create([
                    'employee_id' => $employee->id,
                    'status' => 'pending_hr',
                    "{$stage}_approved_{$suffix}" => $value,
                ]);
                $this->actingAs($user, 'sanctum')->getJson("/api/v1/loans/{$loan->id}")
                    ->assertOk()->assertJsonPath('loan.can_delete', false);
                $this->deleteJson("/api/v1/loans/{$loan->id}")->assertStatus(422);
                $this->assertDatabaseHas('loans', ['id' => $loan->id]);
            }
        }
    }

    public function test_other_users_cannot_delete_a_request(): void
    {
        $loan = Loan::factory()->create();
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->deleteJson("/api/v1/loans/{$loan->id}")->assertForbidden();
        $this->assertDatabaseHas('loans', ['id' => $loan->id]);
    }

    public function test_later_and_closed_statuses_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->create(['user_id' => $user->id]);
        foreach (['pending_finance', 'approved', 'disbursed', 'completed', 'rejected', 'cancelled'] as $status) {
            $loan = Loan::factory()->create(['employee_id' => $employee->id, 'status' => $status]);
            $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/loans/{$loan->id}")->assertStatus(422);
            $this->assertDatabaseHas('loans', ['id' => $loan->id]);
        }
    }
}
