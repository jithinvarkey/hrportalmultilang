@php
  $isRtl = \App\Support\Locale\SupportedLocales::isRtl();
  $dir = \App\Support\Locale\SupportedLocales::dir();
  $font = $isRtl ? "'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif" : 'Arial,sans-serif';
  $L = \App\Support\Locale\LocalName::class;
  $greetName = ($isRtl && filled($employee->arabic_name)) ? $employee->arabic_name : ($employee->first_name ?? __('mail_onboarding.employee'));
  $hasHdf = collect($attachmentsMeta)->contains(fn ($attachment) => str_starts_with(strtoupper($attachment['name'] ?? ''), 'HDF.'));
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body dir="{{ $dir }}" style="margin:0;padding:0;background:#f3f4f6;font-family:{{ $font }}">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px">
  <table width="620" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="max-width:620px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;text-align:{{ $isRtl ? 'right' : 'left' }}">
    <tr><td style="background:#1e3a5f;padding:24px 32px;color:#fff;font-size:20px;font-weight:bold">{{ config('app.name', 'HRMS') }}</td></tr>
    <tr><td style="padding:32px;color:#374151;font-size:15px;line-height:1.7">
      <p style="margin:0 0 18px">{{ __('mail.greeting', ['name' => $greetName]) }}</p>
      <p style="margin:0 0 18px">
        {{ __('mail_onboarding.link.welcome') }}
      </p>
      <p style="margin:0 0 24px;text-align:center">
        <a href="{{ $onboardingUrl }}" style="display:inline-block;background:#facc15;color:#111827;text-decoration:none;font-weight:bold;padding:13px 22px;border-radius:6px">
          {{ __('mail_onboarding.link.button') }}
        </a>
      </p>
      <p style="margin:0 0 18px">
        {{ __('mail_onboarding.link.form_fields') }}
      </p>
      <p style="margin:0 0 18px">
        {{ __('mail_onboarding.link.attachments') }}@if($hasHdf) {{ __('mail_onboarding.link.hdf') }}@endif {{ __('mail_onboarding.link.uploads') }}
      </p>
      @if($loginEmail)
        <table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;border:1px solid #e5e7eb;border-radius:6px">
          <tr><td style="padding:14px 16px;background:#f9fafb;color:#111827;font-weight:bold">{{ __('mail_onboarding.link.login_details') }}</td></tr>
          <tr><td style="padding:14px 16px">
            <div><strong>{{ __('mail_onboarding.email') }}:</strong> <span dir="ltr">{{ $loginEmail }}</span></div>
            @if($tempPassword)<div><strong>{{ __('mail_onboarding.link.temp_password') }}:</strong> <span dir="ltr">{{ $tempPassword }}</span></div>@endif
          </td></tr>
        </table>
      @endif
      @if($expiresAt)
        <p style="margin:0 0 18px;color:#6b7280">{{ __('mail_onboarding.link.valid_until', ['date' => $L::date($expiresAt, 'd M Y h:i A', $expiresAt)]) }}</p>
      @endif
      <p style="margin:0;color:#6b7280;font-size:13px">{{ __('mail_onboarding.link.button_fallback') }}<br><span dir="ltr">{{ $onboardingUrl }}</span></p>
    </td></tr>
    <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:11px;text-align:center">{{ __('mail_onboarding.auto_notice') }}</td></tr>
  </table>
</td></tr></table>
</body>
</html>
