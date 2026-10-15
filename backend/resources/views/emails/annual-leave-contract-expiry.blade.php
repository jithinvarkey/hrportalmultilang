<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px">
  <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
    <tr><td style="background:#1e3a5f;padding:24px 32px;color:#fff;font-size:20px;font-weight:bold">{{ config('app.name', 'HRMS') }}</td></tr>
    <tr><td style="padding:32px;color:#374151;font-size:15px;line-height:1.7">
      <p style="margin:0 0 18px">Dear {{ $employeeName }},</p>
      <p style="margin:0 0 18px">Your current employment contract will expire on <strong>{{ $contractExpiryDate }}</strong>.</p>
      <table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;background:#fff8e1;border:1px solid #f4c430;border-radius:6px">
        <tr><td style="padding:16px;color:#5f4700">
          Your remaining annual leave balance is <strong>{{ number_format($remainingDays, 1) }} days</strong>. A maximum of <strong>{{ number_format($carryForwardLimit, 0) }} days</strong> can be carried forward to your next contract.
        </td></tr>
      </table>
      <p style="margin:0">Please plan any leave above the carry-forward limit before your current contract expires and coordinate with your direct manager and Human Resources.</p>
      <p style="margin:24px 0 0;text-align:center"><a href="{{ $leaveUrl }}" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:7px">View Leave Balance</a></p>
    </td></tr>
    <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:11px;text-align:center">This is an automated annual leave reminder sent 90 days before contract expiry.</td></tr>
  </table>
</td></tr></table>
</body>
</html>
