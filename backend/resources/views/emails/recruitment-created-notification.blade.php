@php
  $isRtl = \App\Support\Locale\SupportedLocales::isRtl();
  $dir = \App\Support\Locale\SupportedLocales::dir();
  $font = $isRtl ? "'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif" : 'Arial,sans-serif';
  $L = \App\Support\Locale\LocalName::class;
  $notSpecified = __('mail_recruitment.created.not_specified');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body dir="{{ $dir }}" style="margin:0;padding:0;background:#f3f4f6;font-family:{{ $font }}">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px">
  <table role="presentation" width="620" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="width:100%;max-width:620px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;text-align:{{ $isRtl ? 'right' : 'left' }}">
    <tr><td style="background:#1e3a5f;padding:24px 32px;color:#fff">
      <div style="font-size:20px;font-weight:bold">{{ __('mail.company') }}</div>
      <div style="margin-top:4px;color:#bfdbfe;font-size:12px;letter-spacing:{{ $isRtl ? '0' : '1px' }};text-transform:uppercase">{{ __('mail_recruitment.team') }}</div>
    </td></tr>
    <tr><td style="padding:32px;color:#374151;font-size:15px;line-height:1.7">
      <p style="margin:0 0 18px">{{ __('mail_recruitment.created.greeting') }}</p>
      <p style="margin:0 0 18px">
        {{ $type === 'job'
            ? __('mail_recruitment.created.intro_job')
            : __('mail_recruitment.created.intro_application') }}
      </p>

      <table role="presentation" width="100%" cellspacing="0" cellpadding="8" dir="{{ $dir }}" style="border-collapse:collapse;background:#f9fafb">
        @if ($type === 'job')
          <tr><td style="font-weight:bold;width:38%;border-bottom:1px solid #e5e7eb">{{ __('mail_recruitment.created.job_title') }}</td><td style="border-bottom:1px solid #e5e7eb">{{ $record->title }}</td></tr>
          <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">{{ __('mail_recruitment.created.department') }}</td><td style="border-bottom:1px solid #e5e7eb">{{ $L::department($record->department, $notSpecified) }}</td></tr>
          <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">{{ __('mail_recruitment.created.employment_type') }}</td><td style="border-bottom:1px solid #e5e7eb">{{ $L::option('mail_recruitment.employment_types', $record->employment_type, '') }}</td></tr>
          <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">{{ __('mail_recruitment.created.status') }}</td><td style="border-bottom:1px solid #e5e7eb">{{ \App\Support\Locale\StatusLabel::for($record->status) }}</td></tr>
          <tr><td style="font-weight:bold">{{ __('mail_recruitment.created.vacancies') }}</td><td>{{ $record->vacancies }}</td></tr>
        @else
          <tr><td style="font-weight:bold;width:38%;border-bottom:1px solid #e5e7eb">{{ __('mail_recruitment.created.applicant') }}</td><td style="border-bottom:1px solid #e5e7eb">{{ $record->applicant_name }}</td></tr>
          <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">{{ __('mail_recruitment.created.added_by') }}</td><td style="border-bottom:1px solid #e5e7eb">{{ $addedBy?->name ?? __('mail_recruitment.created.public_applicant') }}@if($addedBy && $addedBy->roles->isNotEmpty()) ({{ $addedBy->roles->pluck('name')->map(fn ($role) => $L::option('mail_recruitment.roles', $role))->join($isRtl ? '، ' : ', ') }})@endif</td></tr>
          <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">{{ __('mail_recruitment.created.email') }}</td><td dir="ltr" style="border-bottom:1px solid #e5e7eb;text-align:{{ $isRtl ? 'right' : 'left' }}">{{ $record->applicant_email }}</td></tr>
          <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">{{ __('mail_recruitment.created.phone') }}</td><td style="border-bottom:1px solid #e5e7eb">@if($record->applicant_phone)<span dir="ltr">{{ $record->applicant_phone }}</span>@else{{ __('mail_recruitment.created.not_provided') }}@endif</td></tr>
          <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">{{ __('mail_recruitment.created.job_title') }}</td><td style="border-bottom:1px solid #e5e7eb">{{ $record->jobPosting?->title ?? $notSpecified }}</td></tr>
          <tr><td style="font-weight:bold">{{ __('mail_recruitment.created.department') }}</td><td>{{ $L::department($record->jobPosting?->department, $notSpecified) }}</td></tr>
        @endif
      </table>

      <p style="margin:20px 0 0">{{ __('mail_recruitment.created.sign_in') }}</p>
    </td></tr>
    <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;text-align:center">
      <p style="margin:0;color:#9ca3af;font-size:11px">{{ __('mail_recruitment.auto_notice') }}</p>
    </td></tr>
  </table>
</td></tr></table>
</body>
</html>
