<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif">
@php
  $backgroundImage = $backgroundImagePath && is_file($backgroundImagePath)
      ? $message->embed($backgroundImagePath)
      : null;
@endphp
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px">
  <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
    <tr><td style="background:#1e3a5f;padding:24px 32px;color:#fff;font-size:20px;font-weight:bold">{{ config('app.name', 'HRMS') }}</td></tr>
    @if($backgroundImage)
    <tr><td style="padding:0;line-height:0">
      <img src="{{ $backgroundImage }}" width="600" alt="Birthday message"
           style="display:block;width:100%;max-width:600px;height:auto;border:0">
    </td></tr>
    @else
    <tr><td style="padding:32px;color:#1f2937;font-size:15px;line-height:1.7">
      <table width="100%" cellpadding="0" cellspacing="0"><tr>
        <td width="{{ $mailBodyAr ? '50%' : '100%' }}" valign="top" dir="ltr" style="padding:0 {{ $mailBodyAr ? '20px' : '0' }} 0 0;text-align:left">{!! nl2br(e($mailBody)) !!}</td>
        @if($mailBodyAr)
        <td width="50%" valign="top" dir="rtl" lang="ar" style="padding:0 0 0 20px;border-left:1px solid #e5e7eb;text-align:right;font-family:Arial,Tahoma,sans-serif">{!! nl2br(e($mailBodyAr)) !!}</td>
        @endif
      </tr></table>
    </td></tr>
    @endif
    <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:11px;text-align:center">Sent by Human Resources</td></tr>
  </table>
</td></tr></table>
</body>
</html>
