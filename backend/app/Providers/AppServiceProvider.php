<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Listeners\RedirectMailToTestInbox;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider {

    /**
     * Register any application services.
     */
    public function register(): void {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void {
        // Column changes (->change()) in migrations use Laravel's native MySQL support instead of the
        // doctrine/dbal package, which is not installed; without this a fresh `migrate` fails.
        Schema::useNativeSchemaOperationsIfPossible();

        // Local development never emails real people: every message (To, CC and BCC) goes to MAIL_TEST_EMAIL,
        // with the real recipients shown in the subject ("[TEST → …]") so each copy can be told apart.
        if ((config('mail.force_test_email') || $this->app->environment('local')) && config('mail.test_email')) {
            $redirect = new RedirectMailToTestInbox((string) config('mail.test_email'));
            Event::listen(MessageSending::class, [$redirect, 'handle']);
        }
    }
}
