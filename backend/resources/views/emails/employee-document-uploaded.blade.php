@php
  $isRtl = \App\Support\Locale\SupportedLocales::isRtl();
  $dir = \App\Support\Locale\SupportedLocales::dir();
  $font = $isRtl ? "'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif" : 'Arial,sans-serif';
  $L = \App\Support\Locale\LocalName::class;
  $documentType = $L::option('mail_onboarding.document.types', $document->type, '');
  $employeeName = $L::employee($employee);
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body dir="{{ $dir }}" style="margin:0;padding:0;background:#f3f4f6;font-family:{{ $font }}">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px">
  <table width="620" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="max-width:620px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;text-align:{{ $isRtl ? 'right' : 'left' }}">
    <tr><td style="background:#1e3a5f;padding:24px 32px;color:#fff;font-size:20px;font-weight:bold">{{ config('app.name', 'HRMS') }}</td></tr>
    <tr><td style="padding:32px;color:#374151;font-size:15px;line-height:1.7">
      <p style="margin:0 0 18px">{{ __('mail_onboarding.document.greeting') }}</p>
      <p style="margin:0 0 18px">
        {!! __('mail_onboarding.document.intro', ['name' => e($employeeName ?? __('mail_onboarding.document.an_employee'))]) !!}
      </p>
      <table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;border:1px solid #e5e7eb;border-radius:6px">
        <tr><td style="padding:14px 16px;background:#f9fafb;color:#111827;font-weight:bold">{{ __('mail_onboarding.document.details') }}</td></tr>
        <tr><td style="padding:14px 16px">
          <div><strong>{{ __('mail.labels.employee') }}:</strong> {{ $employeeName ?? '-' }}</div>
          <div><strong>{{ __('mail_onboarding.employee_code') }}:</strong> {{ $employee->employee_code ?? '-' }}</div>
          <div><strong>{{ __('mail_onboarding.document.document_title') }}:</strong> {{ $document->title }}</div>
          <div><strong>{{ __('mail_onboarding.document.document_type') }}:</strong> {{ $documentType ?: '-' }}</div>
          <div><strong>{{ __('mail_onboarding.document.file_name') }}:</strong> <span dir="ltr">{{ $document->file_name ?? '-' }}</span></div>
          <div><strong>{{ __('mail_onboarding.document.uploaded_at') }}:</strong> {{ $L::date($document->created_at ?? now(), 'd M Y h:i A') }}</div>
        </td></tr>
      </table>
      <p style="margin:0">{{ __('mail_onboarding.document.closing') }}</p>
    </td></tr>
    <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:11px;text-align:center">{{ __('mail_onboarding.document.auto_notice') }}</td></tr>
  </table>
</td></tr></table>
</body>
</html>
