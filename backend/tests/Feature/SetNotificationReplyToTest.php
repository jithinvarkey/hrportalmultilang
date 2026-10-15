<?php

namespace Tests\Feature;

use App\Listeners\SetNotificationReplyTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class SetNotificationReplyToTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');
        Schema::dropIfExists('system_settings');

        parent::tearDown();
    }

    public function test_it_adds_the_configured_email_as_reply_to(): void
    {
        DB::table('system_settings')->insert([
            'key' => 'notification_reply_to_email',
            'value' => 'hr@example.com',
        ]);

        $message = (new Email())->from('noreply@example.com')->to('employee@example.com');

        app(SetNotificationReplyTo::class)->handle(new MessageSending($message));

        $replyTo = $message->getReplyTo();

        $this->assertCount(1, $replyTo);
        $this->assertSame('hr@example.com', $replyTo[0]->getAddress());
    }

    public function test_it_leaves_email_sendable_when_no_reply_to_is_configured(): void
    {
        $message = (new Email())->from('noreply@example.com')->to('employee@example.com');

        app(SetNotificationReplyTo::class)->handle(new MessageSending($message));

        $this->assertSame([], $message->getReplyTo());
    }

    public function test_it_falls_back_to_the_hr_manager_email(): void
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'hr_manager',
            'guard_name' => 'web',
        ]);
        $managerId = DB::table('users')->insertGetId([
            'name' => 'HR Manager',
            'email' => 'manager@example.com',
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => 'App\\Models\\User',
            'model_id' => $managerId,
        ]);

        $message = (new Email())->from('noreply@example.com')->to('employee@example.com');

        app(SetNotificationReplyTo::class)->handle(new MessageSending($message));

        $this->assertSame('manager@example.com', $message->getReplyTo()[0]->getAddress());
    }

    public function test_the_admin_setting_takes_priority_over_the_hr_manager(): void
    {
        DB::table('system_settings')->insert([
            'key' => 'notification_reply_to_email',
            'value' => 'configured@example.com',
        ]);
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'hr_manager',
            'guard_name' => 'web',
        ]);
        $managerId = DB::table('users')->insertGetId([
            'name' => 'HR Manager',
            'email' => 'manager@example.com',
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => 'App\\Models\\User',
            'model_id' => $managerId,
        ]);

        $message = (new Email())->from('noreply@example.com')->to('employee@example.com');

        app(SetNotificationReplyTo::class)->handle(new MessageSending($message));

        $this->assertSame('configured@example.com', $message->getReplyTo()[0]->getAddress());
    }
}
