@php
    use App\Mail\Support\MailFormat;

    $isRtl = \App\Support\Locale\SupportedLocales::isRtl();
    $dir = \App\Support\Locale\SupportedLocales::dir();
    $align = MailFormat::start();
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body dir="{{ $dir }}" style="margin:0;padding:0;background:#f3f4f6;font-family:{{ MailFormat::font('Arial,sans-serif') }};direction:{{ $dir }};text-align:{{ $align }}">
<table width="100%" cellpadding="0" cellspacing="0" dir="{{ $dir }}"><tr><td align="center" style="padding:32px 12px">
  <table width="600" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="max-width:600px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;text-align:{{ $align }}">
    <tr><td style="background:#1e3a5f;padding:24px 32px;color:#fff;font-size:20px;font-weight:bold">{{ $isRtl ? __('mail.company') : config('app.name', 'HRMS') }}</td></tr>
    <tr><td style="padding:32px;color:#374151;font-size:15px;line-height:1.7">
      <p style="margin:0 0 18px">{{ __('mail.greeting', ['name' => MailFormat::name($employeeName, $employeeNameAr ?? null)]) }}</p>
      <p style="margin:0 0 18px">{!! __('mail_leave.contract_expiry.intro', ['date' => '<strong>' . e(MailFormat::date($contractExpiryDate)) . '</strong>']) !!}</p>
      <table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;background:#fff8e1;border:1px solid #f4c430;border-radius:6px">
        <tr><td style="padding:16px;color:#5f4700">
          {!! __('mail_leave.contract_expiry.balance', [
              'remaining' => '<strong>' . e(MailFormat::days(round($remainingDays, 1))) . '</strong>',
              'limit' => '<strong>' . e(MailFormat::days(round($carryForwardLimit))) . '</strong>',
          ]) !!}
        </td></tr>
      </table>
      <p style="margin:0">{{ __('mail_leave.contract_expiry.advice') }}</p>
      <p style="margin:24px 0 0;text-align:center"><a href="{{ $leaveUrl }}" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:7px">{{ __('mail_leave.contract_expiry.button') }}</a></p>
    </td></tr>
    <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:11px;text-align:center">{{ __('mail_leave.contract_expiry.footer') }}</td></tr>
  </table>
</td></tr></table>
</body>
</html>
