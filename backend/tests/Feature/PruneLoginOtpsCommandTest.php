<?php

namespace Tests\Feature;

use App\Models\LoginOtp;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PruneLoginOtpsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('login_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('purpose', 32);
            $table->string('challenge_token', 80)->unique();
            $table->string('otp_hash');
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('send_count')->default(0);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('login_otps');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_it_deletes_only_otp_records_older_than_seven_days(): void
    {
        $user = User::factory()->create();
        $old = $this->createOtp($user, now()->subDays(8));
        $recent = $this->createOtp($user, now()->subDays(6));

        $this->artisan('login-otps:prune')->assertSuccessful();

        $this->assertDatabaseMissing('login_otps', ['id' => $old->id]);
        $this->assertDatabaseHas('login_otps', ['id' => $recent->id]);
    }

    public function test_dry_run_does_not_delete_matching_records(): void
    {
        $user = User::factory()->create();
        $old = $this->createOtp($user, now()->subDays(8));

        $this->artisan('login-otps:prune --dry-run')->assertSuccessful();

        $this->assertDatabaseHas('login_otps', ['id' => $old->id]);
    }

    private function createOtp(User $user, $createdAt): LoginOtp
    {
        $otp = LoginOtp::create([
            'user_id' => $user->id,
            'purpose' => LoginOtp::PURPOSE_LOGIN,
            'challenge_token' => hash('sha256', Str::uuid()->toString()),
            'otp_hash' => Hash::make('123456'),
            'expires_at' => $createdAt->copy()->addMinutes(10),
            'last_sent_at' => $createdAt,
        ]);
        $otp->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $otp;
    }
}
