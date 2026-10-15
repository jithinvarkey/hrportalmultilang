<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px">
  <table width="620" cellpadding="0" cellspacing="0" style="max-width:620px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
    <tr><td style="background:#1e3a5f;padding:24px 32px;color:#fff;font-size:20px;font-weight:bold">{{ config('app.name', 'HRMS') }}</td></tr>
    <tr><td style="padding:32px;color:#374151;font-size:15px;line-height:1.7">
      <p style="margin-top:0">Dear {{ $recipientName }},</p>
      @if($payrollStatus)
        <p>Payroll for <strong>{{ $month }}</strong> has been generated, but its current status is <strong>{{ ucwords(str_replace('_', ' ', $payrollStatus)) }}</strong>. It has not yet been marked as Paid.</p>
      @else
        <p>Payroll for <strong>{{ $month }}</strong> has not yet been generated.</p>
      @endif
      <p>Please review, complete the required payroll actions, and mark the payroll as Paid.</p>
      <p style="margin:24px 0 0;text-align:center"><a href="{{ $payrollUrl }}" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:7px">Open Payroll</a></p>
    </td></tr>
    <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:11px;text-align:center">Automated month-end payroll reminder</td></tr>
  </table>
</td></tr></table>
</body>
</html>
