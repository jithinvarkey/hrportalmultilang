@php
    use App\Mail\Support\MailFormat;

    $isRtl = \App\Support\Locale\SupportedLocales::isRtl();
    $dir = \App\Support\Locale\SupportedLocales::dir();
    $align = MailFormat::start();
    $approved = $action === 'approved';
    $rejected = $action === 'rejected';
    $statusColor = $approved ? '#10b981' : ($rejected ? '#ef4444' : '#2563eb');
    $statusKey = $approved ? 'approved' : ($rejected ? 'rejected' : 'submitted');
    $statusText = __('mail_loan.banner.' . $statusKey);
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body dir="{{ $dir }}" style="margin:0;padding:0;background:#f0f4f8;font-family:{{ MailFormat::font('Arial,sans-serif') }};direction:{{ $dir }};text-align:{{ $align }}">
<table width="100%" cellpadding="0" cellspacing="0" dir="{{ $dir }}"><tr><td align="center" style="padding:30px 10px">
  <table width="600" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="max-width:600px;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;text-align:{{ $align }}">
    <tr><td style="background:#1e3a5f;padding:24px 32px;color:#fff;font-size:20px;font-weight:bold">{{ $isRtl ? __('mail.company') : config('app.name', 'HRMS') }}</td></tr>
    <tr><td style="background:{{ $statusColor }};padding:14px 32px;color:#fff;font-size:15px;font-weight:bold;text-transform:uppercase">{{ $statusText }}</td></tr>
    <tr><td style="padding:28px 32px;color:#374151;font-size:14px;line-height:1.6">
      <p style="margin:0 0 16px">{!! __('mail.greeting', ['name' => '<strong>' . e(MailFormat::name($recipientName, $recipientNameAr ?? null)) . '</strong>']) !!}</p>
      @if($approved)
        <p>{{ __('mail_loan.intro.approved') }}</p>
      @elseif($rejected)
        <p>{{ __('mail_loan.intro.rejected') }}@if($loan->rejection_reason) <strong>{{ __('mail.labels.reason') }}:</strong> {{ $loan->rejection_reason }}@endif</p>
      @else
        <p>{{ __('mail_loan.intro.submitted') }}</p>
      @endif

      <table width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
        <tr style="background:#f9fafb"><td style="padding:10px 16px;color:#6b7280;font-weight:bold;width:40%">{{ __('mail.labels.reference') }}</td><td style="padding:10px 16px;font-weight:600">{{ $loan->reference }}</td></tr>
        <tr><td style="padding:10px 16px;border-top:1px solid #e5e7eb;color:#6b7280;font-weight:bold">{{ __('mail.labels.employee') }}</td><td style="padding:10px 16px;border-top:1px solid #e5e7eb">{{ MailFormat::name($loan->employee?->full_name, $loan->employee?->arabic_name) }}</td></tr>
        <tr style="background:#f9fafb"><td style="padding:10px 16px;border-top:1px solid #e5e7eb;color:#6b7280;font-weight:bold">{{ __('mail_loan.labels.loan_type') }}</td><td style="padding:10px 16px;border-top:1px solid #e5e7eb">{{ MailFormat::localized($loan->loanType) }}</td></tr>
        <tr><td style="padding:10px 16px;border-top:1px solid #e5e7eb;color:#6b7280;font-weight:bold">{{ __('mail_loan.labels.requested_amount') }}</td><td style="padding:10px 16px;border-top:1px solid #e5e7eb;font-weight:600">{{ MailFormat::money($loan->requested_amount) }}</td></tr>
        <tr style="background:#f9fafb"><td style="padding:10px 16px;border-top:1px solid #e5e7eb;color:#6b7280;font-weight:bold">{{ __('mail.labels.status') }}</td><td style="padding:10px 16px;border-top:1px solid #e5e7eb">{{ \App\Support\Locale\StatusLabel::for($loan->status) }}</td></tr>
      </table>

      <p style="margin:24px 0 0;text-align:center"><a href="{{ $requestUrl }}" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:7px">{{ __('mail_loan.view_request') }}</a></p>
      <p style="margin:20px 0 0;color:#6b7280;font-size:13px">{!! __('mail_loan.questions', ['email' => '<a href="mailto:diamond-hr@dbroker.com.sa" style="color:#1e3a5f">diamond-hr@dbroker.com.sa</a>']) !!}</p>
    </td></tr>
    <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:11px;text-align:center">{{ __('mail.auto_notice') }}</td></tr>
  </table>
</td></tr></table>
</body>
</html>
