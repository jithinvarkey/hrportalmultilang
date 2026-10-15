@php
  use App\Mail\Support\MailFormat;

  $isRtl = \App\Support\Locale\SupportedLocales::isRtl();
  $dir = \App\Support\Locale\SupportedLocales::dir();
  $align = MailFormat::start();
  $statusLabel = \App\Support\Locale\StatusLabel::for($status);
  $statusColor = match ($status) {
    'completed' => '#10b981',
    'rejected', 'cancelled' => '#ef4444',
    'pending' => '#f59e0b',
    default => '#3b82f6',
  };
  $employeeLatinName = trim(($request->employee?->first_name ?? '') . ' ' . ($request->employee?->last_name ?? ''));
  $name = MailFormat::name($recipientName ?: $employeeLatinName, $recipientNameAr ?? null);
  $labelStyle = 'padding:10px 16px;font-size:12px;color:#6b7280;font-weight:bold;text-transform:uppercase';
  $displayNote = $displayNote ?? $note;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body dir="{{ $dir }}" style="margin:0;padding:0;background:#f0f4f8;font-family:{{ MailFormat::font('Arial,sans-serif') }};direction:{{ $dir }};text-align:{{ $align }};">
<table width="100%" cellpadding="0" cellspacing="0" dir="{{ $dir }}"><tr><td align="center" style="padding:30px 10px">
<table width="600" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1);text-align:{{ $align }}">

  <tr><td style="background:#1e3a5f;padding:24px 32px">
    <p style="margin:0;color:#fff;font-size:20px;font-weight:bold">{{ __('mail.company') }}</p>
    <p style="margin:4px 0 0;color:#93c5fd;font-size:12px;letter-spacing:{{ $isRtl ? '0' : '1px' }};text-transform:uppercase">{{ __('mail.department') }}</p>
  </td></tr>

  <tr><td style="background:{{ $statusColor }};padding:14px 32px">
    <p style="margin:0;color:#fff;font-size:15px;font-weight:bold;text-transform:uppercase;letter-spacing:{{ $isRtl ? '0' : '.5px' }}">
      {{ __('mail_request.banner', ['status' => $statusLabel]) }}
    </p>
  </td></tr>

  <tr><td style="padding:28px 32px">
    <p style="margin:0 0 16px;color:#1a1a2e;font-size:15px">
      @if($name !== '')
        {!! __('mail.greeting', ['name' => '<strong>' . e($name) . '</strong>']) !!}
      @else
        {{ __('mail.greeting_plain') }}
      @endif
    </p>

    <p style="color:#374151;font-size:14px;line-height:1.6;margin:0 0 20px">
      {!! __('mail_request.intro', [
          'reference' => '<strong>' . e($request->reference) . '</strong>',
          'status' => '<strong style="color:' . $statusColor . '">' . e($statusLabel) . '</strong>',
      ]) !!}
      @if($displayNote) <br>{{ $displayNote }} @endif
    </p>

    @if($status === 'completed' && $request->completion_notes)
      <p style="color:#374151;font-size:14px;line-height:1.6;margin:0 0 20px">
        <strong>{{ __('mail_request.labels.completion_notes') }}:</strong> {{ $request->completion_notes }}
      </p>
    @endif

    @if($status === 'rejected' && $request->rejection_reason)
      <p style="color:#374151;font-size:14px;line-height:1.6;margin:0 0 20px">
        <strong>{{ __('mail.labels.reason') }}:</strong> {{ $request->rejection_reason }}
      </p>
    @endif

    <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-bottom:20px">
      <tr style="background:#f9fafb">
        <td style="{{ $labelStyle }};width:38%">{{ __('mail.labels.reference') }}</td>
        <td style="padding:10px 16px;font-size:13px;color:#1a1a2e;font-weight:600">{{ $request->reference }}</td>
      </tr>
      <tr>
        <td style="{{ $labelStyle }};border-top:1px solid #e5e7eb">{{ __('mail_request.labels.request_type') }}</td>
        <td style="padding:10px 16px;font-size:13px;color:#1a1a2e;border-top:1px solid #e5e7eb">{{ MailFormat::localized($request->requestType) ?: '-' }}</td>
      </tr>
      <tr style="background:#f9fafb">
        <td style="{{ $labelStyle }};border-top:1px solid #e5e7eb">{{ __('mail_request.labels.requested_by') }}</td>
        <td style="padding:10px 16px;font-size:13px;color:#1a1a2e;border-top:1px solid #e5e7eb">{{ MailFormat::name($employeeLatinName, $request->employee?->arabic_name) }}</td>
      </tr>
      <tr>
        <td style="{{ $labelStyle }};border-top:1px solid #e5e7eb">{{ __('mail_request.labels.submitted') }}</td>
        <td style="padding:10px 16px;font-size:13px;color:#1a1a2e;border-top:1px solid #e5e7eb">{{ MailFormat::date($request->created_at) }}</td>
      </tr>
    </table>

    <p style="color:#6b7280;font-size:13px;margin:0">
      {!! __('mail_request.questions', ['email' => '<a href="mailto:diamond-hr@dbroker.com.sa" style="color:#1e3a5f">diamond-hr@dbroker.com.sa</a>']) !!}
    </p>
  </td></tr>

  <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;text-align:center">
    <p style="margin:0;color:#9ca3af;font-size:11px">{{ __('mail_request.footer', ['company' => __('mail.company'), 'location' => __('mail.location')]) }}</p>
  </td></tr>
</table>
</td></tr></table>
</body>
</html>
