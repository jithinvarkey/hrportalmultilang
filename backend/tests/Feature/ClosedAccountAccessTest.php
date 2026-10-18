<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LoginOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Employees who have left (terminated, inactive or archived) may not sign in or keep a session;
 * HR sends them final documents directly.
 *
 * @group auth
 */
class ClosedAccountAccessTest extends TestCase
{
    use RefreshDatabase;

    private function account(?string $employeeStatus, bool $archived = false, string $email = 'person@hrms.test'): User
    {
        $user = User::factory()->create([
            'email'      => $email,
            'password'   => Hash::make('Secret@123'),
            'otp_exempt' => true,
        ]);

        if ($employeeStatus !== null) {
            $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => $employeeStatus]);
            if ($archived) {
                $employee->delete();
            }
        }

        return $user;
    }

    private function login(string $email = 'person@hrms.test')
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Secret@123']);
    }

    /** @test */
    public function terminated_employee_cannot_sign_in(): void
    {
        $this->account('terminated');

        $this->login()
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account is no longer active. Please contact HR.')
            ->assertJsonMissingPath('token');
    }

    /** @test */
    public function inactive_employee_cannot_sign_in(): void
    {
        $this->account('inactive');

        $this->login()->assertForbidden()->assertJsonMissingPath('token');
    }

    /** @test */
    public function archived_employee_cannot_sign_in(): void
    {
        $this->account('active', archived: true);

        $this->login()->assertForbidden()->assertJsonMissingPath('token');
    }

    /** @test */
    public function wrong_password_does_not_reveal_that_an_account_is_closed(): void
    {
        $this->account('terminated');

        $this->postJson('/api/v1/auth/login', ['email' => 'person@hrms.test', 'password' => 'Wrong@123'])
            ->assertUnprocessable();
    }

    /** @test */
    public function active_probation_and_on_leave_employees_and_admins_without_employee_record_can_sign_in(): void
    {
        $this->account('active', email: 'active@hrms.test');
        $this->account('probation', email: 'probation@hrms.test');
        $this->account('on_leave', email: 'onleave@hrms.test');
        $this->account(null, email: 'admin@hrms.test');

        foreach (['active@hrms.test', 'probation@hrms.test', 'onleave@hrms.test', 'admin@hrms.test'] as $email) {
            $this->login($email)->assertOk()->assertJsonStructure(['token']);
        }
    }

    /** @test */
    public function existing_session_ends_when_the_employee_is_terminated(): void
    {
        $user = $this->account('active');
        $token = $this->login()->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        Employee::where('user_id', $user->id)->update(['status' => 'terminated']);

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Your account is no longer active. Please contact HR.');

        // The token itself is revoked, not just refused once.
        $this->assertSame(0, $user->tokens()->count());
    }

    /** @test */
    public function forgot_password_gives_a_closed_account_the_generic_answer_without_an_otp(): void
    {
        $this->account('terminated');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'person@hrms.test'])
            ->assertOk()
            ->assertJsonMissingPath('challenge_token')
            ->assertJsonPath('message', 'If an account exists for that email, an OTP has been sent.');

        $this->assertSame(0, LoginOtp::count());
    }

    /** @test */
    public function the_refusal_is_translated_for_arabic_users(): void
    {
        $this->account('terminated');

        $this->withHeader('Accept-Language', 'ar')->login()
            ->assertForbidden()
            ->assertJsonPath('message', 'لم يعد حسابك نشطاً. يرجى التواصل مع الموارد البشرية.');
    }
}
