<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Listeners\RedirectMailToTestInbox;
use App\Services\MailServerSettingsService;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\MailManager;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider {

    /**
     * Register any application services.
     */
    public function register(): void {
        $this->app->singleton(MailServerSettingsService::class);

        // SMTP settings saved in Admin > Settings replace the MAIL_* values from .env. Read them only when mail is
        // actually used, not on every request.
        $this->app->afterResolving('mail.manager', function ($manager) {
            $this->app->make(MailServerSettingsService::class)->apply($manager instanceof MailManager ? $manager : null);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void {
        // Column changes (->change()) in migrations use Laravel's native MySQL support instead of the
        // doctrine/dbal package, which is not installed; without this a fresh `migrate` fails.
        Schema::useNativeSchemaOperationsIfPossible();

        // A queue worker keeps its mailers for hours: re-check the saved SMTP settings before each job.
        Event::listen(JobProcessing::class, function () {
            if ($this->app->resolved('mail.manager')) {
                $this->app->make(MailServerSettingsService::class)->apply();
            }
        });

        // Local development never emails real people: every message (To, CC and BCC) goes to MAIL_TEST_EMAIL,
        // with the real recipients shown in the subject ("[TEST → …]") so each copy can be told apart.
        $testInbox = $this->app->make(MailServerSettingsService::class)->redirectAddress();
        if ($testInbox !== null) {
            $redirect = new RedirectMailToTestInbox($testInbox);
            Event::listen(MessageSending::class, [$redirect, 'handle']);
        }
    }
}
