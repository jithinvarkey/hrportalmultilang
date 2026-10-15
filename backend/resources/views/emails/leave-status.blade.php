@php
  use App\Mail\Support\MailFormat;

  $isRtl = \App\Support\Locale\SupportedLocales::isRtl();
  $dir = \App\Support\Locale\SupportedLocales::dir();
  $align = MailFormat::start();
  $isHourlyLeave = (bool) ($leave->leaveType?->is_hourly ?? false)
      || in_array(strtoupper((string) ($leave->leaveType?->code ?? '')), ['BE', 'PE'], true);
  if ($isHourlyLeave) {
      $startTime = $leave->start_time ? \Carbon\Carbon::parse($leave->start_time)->format('H:i') : null;
      $endTime = $leave->end_time ? \Carbon\Carbon::parse($leave->end_time)->format('H:i') : null;
      $durationText = MailFormat::hours($leave->total_hours ?? 0)
          . ($startTime && $endTime ? " ({$startTime} – {$endTime})" : '');
  } else {
      $durationText = MailFormat::days($leave->total_days ?? 0);
  }
  $bannerText = in_array($action, ['approved', 'rejected'], true)
      ? __('mail_leave.simple.banner.' . $action)
      : __('mail_leave.simple.banner.default', ['status' => \App\Support\Locale\StatusLabel::for($action)]);
  $labelStyle = 'padding:10px 16px;font-size:12px;color:#6b7280;font-weight:bold;text-transform:uppercase;letter-spacing:' . ($isRtl ? '0' : '.05em');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body dir="{{ $dir }}" style="margin:0;padding:0;background:#f0f4f8;font-family:{{ MailFormat::font('Arial,sans-serif') }};direction:{{ $dir }};text-align:{{ $align }};">
<table width="100%" cellpadding="0" cellspacing="0" dir="{{ $dir }}"><tr><td align="center" style="padding:30px 10px">
<table width="600" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1);text-align:{{ $align }}">

  <!-- Header -->
  <tr><td style="background:#1e3a5f;padding:24px 32px">
    <p style="margin:0;color:#fff;font-size:20px;font-weight:bold">{{ __('mail.company') }}</p>
    <p style="margin:4px 0 0;color:#93c5fd;font-size:12px;letter-spacing:{{ $isRtl ? '0' : '1px' }};text-transform:uppercase">{{ __('mail.department') }}</p>
  </td></tr>

  <!-- Status banner -->
  <tr><td style="background:{{ $action==='approved' ? '#10b981' : ($action==='rejected' ? '#ef4444' : '#f59e0b') }};padding:14px 32px">
    <p style="margin:0;color:#fff;font-size:15px;font-weight:bold;text-transform:uppercase;letter-spacing:{{ $isRtl ? '0' : '.5px' }}">
      {{ $bannerText }}
    </p>
  </td></tr>

  <!-- Body -->
  <tr><td style="padding:28px 32px">
    <p style="margin:0 0 16px;color:#1a1a2e;font-size:15px">
      {!! __('mail.greeting', ['name' => '<strong>' . e(MailFormat::name($leave->employee?->first_name, $leave->employee?->arabic_name)) . '</strong>']) !!}
    </p>

    @if($action === 'approved')
    <p style="color:#374151;font-size:14px;line-height:1.6">
      {!! __('mail_leave.simple.approved', ['status' => '<strong style="color:#10b981">' . e(__('mail_leave.simple.word.approved')) . '</strong>']) !!}
    </p>
    @elseif($action === 'rejected')
    <p style="color:#374151;font-size:14px;line-height:1.6">
      {!! __('mail_leave.simple.rejected', ['status' => '<strong style="color:#ef4444">' . e(__('mail_leave.simple.word.rejected')) . '</strong>']) !!}
      @if($leave->rejection_reason)
        <br><strong>{{ __('mail.labels.reason') }}:</strong> {{ $leave->rejection_reason }}
      @endif
    </p>
    @else
    <p style="color:#374151;font-size:14px;line-height:1.6">
      {!! __('mail_leave.simple.submitted', ['status' => '<strong>' . e(__('mail_leave.simple.word.submitted')) . '</strong>']) !!}
    </p>
    @endif

    <!-- Details table -->
    <table width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
      <tr style="background:#f9fafb">
        <td style="{{ $labelStyle }};width:40%">{{ __('mail.labels.reference') }}</td>
        <td style="padding:10px 16px;font-size:13px;color:#1a1a2e;font-weight:600">{{ $leave->reference ?? '—' }}</td>
      </tr>
      <tr><td style="{{ $labelStyle }};border-top:1px solid #e5e7eb">{{ __('mail_leave.labels.leave_type') }}</td>
        <td style="padding:10px 16px;font-size:13px;color:#1a1a2e;border-top:1px solid #e5e7eb">{{ MailFormat::localized($leave->leaveType) ?: '—' }}</td>
      </tr>
      <tr style="background:#f9fafb"><td style="{{ $labelStyle }};border-top:1px solid #e5e7eb">{{ __('mail.labels.from') }}</td>
        <td style="padding:10px 16px;font-size:13px;color:#1a1a2e;border-top:1px solid #e5e7eb">{{ MailFormat::date($leave->start_date) }}</td>
      </tr>
      <tr><td style="{{ $labelStyle }};border-top:1px solid #e5e7eb">{{ __('mail.labels.to') }}</td>
        <td style="padding:10px 16px;font-size:13px;color:#1a1a2e;border-top:1px solid #e5e7eb">{{ MailFormat::date($leave->end_date) }}</td>
      </tr>
      <tr style="background:#f9fafb"><td style="{{ $labelStyle }};border-top:1px solid #e5e7eb">{{ __('mail.labels.duration') }}</td>
        <td style="padding:10px 16px;font-size:13px;color:#1a1a2e;font-weight:600;border-top:1px solid #e5e7eb">{{ $durationText }}</td>
      </tr>
    </table>

    <p style="margin:24px 0;text-align:center">
      <a href="{{ rtrim((string) config('app.frontend_url', config('app.url')), '/') }}/leave?request_id={{ $leave->id }}" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:7px">{{ __('mail_leave.view_request') }}</a>
    </p>

    <p style="color:#6b7280;font-size:13px;margin:0">
      {!! __('mail.contact_hr', ['email' => '<a href="mailto:diamond-hr@dbroker.com.sa" style="color:#1e3a5f">diamond-hr@dbroker.com.sa</a>']) !!}
    </p>
  </td></tr>

  <!-- Footer -->
  <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;text-align:center">
    <p style="margin:0;color:#9ca3af;font-size:11px">
      {{ __('mail.footer') }}
    </p>
  </td></tr>
</table>
</td></tr></table>
</body>
</html>
