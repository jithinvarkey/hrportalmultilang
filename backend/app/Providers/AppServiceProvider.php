<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Mail;

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
        // Local development never emails real people: every message (To, CC and BCC) goes to MAIL_TEST_EMAIL.
        if ((config('mail.force_test_email') || $this->app->environment('local')) && config('mail.test_email')) {
            Mail::alwaysTo(config('mail.test_email'));
        }
    }
}
