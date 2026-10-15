@php
  $isRtl = \App\Support\Locale\SupportedLocales::isRtl();
  $dir = \App\Support\Locale\SupportedLocales::dir();
  $font = $isRtl ? "'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif" : 'Arial,sans-serif';
  $backgroundImage = $backgroundImagePath && is_file($backgroundImagePath)
      ? $message->embed($backgroundImagePath)
      : null;
  // HR-authored wording in both languages, shown as written: the recipient's language comes first
  // (right-hand column in Arabic, left-hand column in English).
  $columns = [['dir' => 'ltr', 'lang' => 'en', 'text' => $mailBody, 'font' => 'Arial,sans-serif']];
  if ($mailBodyAr) {
      $columns[] = ['dir' => 'rtl', 'lang' => 'ar', 'text' => $mailBodyAr, 'font' => "'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif"];
      if ($isRtl) {
          $columns = array_reverse($columns);
      }
  }
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body dir="{{ $dir }}" style="margin:0;padding:0;background:#f3f4f6;font-family:{{ $font }}">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px">
  <table width="600" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="max-width:600px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;text-align:{{ $isRtl ? 'right' : 'left' }}">
    <tr><td style="background:#1e3a5f;padding:24px 32px;color:#fff;font-size:20px;font-weight:bold">{{ config('app.name', 'HRMS') }}</td></tr>
    @if($backgroundImage)
    <tr><td style="padding:0;line-height:0">
      <img src="{{ $backgroundImage }}" width="600" alt="{{ __('mail_people.birthday.image_alt') }}"
           style="display:block;width:100%;max-width:600px;height:auto;border:0">
    </td></tr>
    @else
    <tr><td style="padding:32px;color:#1f2937;font-size:15px;line-height:1.7">
      <table width="100%" cellpadding="0" cellspacing="0" dir="{{ $dir }}"><tr>
        @foreach($columns as $column)
          @php
            $single = count($columns) === 1;
            // padding/border towards the inner edge between the two columns
            $inner = $isRtl ? ($loop->first ? 'left' : 'right') : ($loop->first ? 'right' : 'left');
            $padding = $single ? 'padding:0' : 'padding-' . $inner . ':20px';
            $border = (!$single && !$loop->first) ? 'border-' . $inner . ':1px solid #e5e7eb;' : '';
          @endphp
          <td width="{{ $single ? '100%' : '50%' }}" valign="top" dir="{{ $column['dir'] }}" lang="{{ $column['lang'] }}" style="{{ $padding }};{{ $border }}text-align:{{ $column['dir'] === 'rtl' ? 'right' : 'left' }};font-family:{{ $column['font'] }}">{!! nl2br(e($column['text'])) !!}</td>
        @endforeach
      </tr></table>
    </td></tr>
    @endif
    <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:11px;text-align:center">{{ __('mail_people.sent_by_hr') }}</td></tr>
  </table>
</td></tr></table>
</body>
</html>
