@php
  use App\Mail\Support\MailFormat;

  $isRtl = \App\Support\Locale\SupportedLocales::isRtl();
  $dir = \App\Support\Locale\SupportedLocales::dir();
  $align = MailFormat::start();
  $employee = $payslip->employee;
  $monthText = MailFormat::month($payslip->payroll?->month);
  $labelStyle = 'padding:10px 16px;font-size:12px;color:#6b7280;font-weight:bold;text-transform:uppercase';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body dir="{{ $dir }}" style="margin:0;padding:0;background:#f0f4f8;font-family:{{ MailFormat::font('Arial,sans-serif') }};direction:{{ $dir }};text-align:{{ $align }};">
<table width="100%" cellpadding="0" cellspacing="0" dir="{{ $dir }}"><tr><td align="center" style="padding:30px 10px">
<table width="600" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1);text-align:{{ $align }}">

  <tr><td style="background:#1e3a5f;padding:24px 32px">
    <p style="margin:0;color:#fff;font-size:20px;font-weight:bold">{{ __('mail.company') }}</p>
    <p style="margin:4px 0 0;color:#93c5fd;font-size:12px;letter-spacing:{{ $isRtl ? '0' : '1px' }};text-transform:uppercase">{{ __('mail_payroll.payslip.department') }}</p>
  </td></tr>

  <tr><td style="background:#10b981;padding:14px 32px">
    <p style="margin:0;color:#fff;font-size:15px;font-weight:bold">{{ __('mail_payroll.payslip.banner', ['month' => $monthText]) }}</p>
  </td></tr>

  <tr><td style="padding:28px 32px">
    <p style="margin:0 0 16px;color:#1a1a2e;font-size:15px">
      {!! __('mail.greeting', ['name' => '<strong>' . e(MailFormat::name($employee?->first_name, $employee?->arabic_name)) . '</strong>']) !!}
    </p>
    <p style="color:#374151;font-size:14px;line-height:1.6;margin:0 0 20px">
      {!! __('mail_payroll.payslip.intro', ['month' => '<strong>' . e($monthText) . '</strong>']) !!}
    </p>

    <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-bottom:20px">
      <tr style="background:#f9fafb">
        <td style="{{ $labelStyle }};width:50%">{{ __('mail.labels.employee') }}</td>
        <td style="padding:10px 16px;font-size:13px;color:#1a1a2e;font-weight:600">{{ MailFormat::name(trim(($employee?->first_name ?? '') . ' ' . ($employee?->last_name ?? '')), $employee?->arabic_name) }}</td>
      </tr>
      <tr><td style="{{ $labelStyle }};border-top:1px solid #e5e7eb">{{ __('mail_payroll.payslip.net_salary') }}</td>
        <td style="padding:10px 16px;font-size:16px;color:#10b981;font-weight:bold;border-top:1px solid #e5e7eb">{{ MailFormat::money($payslip->net_salary) }}</td>
      </tr>
      <tr style="background:#f9fafb"><td style="{{ $labelStyle }};border-top:1px solid #e5e7eb">{{ __('mail_payroll.payslip.period') }}</td>
        <td style="padding:10px 16px;font-size:13px;color:#1a1a2e;border-top:1px solid #e5e7eb">{{ $monthText }}</td>
      </tr>
    </table>

    <p style="color:#6b7280;font-size:13px;margin:0">
      {!! __('mail_payroll.payslip.contact', ['email' => '<a href="mailto:diamond-hr@dbroker.com.sa" style="color:#1e3a5f">diamond-hr@dbroker.com.sa</a>']) !!}
    </p>
  </td></tr>

  <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;text-align:center">
    <p style="margin:0;color:#9ca3af;font-size:11px">{{ __('mail_payroll.payslip.footer', ['company' => __('mail.company')]) }}</p>
  </td></tr>
</table>
</td></tr></table>
</body>
</html>
