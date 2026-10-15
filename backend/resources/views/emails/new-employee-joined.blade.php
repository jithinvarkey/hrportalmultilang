@php
  $isRtl = \App\Support\Locale\SupportedLocales::isRtl();
  $dir = \App\Support\Locale\SupportedLocales::dir();
  $font = $isRtl ? "'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif" : 'Arial,sans-serif';
  $L = \App\Support\Locale\LocalName::class;
  $name = $L::employee($newEmployee);
  $shortName = $isRtl && filled($newEmployee->arabic_name) ? $newEmployee->arabic_name : $newEmployee->first_name;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body dir="{{ $dir }}" style="margin:0;padding:0;background:#f3f4f6;font-family:{{ $font }};color:#1f2937">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px">
  <table width="600" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="max-width:600px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;text-align:{{ $isRtl ? 'right' : 'left' }}">
    <tr><td style="background:#1e3a5f;padding:24px 32px;color:#fff;font-size:20px;font-weight:bold">{{ __('mail_people.joined.title') }}</td></tr>
    <tr><td style="padding:32px;font-size:15px;line-height:1.7">
      <p style="margin:0 0 18px">{{ __('mail.greeting', ['name' => $recipientName ?: __('mail_people.joined.colleagues')]) }}</p>
      <p style="margin:0 0 18px">{!! __('mail_people.joined.intro', ['name' => e($name)]) !!}</p>
      <table width="100%" cellpadding="8" cellspacing="0" dir="{{ $dir }}" style="background:#f9fafb;border-collapse:collapse">
        <tr><td style="width:35%;font-weight:bold;border-bottom:1px solid #e5e7eb">{{ __('mail_people.joined.job_title') }}</td><td style="border-bottom:1px solid #e5e7eb">{{ $L::designation($newEmployee->designation, '-') }}</td></tr>
        <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">{{ __('mail_people.joined.department') }}</td><td style="border-bottom:1px solid #e5e7eb">{{ $L::department($newEmployee->department, '-') }}</td></tr>
        <tr><td style="font-weight:bold">{{ __('mail_people.joined.joining_date') }}</td><td>{{ $L::date($newEmployee->hire_date) }}</td></tr>
      </table>
      <p style="margin:20px 0 0">{{ __('mail_people.joined.closing', ['name' => $shortName]) }}</p>
    </td></tr>
    <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:11px;text-align:center">{{ __('mail_people.sent_by_hr') }}</td></tr>
  </table>
</td></tr></table>
</body>
</html>
