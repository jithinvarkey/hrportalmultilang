@php
  $isRtl = \App\Support\Locale\SupportedLocales::isRtl();
  $dir = \App\Support\Locale\SupportedLocales::dir();
  $font = $isRtl ? "'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif" : 'Arial,sans-serif';
  $L = \App\Support\Locale\LocalName::class;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head>
  <meta charset="UTF-8">
  <title>{{ __('mail_onboarding.it.title') }}</title>
</head>
<body dir="{{ $dir }}" style="margin:0;background:#f3f4f6;font-family:{{ $font }};color:#1f2937">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f6;padding:24px">
    <tr><td align="center">
      <table role="presentation" width="620" cellspacing="0" cellpadding="0" dir="{{ $dir }}" style="max-width:620px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;text-align:{{ $isRtl ? 'right' : 'left' }}">
        <tr><td style="background:#1e3a5f;color:#ffffff;padding:24px 30px">
          <h1 style="margin:0;font-size:22px">{{ __('mail_onboarding.it.title') }}</h1>
        </td></tr>
        <tr><td style="padding:30px;font-size:15px;line-height:1.6">
          <p style="margin:0 0 18px">{{ __('mail_onboarding.it.greeting') }}</p>
          <p style="margin:0 0 18px">{{ __('mail_onboarding.it.intro') }}</p>
          <table role="presentation" width="100%" cellspacing="0" cellpadding="8" dir="{{ $dir }}" style="border-collapse:collapse;background:#f9fafb">
            <tr><td style="font-weight:bold;width:38%;border-bottom:1px solid #e5e7eb">{{ __('mail_onboarding.employee') }}</td><td style="border-bottom:1px solid #e5e7eb">{{ $L::employee($employee) }}</td></tr>
            <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">{{ __('mail_onboarding.employee_number') }}</td><td style="border-bottom:1px solid #e5e7eb">{{ $employee->employee_code ?: '-' }}</td></tr>
            <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">{{ __('mail_onboarding.email') }}</td><td dir="ltr" style="border-bottom:1px solid #e5e7eb;text-align:{{ $isRtl ? 'right' : 'left' }}">{{ $employee->email ?: '-' }}</td></tr>
            <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">{{ __('mail_onboarding.job_title') }}</td><td style="border-bottom:1px solid #e5e7eb">{{ $L::designation($employee->designation, '-') }}</td></tr>
            <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">{{ __('mail.labels.department') }}</td><td style="border-bottom:1px solid #e5e7eb">{{ $L::department($employee->department, '-') }}</td></tr>
            <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">{{ __('mail_onboarding.manager') }}</td><td style="border-bottom:1px solid #e5e7eb">{{ $L::employee($employee->manager, '-') }}</td></tr>
            <tr><td style="font-weight:bold">{{ __('mail_onboarding.joining_date') }}</td><td>{{ $L::date($employee->hire_date) }}</td></tr>
          </table>
          <p style="margin:20px 0 0;color:#6b7280;font-size:13px">{{ __('mail_onboarding.it.auto_notice') }}</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
