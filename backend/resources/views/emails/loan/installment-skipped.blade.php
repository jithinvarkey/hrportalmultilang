@php
    use App\Mail\Support\MailFormat;

    $isRtl = \App\Support\Locale\SupportedLocales::isRtl();
    $dir = \App\Support\Locale\SupportedLocales::dir();
    $align = MailFormat::start();
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body dir="{{ $dir }}" style="margin:0;padding:0;background:#f0f4f8;font-family:{{ MailFormat::font('Arial,sans-serif') }};direction:{{ $dir }};text-align:{{ $align }}">
<table width="100%" cellpadding="0" cellspacing="0" dir="{{ $dir }}"><tr><td align="center" style="padding:30px 10px">
  <table width="600" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="max-width:600px;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;text-align:{{ $align }}">
    <tr><td style="background:#1e3a5f;padding:24px 32px;color:#fff;font-size:20px;font-weight:bold">{{ $isRtl ? __('mail.company') : config('app.name', 'HRMS') }}</td></tr>
    <tr><td style="background:#f59e0b;padding:14px 32px;color:#fff;font-size:15px;font-weight:bold;text-transform:uppercase">{{ __('mail_loan.banner.installment_skipped') }}</td></tr>
    <tr><td style="padding:28px 32px;color:#374151;font-size:14px;line-height:1.6">
      <p style="margin:0 0 16px">{!! __('mail.greeting', ['name' => '<strong>' . e(MailFormat::name($recipientName, $recipientNameAr ?? null)) . '</strong>']) !!}</p>
      <p>{{ __('mail_loan.intro.installment_skipped') }}</p>

      <table width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
        <tr style="background:#f9fafb"><td style="padding:10px 16px;color:#6b7280;font-weight:bold;width:42%">{{ __('mail_loan.labels.loan_reference') }}</td><td style="padding:10px 16px;font-weight:600">{{ $loan->reference }}</td></tr>
        <tr><td style="padding:10px 16px;border-top:1px solid #e5e7eb;color:#6b7280;font-weight:bold">{{ __('mail.labels.employee') }}</td><td style="padding:10px 16px;border-top:1px solid #e5e7eb">{{ MailFormat::name($loan->employee?->full_name, $loan->employee?->arabic_name) }}</td></tr>
        <tr style="background:#f9fafb"><td style="padding:10px 16px;border-top:1px solid #e5e7eb;color:#6b7280;font-weight:bold">{{ __('mail_loan.labels.loan_type') }}</td><td style="padding:10px 16px;border-top:1px solid #e5e7eb">{{ MailFormat::localized($loan->loanType) }}</td></tr>
        <tr><td style="padding:10px 16px;border-top:1px solid #e5e7eb;color:#6b7280;font-weight:bold">{{ __('mail_loan.labels.skipped_installment') }}</td><td style="padding:10px 16px;border-top:1px solid #e5e7eb">{{ __('mail_loan.installment_due', ['number' => $skippedInstallment->installment_no, 'date' => MailFormat::date($skippedInstallment->due_date)]) }}</td></tr>
        <tr style="background:#f9fafb"><td style="padding:10px 16px;border-top:1px solid #e5e7eb;color:#6b7280;font-weight:bold">{{ __('mail.labels.amount') }}</td><td style="padding:10px 16px;border-top:1px solid #e5e7eb;font-weight:600">{{ MailFormat::money($skippedInstallment->amount) }}</td></tr>
        <tr><td style="padding:10px 16px;border-top:1px solid #e5e7eb;color:#6b7280;font-weight:bold">{{ __('mail_loan.labels.replacement_installment') }}</td><td style="padding:10px 16px;border-top:1px solid #e5e7eb">{{ __('mail_loan.installment_due', ['number' => $replacementInstallment->installment_no, 'date' => MailFormat::date($replacementInstallment->due_date)]) }}</td></tr>
        <tr style="background:#f9fafb"><td style="padding:10px 16px;border-top:1px solid #e5e7eb;color:#6b7280;font-weight:bold">{{ __('mail_loan.labels.notes') }}</td><td style="padding:10px 16px;border-top:1px solid #e5e7eb">{{ $skippedInstallment->notes ?: '—' }}</td></tr>
        <tr><td style="padding:10px 16px;border-top:1px solid #e5e7eb;color:#6b7280;font-weight:bold">{{ __('mail_loan.labels.skipped_by') }}</td><td style="padding:10px 16px;border-top:1px solid #e5e7eb">{{ __('mail_loan.performed_on', ['name' => $performedBy, 'date' => MailFormat::date(now(), 'd M Y H:i')]) }}</td></tr>
      </table>

      <p style="margin:24px 0 0;text-align:center"><a href="{{ $loanUrl }}" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:7px">{{ __('mail_loan.view_schedule') }}</a></p>
      <p style="margin:20px 0 0;color:#6b7280;font-size:13px">{!! __('mail_loan.questions', ['email' => '<a href="mailto:diamond-hr@dbroker.com.sa" style="color:#1e3a5f">diamond-hr@dbroker.com.sa</a>']) !!}</p>
    </td></tr>
    <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:11px;text-align:center">{{ __('mail.auto_notice') }}</td></tr>
  </table>
</td></tr></table>
</body>
</html>
