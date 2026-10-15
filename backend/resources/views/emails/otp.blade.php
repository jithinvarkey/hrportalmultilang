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
      <p style="margin:0 0 18px">{{ __('mail.greeting', ['name' => MailFormat::name($name, $nameAr ?? null)]) }}</p>
      <p style="margin:0 0 18px">{{ __('mail_auth.otp.intro', ['purpose' => __('mail_auth.otp.purpose.' . $purpose)]) }}</p>
      <p style="margin:0 0 18px;text-align:center">
        <span dir="ltr" style="display:inline-block;letter-spacing:8px;font-size:32px;font-weight:bold;color:#1e3a5f;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px 22px">{{ $otp }}</span>
      </p>
      <p style="margin:0">{{ __('mail_auth.otp.expires', ['minutes' => trans_choice('mail_auth.minutes', $expiresInMinutes, ['count' => $expiresInMinutes])]) }}</p>
    </td></tr>
    <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:11px;text-align:center">{{ __('mail_auth.otp.footer') }}</td></tr>
  </table>
</td></tr></table>
</body>
</html>
