<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\MailServerTestMail;
use App\Models\User;
use App\Services\MailServerSettingsService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

/**
 * Outgoing mail (SMTP) server managed from Admin > Settings instead of .env.
 *
 * @group mail
 */
class MailServerSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/settings/mail-server';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        // This machine's .env may redirect all mail to a test inbox (registered while the app boots).
        Event::forget(MessageSending::class);
        $this->freshProcess();
    }

    /** Stands in for the MAIL_* values of .env. */
    private function useEnvMail(): void
    {
        config([
            'mail.default' => 'array',
            'mail.test_email' => null,
            'mail.force_test_email' => false,
            'mail.mailers.smtp' => [
                'transport' => 'smtp', 'url' => null, 'host' => 'env.smtp.test', 'port' => 587, 'encryption' => 'tls',
                'username' => 'env@hr.test', 'password' => 'env-secret', 'timeout' => null, 'local_domain' => null,
            ],
            'mail.from' => ['address' => 'env@hr.test', 'name' => 'Env Sender'],
        ]);
    }

    /** A new request / worker process: .env config only, nothing read from the database or built yet. */
    private function freshProcess(): void
    {
        $this->useEnvMail();
        $this->app->forgetInstance(MailServerSettingsService::class);
        $this->app->forgetInstance('mail.manager');
        Mail::clearResolvedInstances();
    }

    private function actingWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'tls',
            'username' => 'hr@company.test', 'password' => 's3cret pass',
            'from_address' => 'hr@company.test', 'from_name' => 'HR Portal',
        ], $overrides);
    }

    private function stored(string $field): ?string
    {
        return DB::table('system_settings')->where('key', 'mail_server_' . $field)->value('value');
    }

    private function smtpTransport(): EsmtpTransport
    {
        return app('mail.manager')->mailer('smtp')->getSymfonyTransport();
    }

    /** @test */
    public function only_a_super_admin_can_read_change_or_test_the_mail_server(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();

        $this->actingWithRole('hr_manager');
        $this->getJson(self::URL)->assertForbidden();
        $this->putJson(self::URL, $this->payload())->assertForbidden();
        $this->postJson(self::URL . '/test', ['to' => 'a@b.test'])->assertForbidden();
        $this->assertNull($this->stored('host'));
    }

    /** @test */
    public function env_settings_are_used_until_the_page_is_saved(): void
    {
        $this->actingWithRole('super_admin');

        $response = $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('settings.source', 'env')
            ->assertJsonPath('settings.host', 'env.smtp.test')
            ->assertJsonPath('settings.has_password', true);

        $this->assertStringNotContainsString('env-secret', $response->getContent());
        $this->assertSame('env.smtp.test', $this->smtpTransport()->getStream()->getHost());
        $this->assertSame('env-secret', $this->smtpTransport()->getPassword());
    }

    /** @test */
    public function saved_settings_replace_env_for_the_connection_and_the_sender(): void
    {
        $this->actingWithRole('super_admin');
        $this->putJson(self::URL, $this->payload(['port' => 2587]))->assertOk()->assertJsonPath('settings.source', 'database');

        $this->freshProcess();
        $transport = $this->smtpTransport();
        $this->assertSame('smtp.office365.com', $transport->getStream()->getHost());
        $this->assertSame(2587, $transport->getStream()->getPort());
        $this->assertSame('hr@company.test', $transport->getUsername());
        $this->assertSame('s3cret pass', $transport->getPassword());
        $this->assertFalse($transport->getStream()->isTLS());

        Mail::to('someone@b.test')->send(new MailServerTestMail());
        $from = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()[0]->getOriginalMessage()->getFrom()[0];
        $this->assertSame('hr@company.test', $from->getAddress());
        $this->assertSame('HR Portal', $from->getName());
    }

    /** @test */
    public function ssl_connects_with_tls_from_the_start_on_any_port(): void
    {
        $this->actingWithRole('super_admin');
        $this->putJson(self::URL, $this->payload(['encryption' => 'ssl', 'port' => 8465]))->assertOk();

        $this->freshProcess();
        $this->assertTrue($this->smtpTransport()->getStream()->isTLS());
    }

    /** @test */
    public function the_password_is_encrypted_and_never_returned_or_logged(): void
    {
        $this->actingWithRole('super_admin');
        $put = $this->putJson(self::URL, $this->payload())->assertOk();
        $get = $this->getJson(self::URL)->assertOk();

        $this->assertSame('s3cret pass', Crypt::decryptString($this->stored('password')));
        $this->assertStringNotContainsString('s3cret', $this->stored('password'));
        $this->assertStringNotContainsString('s3cret', $put->getContent() . $get->getContent());
        $this->assertStringNotContainsString('s3cret', DB::table('activity_log')->get()->toJson());
        $this->assertDatabaseHas('activity_log', ['description' => 'Outgoing mail server settings updated.']);
    }

    /** @test */
    public function a_blank_password_keeps_the_current_one_for_the_same_server_and_account(): void
    {
        $this->actingWithRole('super_admin');
        $this->putJson(self::URL, $this->payload())->assertOk();
        $this->putJson(self::URL, $this->payload(['password' => '', 'from_name' => 'New Name']))->assertOk();

        $this->assertSame('s3cret pass', Crypt::decryptString($this->stored('password')));
        $this->assertSame('New Name', $this->stored('from_name'));
    }

    /** @test */
    public function the_first_save_can_take_over_the_env_password_for_the_same_account(): void
    {
        $this->actingWithRole('super_admin');
        $this->putJson(self::URL, $this->payload(['host' => 'env.smtp.test', 'username' => 'env@hr.test', 'password' => null]))->assertOk();

        $this->assertSame('env-secret', Crypt::decryptString($this->stored('password')));
    }

    /** @test */
    public function the_current_password_is_not_reused_for_another_server_or_account(): void
    {
        $this->actingWithRole('super_admin');

        // .env credentials
        $this->putJson(self::URL, $this->payload(['username' => 'env@hr.test', 'password' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('password');

        // saved credentials
        $this->putJson(self::URL, $this->payload())->assertOk();
        foreach ([['host' => 'evil.test'], ['username' => 'other@company.test']] as $change) {
            $this->putJson(self::URL, $this->payload($change + ['password' => '']))
                ->assertStatus(422)->assertJsonValidationErrors('password');
        }
        $this->assertSame('smtp.office365.com', $this->stored('host'));
    }

    /** @test */
    public function a_server_without_login_needs_no_password(): void
    {
        $this->actingWithRole('super_admin');
        $this->putJson(self::URL, $this->payload(['encryption' => 'none', 'port' => 25, 'username' => '', 'password' => '']))
            ->assertOk()->assertJsonPath('settings.has_password', false);

        $this->freshProcess();
        $this->assertSame('', $this->smtpTransport()->getUsername());
        $this->assertSame('', $this->smtpTransport()->getPassword());
    }

    /** @test */
    public function invalid_settings_are_rejected(): void
    {
        $this->actingWithRole('super_admin');
        $bad = ['host' => 'http://smtp.test/', 'port' => 70000, 'encryption' => 'xyz', 'from_address' => 'nope', 'from_name' => ''];

        $this->putJson(self::URL, $this->payload($bad))->assertStatus(422)->assertJsonValidationErrors(array_keys($bad));
        $this->assertNull($this->stored('host'));
    }

    /** @test */
    public function a_password_saved_with_another_app_key_falls_back_to_env_and_is_reported(): void
    {
        $this->actingWithRole('super_admin');
        $this->putJson(self::URL, $this->payload())->assertOk();
        DB::table('system_settings')->where('key', 'mail_server_password')->update(['value' => 'not-decryptable']);

        $this->freshProcess();
        $this->assertSame('env.smtp.test', $this->smtpTransport()->getStream()->getHost());
        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('settings.password_unreadable', true)->assertJsonPath('settings.has_password', false);
        $this->putJson(self::URL, $this->payload(['password' => '']))->assertStatus(422);
    }

    /** @test */
    public function a_running_queue_worker_picks_up_a_change_before_its_next_job(): void
    {
        $this->assertSame('env.smtp.test', $this->smtpTransport()->getStream()->getHost());

        // Saved by another process (the web server).
        $rows = $this->payload(['password' => Crypt::encryptString('s3cret pass')]);
        foreach ($rows as $field => $value) {
            DB::table('system_settings')->insert(['key' => 'mail_server_' . $field, 'value' => (string) $value]);
        }
        $this->assertSame('env.smtp.test', $this->smtpTransport()->getStream()->getHost());

        event(new JobProcessing('database', $this->createMock(Job::class)));
        $this->assertSame('smtp.office365.com', $this->smtpTransport()->getStream()->getHost());
    }

    /** @test */
    public function mail_still_works_when_the_settings_cannot_be_read(): void
    {
        DB::shouldReceive('table')->with('system_settings')->andThrow(new \RuntimeException('Database is down'));

        $this->assertSame('env.smtp.test', $this->smtpTransport()->getStream()->getHost());
    }

    /** @test */
    public function the_test_email_goes_to_the_address_entered(): void
    {
        $this->actingWithRole('super_admin');
        $this->postJson(self::URL . '/test', ['to' => 'me@company.test'])->assertOk()
            ->assertJsonPath('message', 'Test email sent to me@company.test.');

        $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame('me@company.test', $messages[0]->getOriginalMessage()->getTo()[0]->getAddress());
    }

    /** @test */
    public function the_page_says_when_test_mode_redirects_all_mail(): void
    {
        config(['mail.test_email' => 'inbox@test.local', 'mail.force_test_email' => true]);

        $this->actingWithRole('super_admin');
        $this->getJson(self::URL)->assertOk()->assertJsonPath('settings.redirected_to', 'inbox@test.local');
        $this->postJson(self::URL . '/test', ['to' => 'me@company.test'])
            ->assertJsonPath('message', 'Test email sent to inbox@test.local because test mode is on.');
    }

    /** @test */
    public function a_failed_test_email_returns_the_reason(): void
    {
        $this->actingWithRole('super_admin');
        $this->putJson(self::URL, $this->payload(['host' => '127.0.0.1', 'port' => 1]))->assertOk();
        config(['mail.default' => 'smtp']);

        $response = $this->postJson(self::URL . '/test', ['to' => 'me@company.test'])->assertStatus(422);
        $this->assertStringContainsString('The test email could not be sent', $response->json('message'));
        $this->assertStringNotContainsString('s3cret', $response->getContent());
    }
}
