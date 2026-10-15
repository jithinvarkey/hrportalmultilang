@php
  $isRtl = \App\Support\Locale\SupportedLocales::isRtl();
  $dir = \App\Support\Locale\SupportedLocales::dir();
  $font = $isRtl ? "'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif" : 'Arial,sans-serif';
  $L = \App\Support\Locale\LocalName::class;
  $position = $interview->application?->jobPosting?->title;
  $format = $interview->format ?? 'in_person';
  $calloutBorder = $isRtl ? 'border-right:4px solid #3b82f6;border-radius:8px 0 0 8px' : 'border-left:4px solid #3b82f6;border-radius:0 8px 8px 0';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body dir="{{ $dir }}" style="margin:0;padding:0;background:#f0f4f8;font-family:{{ $font }};">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:30px 10px">
<table width="600" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1);text-align:{{ $isRtl ? 'right' : 'left' }}">

  <tr><td style="background:#1e3a5f;padding:24px 32px">
    <p style="margin:0;color:#fff;font-size:20px;font-weight:bold">{{ __('mail.company') }}</p>
    <p style="margin:4px 0 0;color:#93c5fd;font-size:12px;letter-spacing:{{ $isRtl ? '0' : '1px' }};text-transform:uppercase">{{ __('mail_recruitment.team') }}</p>
  </td></tr>

  <tr><td style="background:#3b82f6;padding:14px 32px">
    <p style="margin:0;color:#fff;font-size:15px;font-weight:bold;text-transform:uppercase;letter-spacing:{{ $isRtl ? '0' : '.5px' }}">
      {{ __('mail_recruitment.interview.banner') }}
    </p>
  </td></tr>

  <tr><td style="padding:28px 32px">
    <p style="margin:0 0 16px;color:#1a1a2e;font-size:15px">
      {!! __('mail.greeting', ['name' => '<strong>' . e($interview->application?->applicant_name ?? __('mail_recruitment.candidate')) . '</strong>']) !!}
    </p>
    <p style="color:#374151;font-size:14px;line-height:1.6;margin:0 0 20px">
      @if($position)
        {!! __('mail_recruitment.interview.intro', ['position' => e($position), 'company' => e(__('mail.company'))]) !!}
      @else
        {!! __('mail_recruitment.interview.intro_generic', ['company' => e(__('mail.company'))]) !!}
      @endif
    </p>

    <table width="100%" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-bottom:20px">
      <tr style="background:#f9fafb">
        <td style="padding:10px 16px;font-size:12px;color:#6b7280;font-weight:bold;text-transform:uppercase;width:35%">{{ __('mail.labels.date') }}</td>
        <td style="padding:10px 16px;font-size:13px;color:#1a1a2e;font-weight:600">
          {{ $L::date($interview->scheduled_at, $isRtl ? 'l d F Y' : 'l, d F Y') }}
        </td>
      </tr>
      <tr>
        <td style="padding:10px 16px;font-size:12px;color:#6b7280;font-weight:bold;text-transform:uppercase;border-top:1px solid #e5e7eb">{{ __('mail_recruitment.interview.time') }}</td>
        <td style="padding:10px 16px;font-size:13px;color:#1a1a2e;border-top:1px solid #e5e7eb">
          {{ $L::date($interview->scheduled_at, 'h:i A') }}
        </td>
      </tr>
      <tr style="background:#f9fafb">
        <td style="padding:10px 16px;font-size:12px;color:#6b7280;font-weight:bold;text-transform:uppercase;border-top:1px solid #e5e7eb">{{ __('mail.labels.type') }}</td>
        <td style="padding:10px 16px;font-size:13px;color:#1a1a2e;border-top:1px solid #e5e7eb">
          {{ $L::option('mail_recruitment.interview.formats', $format) }}
        </td>
      </tr>
      @if($interview->location_or_link)
      <tr>
        <td style="padding:10px 16px;font-size:12px;color:#6b7280;font-weight:bold;text-transform:uppercase;border-top:1px solid #e5e7eb">
          {{ $interview->format === 'in_person' ? __('mail_recruitment.interview.location') : __('mail_recruitment.interview.meeting_details') }}
        </td>
        <td style="padding:10px 16px;font-size:13px;color:#1a1a2e;border-top:1px solid #e5e7eb">
          @if(str_starts_with((string) $interview->location_or_link, 'http'))
            <a href="{{ $interview->location_or_link }}" dir="ltr" style="color:#3b82f6">{{ $interview->location_or_link }}</a>
            @if($interview->format === 'in_person')
              <br>
              <a href="{{ $interview->location_or_link }}" style="display:inline-block;margin-top:8px;padding:8px 12px;background:#3b82f6;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold">
                {{ __('mail_recruitment.interview.open_maps') }}
              </a>
            @endif
          @else
            {{ $interview->location_or_link }}
          @endif
        </td>
      </tr>
      @endif
      @if($interview->duration_minutes)
      <tr style="background:#f9fafb">
        <td style="padding:10px 16px;font-size:12px;color:#6b7280;font-weight:bold;text-transform:uppercase;border-top:1px solid #e5e7eb">{{ __('mail.labels.duration') }}</td>
        <td style="padding:10px 16px;font-size:13px;color:#1a1a2e;border-top:1px solid #e5e7eb">{{ __('mail_recruitment.interview.minutes', ['count' => $interview->duration_minutes]) }}</td>
      </tr>
      @endif
      @if(!empty($interview->interviewers))
      <tr>
        <td style="padding:10px 16px;font-size:12px;color:#6b7280;font-weight:bold;text-transform:uppercase;border-top:1px solid #e5e7eb">{{ __('mail_recruitment.interview.interviewers') }}</td>
        <td style="padding:10px 16px;font-size:13px;color:#1a1a2e;border-top:1px solid #e5e7eb">{{ implode($isRtl ? '، ' : ', ', (array) $interview->interviewers) }}</td>
      </tr>
      @endif
    </table>

    @if($interview->feedback)
    <div style="background:#eff6ff;{{ $calloutBorder }};padding:12px 16px;margin-bottom:20px">
      <p style="margin:0;font-size:13px;color:#374151"><strong>{{ __('mail_recruitment.interview.hr_notes') }}</strong> {{ $interview->feedback }}</p>
    </div>
    @endif

    <p style="color:#374151;font-size:14px;line-height:1.6;margin:0 0 16px">
      {{ __('mail_recruitment.interview.confirm') }}
    </p>
    <p style="color:#6b7280;font-size:13px;margin:0">
      {{ __('mail_recruitment.interview.contact_hr') }} <a href="mailto:diamond-hr@dbroker.com.sa" dir="ltr" style="color:#1e3a5f">diamond-hr@dbroker.com.sa</a>
    </p>
  </td></tr>

  <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;text-align:center">
    <p style="margin:0;color:#9ca3af;font-size:11px">{{ __('mail.company') }} &bull; {{ __('mail.location') }} &bull; {{ __('mail_recruitment.confidential') }}</p>
  </td></tr>
</table>
</td></tr></table>
</body>
</html>
