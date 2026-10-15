<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\MonthlyLeaveReminderMail;
use App\Services\MonthlyLeaveReminderService;
use App\Support\Locale\RecipientLocale;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The monthly leave reminder has an English and an Arabic template; each employee gets the one for their
 * communication language. Uses a minimal in-memory schema (the full migrations do not run on SQLite).
 */
class MonthlyLeaveReminderLanguageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RecipientLocale::flush();
        Mail::fake();

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('arabic_name')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->default('active');
            $table->string('preferred_communication_language')->default('en');
            $table->softDeletes();
            $table->timestamps();
        });

        DB::table('employees')->insert([
            ['first_name' => 'Sam', 'last_name' => 'English', 'email' => 'en@test.local', 'preferred_communication_language' => 'en'],
            ['first_name' => 'Omar', 'last_name' => 'Arabic', 'email' => 'ar@test.local', 'preferred_communication_language' => 'ar'],
        ]);
    }

    public function test_settings_include_arabic_defaults(): void
    {
        $settings = app(MonthlyLeaveReminderService::class)->settings();

        $this->assertSame(trans('mail_leave.monthly_reminder.subject', [], 'ar'), $settings['subject_ar']);
        $this->assertSame(trans('mail_leave.monthly_reminder.body', [], 'ar'), $settings['body_ar']);
    }

    public function test_each_employee_gets_the_template_of_their_language(): void
    {
        $service = app(MonthlyLeaveReminderService::class);
        $service->updateSettings([
            'enabled' => true,
            'day' => 25,
            'subject' => 'Leave reminder for {{month_name}}',
            'body' => 'Hello {{first_name}}',
            'subject_ar' => 'تذكير الإجازات لشهر {{month_name}}',
            'body_ar' => 'مرحباً {{first_name}}',
        ]);

        $result = $service->sendForDate(Carbon::parse('2026-10-25'), true);

        $this->assertSame(2, $result['sent']);
        Mail::assertSent(MonthlyLeaveReminderMail::class, fn ($mail) => $mail->hasTo('en@test.local')
            && $mail->locale === 'en'
            && $mail->subjectTemplate === 'Leave reminder for {{month_name}}'
            && $mail->bodyTemplate === 'Hello {{first_name}}');
        Mail::assertSent(MonthlyLeaveReminderMail::class, fn ($mail) => $mail->hasTo('ar@test.local')
            && $mail->locale === 'ar'
            && $mail->subjectTemplate === 'تذكير الإجازات لشهر {{month_name}}'
            && $mail->bodyTemplate === 'مرحباً {{first_name}}');
    }

    public function test_default_arabic_wording_falls_back_to_the_translated_default(): void
    {
        // Settings never saved: both templates are the defaults, so the mail uses the translated default text.
        app(MonthlyLeaveReminderService::class)->sendForDate(Carbon::parse('2026-10-25'), true);

        Mail::assertSent(MonthlyLeaveReminderMail::class, fn ($mail) => $mail->hasTo('ar@test.local')
            && $mail->locale === 'ar' && $mail->subjectTemplate === null && $mail->bodyTemplate === null);
    }
}
