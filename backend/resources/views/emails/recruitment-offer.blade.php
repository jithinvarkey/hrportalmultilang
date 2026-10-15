@php
  $isRtl = \App\Support\Locale\SupportedLocales::isRtl();
  $dir = \App\Support\Locale\SupportedLocales::dir();
  $font = $isRtl ? "'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif" : 'Arial,sans-serif';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head><meta charset="UTF-8"></head>
<body dir="{{ $dir }}" style="margin:0;padding:0;background:#f3f4f6;font-family:{{ $font }}">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px">
  <table width="620" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="max-width:620px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;text-align:{{ $isRtl ? 'right' : 'left' }}">
    <tr><td style="background:#1e3a5f;padding:24px 32px;color:#fff;font-size:20px;font-weight:bold">{{ __('mail.company') }}</td></tr>
    <tr><td style="padding:32px;color:#374151;font-size:15px;line-height:1.7">
      <p style="margin:0 0 18px">{{ __('mail.greeting', ['name' => $application->applicant_name ?? __('mail_recruitment.candidate')]) }}</p>
      <p style="margin:0 0 18px">
        {{ __('mail_recruitment.offer.p1') }}
      </p>
      <p style="margin:0 0 18px">
        {{ __('mail_recruitment.offer.p2') }}
      </p>
      <p style="margin:0;color:#6b7280;font-size:13px">{{ __('mail_recruitment.auto_notice') }}</p>
    </td></tr>
  </table>
</td></tr></table>
</body>
</html>
