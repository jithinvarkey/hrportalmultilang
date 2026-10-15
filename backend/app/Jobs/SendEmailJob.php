<?php

namespace App\Jobs;

use Throwable;
use App\Mail\GenericNotificationMail;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendEmailJob implements ShouldQueue {

    use Dispatchable,
        InteractsWithQueue,
        Queueable,
        SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    protected array $mailDetails;

    public function __construct(array $mailDetails) {
        $this->mailDetails = $mailDetails;
    }

    public function handle(): void {
        $emailSendId = 0;

        try {

            $emailDetails = [
                'to_address' => is_array($this->mailDetails['to']) ? implode(',', $this->mailDetails['to']) : $this->mailDetails['to'],
                'to_name' => $this->mailDetails['name'] ?? '',
                'subject_text' => $this->mailDetails['subject'],
                'data_text' => json_encode(
                        $this->mailDetails['data'] ?? []
                ),
                'content_text' => $this->mailDetails['template'],
                'created_date' => now(),
                'updated_date' => now(),
                'created_by' => auth()->id() ?? 0,
                'status' => 0,
            ];

            $emailSendId = DB::table('email_table')
                    ->insertGetId($emailDetails);

            $mail = new GenericNotificationMail(
                    $this->mailDetails
            );

            $mailer = Mail::to(
                            $this->mailDetails['to'],
                            $this->mailDetails['name'] ?? ''
            );

            if (!empty($this->mailDetails['cc_data'])) {
                $mailer->cc(
                        $this->mailDetails['cc_data']
                );
            }

            $mailer->send($mail);

            DB::table('email_table')
                    ->where('id', $emailSendId)
                    ->delete();
        } catch (Throwable $e) {

            if ($emailSendId > 0) {

                DB::table('email_table')
                        ->where('id', $emailSendId)
                        ->update([
                            'status' => 1,
                            'error_text' => $e->getMessage(),
                ]);
            }

            Log::error(
                    'SendEmailJob Failed',
                    [
                        'message' => $e->getMessage(),
                        'to' => $this->mailDetails['to'] ?? '',
                        'subject' => $this->mailDetails['subject'] ?? '',
                    ]
            );

            throw $e;
        }
    }

    public function failed(Throwable $e): void {
        Log::error(
                'SendEmailJob permanently failed',
                [
                    'message' => $e->getMessage(),
                    'to' => $this->mailDetails['to'] ?? '',
                    'subject' => $this->mailDetails['subject'] ?? '',
                ]
        );
    }
}
