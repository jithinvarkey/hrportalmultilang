@php
  $isRtl = \App\Support\Locale\SupportedLocales::isRtl();
  $dir = \App\Support\Locale\SupportedLocales::dir();
  $align = $isRtl ? 'right' : 'left';
  $font = $isRtl ? "'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif" : 'Arial,sans-serif';
  $arFont = "'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif";
  $kind = $type === 'policy' ? 'policy' : 'announcement';
  $name = ($isRtl && filled($recipientNameAr ?? null)) ? $recipientNameAr : ($recipientName ?: __('mail_communications.employee'));
  // Generated summaries (e.g. "policy published") are rendered in the recipient's language; HR-authored bodies are shown as written.
  $summary = $messageKey
      ? __('mail_communications.' . $messageKey, array_merge(['title' => ($isRtl && filled($titleAr)) ? $titleAr : $title], $messageParams))
      : $body;
  // HR-authored content in both languages: the recipient's language comes first.
  $columns = [['dir' => 'ltr', 'lang' => 'en', 'label' => __('mail_communications.lang_en'), 'title' => $title, 'body' => $body, 'font' => 'Arial,sans-serif']];
  if ($titleAr || $bodyAr) {
      $columns[] = ['dir' => 'rtl', 'lang' => 'ar', 'label' => __('mail_communications.lang_ar'), 'title' => $titleAr, 'body' => $bodyAr, 'font' => $arFont];
      if ($isRtl) {
          $columns = array_reverse($columns);
      }
  }
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body dir="{{ $dir }}" style="margin:0;padding:0;background:#f0f4f8;font-family:{{ $font }};">
<table width="100%" cellpadding="0" cellspacing="0">
  <tr>
    <td align="center" style="padding:30px 10px">
      <table width="600" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1);text-align:{{ $align }}">
        <tr>
          <td style="background:#1e3a5f;padding:24px 32px">
            <p style="margin:0;color:#fff;font-size:20px;font-weight:bold">{{ config('app.name', 'HRMS') }}</p>
            <p style="margin:4px 0 0;color:#93c5fd;font-size:12px;letter-spacing:{{ $isRtl ? '0' : '1px' }};text-transform:uppercase">{{ __('mail.department') }}</p>
          </td>
        </tr>
        <tr>
          <td style="background:#facc15;padding:14px 32px">
            <p style="margin:0;color:#111827;font-size:15px;font-weight:bold;text-transform:uppercase;letter-spacing:{{ $isRtl ? '0' : '.5px' }}">
              {{ __('mail_communications.banner.' . $kind) }}
            </p>
          </td>
        </tr>
        <tr>
          <td style="padding:28px 32px">
            <p style="margin:0 0 16px;color:#1a1a2e;font-size:15px">
              {!! __('mail_communications.greeting', ['name' => e($name)]) !!}
            </p>
            <p style="color:#374151;font-size:14px;line-height:1.6;margin:0 0 16px">
              {{ __('mail_communications.intro.' . $kind) }}
            </p>
            <table width="100%" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="margin:20px 0;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
              @if($type === 'announcement')
              <tr>
                @foreach($columns as $column)
                  @php
                    $single = count($columns) === 1;
                    $border = (!$single && !$loop->first) ? 'border-' . ($isRtl ? 'right' : 'left') . ':1px solid #e5e7eb;' : '';
                  @endphp
                  <td width="{{ $single ? '100%' : '50%' }}" valign="top" dir="{{ $column['dir'] }}" lang="{{ $column['lang'] }}" style="padding:16px;{{ $border }}text-align:{{ $column['dir'] === 'rtl' ? 'right' : 'left' }};font-family:{{ $column['font'] }}">
                    <div style="font-size:11px;color:#6b7280;font-weight:bold;{{ $column['lang'] === 'en' ? 'text-transform:uppercase;' : '' }}margin-bottom:8px">{{ $column['label'] }}</div>
                    @if($column['title'])<div style="font-size:15px;color:#1a1a2e;font-weight:700;margin-bottom:8px">{{ $column['title'] }}</div>@endif
                    @if($column['body'])<div style="font-size:13px;color:#374151;line-height:1.6">{{ $column['body'] }}</div>@endif
                  </td>
                @endforeach
              </tr>
              @else
              <tr style="background:#f9fafb">
                <td style="padding:10px 16px;font-size:12px;color:#6b7280;font-weight:bold;text-transform:uppercase;letter-spacing:{{ $isRtl ? '0' : '.05em' }};width:30%">{{ __('mail_communications.title') }}</td>
                <td style="padding:10px 16px;font-size:13px;color:#1a1a2e;font-weight:600">
                  {{ ($isRtl && filled($titleAr)) ? $titleAr : $title }}
                  @if(filled($titleAr) && $titleAr !== $title)
                    <div dir="{{ $isRtl ? 'ltr' : 'rtl' }}" style="margin-top:4px;font-weight:400;color:#6b7280;font-family:{{ $isRtl ? 'Arial,sans-serif' : $arFont }}">{{ $isRtl ? $title : $titleAr }}</div>
                  @endif
                </td>
              </tr>
              @if($summary)
              <tr>
                <td style="padding:10px 16px;font-size:12px;color:#6b7280;font-weight:bold;text-transform:uppercase;letter-spacing:{{ $isRtl ? '0' : '.05em' }};border-top:1px solid #e5e7eb">{{ __('mail_communications.summary') }}</td>
                <td style="padding:10px 16px;font-size:13px;color:#374151;border-top:1px solid #e5e7eb;line-height:1.5">{{ $summary }}</td>
              </tr>
              @endif
              @endif
            </table>
            <p style="margin:24px 0 0">
              <a href="{{ $link }}" style="display:inline-block;background:#1e3a5f;color:#fff;text-decoration:none;font-size:14px;font-weight:bold;padding:11px 18px;border-radius:8px">
                {{ __('mail_communications.view.' . $kind) }}
              </a>
            </p>
          </td>
        </tr>
        <tr>
          <td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;text-align:center">
            <p style="margin:0;color:#9ca3af;font-size:11px">
              {{ __('mail_communications.auto_notice') }}
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
