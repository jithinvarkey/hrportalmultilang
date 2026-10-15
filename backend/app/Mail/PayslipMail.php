<?php
namespace App\Mail;
use App\Mail\Support\MailFormat;
use App\Models\Payslip;
use App\Services\PayrollService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;

class PayslipMail extends BaseMailable
{
    use Queueable;

    public function __construct(public Payslip $payslip) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail_payroll.payslip.subject', [
                'month' => MailFormat::month($this->payslip->payroll?->month),
                'company' => __('mail.company'),
            ])
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payslip');
    }

    public function attachments(): array
    {
        try {
            $service = app(PayrollService::class);
            $pdf     = $service->generatePayslipPdf($this->payslip);
            $pdfData = $pdf->output();
            return [
                Attachment::fromData(fn() => $pdfData, "Payslip_{$this->payslip->payroll?->month}.pdf")
                    ->withMime('application/pdf'),
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
