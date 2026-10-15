@php
  $isRtl = \App\Support\Locale\SupportedLocales::isRtl();
  $align = $isRtl ? 'right' : 'left';
  $font = $isRtl ? "'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif" : 'Arial,sans-serif';
  $position = $application->jobPosting?->title;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ \App\Support\Locale\SupportedLocales::dir() }}">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body dir="{{ \App\Support\Locale\SupportedLocales::dir() }}" style="margin:0;padding:0;background:#f3f4f6;font-family:{{ $font }}">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px">
  <table width="620" cellpadding="0" cellspacing="0" dir="{{ \App\Support\Locale\SupportedLocales::dir() }}" style="max-width:620px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;text-align:{{ $align }}">
    <tr><td style="background:#1e3a5f;padding:24px 32px;color:#fff">
      <div style="font-size:20px;font-weight:bold">{{ __('mail.company') }}</div>
      <div style="margin-top:4px;color:#bfdbfe;font-size:12px;letter-spacing:{{ $isRtl ? '0' : '1px' }};text-transform:uppercase">{{ __('mail_recruitment.team') }}</div>
    </td></tr>
    <tr><td style="padding:32px;color:#374151;font-size:15px;line-height:1.7">
      <p style="margin:0 0 18px">{{ __('mail.greeting', ['name' => $application->applicant_name ?: __('mail_recruitment.candidate')]) }}</p>
      <p style="margin:0 0 18px">
        @if($position)
          {!! __('mail_recruitment.rejected.p1', ['position' => e($position)]) !!}
        @else
          {!! __('mail_recruitment.rejected.p1_generic') !!}
        @endif
      </p>
      <p style="margin:0 0 18px">{{ __('mail_recruitment.rejected.p2') }}</p>
      <p style="margin:0 0 18px">{{ __('mail_recruitment.rejected.p3') }}</p>
      <p style="margin:24px 0 0">{{ __('mail.regards') }}<br><strong>{{ __('mail_recruitment.team') }}</strong><br>{{ __('mail.company') }}</p>
    </td></tr>
    <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;text-align:center">
      <p style="margin:0;color:#9ca3af;font-size:11px">{{ __('mail_recruitment.auto_notice') }}</p>
    </td></tr>
  </table>
</td></tr></table>
</body>
</html>
