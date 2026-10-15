<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif;color:#1f2937">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px">
  <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
    <tr><td style="background:#1e3a5f;padding:24px 32px;color:#fff;font-size:20px;font-weight:bold">Welcome Our New Colleague</td></tr>
    <tr><td style="padding:32px;font-size:15px;line-height:1.7">
      <p style="margin:0 0 18px">Dear {{ $recipientName }},</p>
      <p style="margin:0 0 18px">We are pleased to welcome <strong>{{ $newEmployee->full_name }}</strong>, who joins us today.</p>
      <table width="100%" cellpadding="8" cellspacing="0" style="background:#f9fafb;border-collapse:collapse">
        <tr><td style="width:35%;font-weight:bold;border-bottom:1px solid #e5e7eb">Job title</td><td style="border-bottom:1px solid #e5e7eb">{{ $newEmployee->designation?->title ?: '-' }}</td></tr>
        <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">Department</td><td style="border-bottom:1px solid #e5e7eb">{{ $newEmployee->department?->name ?: '-' }}</td></tr>
        <tr><td style="font-weight:bold">Joining date</td><td>{{ $newEmployee->hire_date?->format('d M Y') ?: '-' }}</td></tr>
      </table>
      <p style="margin:20px 0 0">Please join us in welcoming {{ $newEmployee->first_name }} and wishing them every success.</p>
    </td></tr>
    <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:11px;text-align:center">Sent by Human Resources</td></tr>
  </table>
</td></tr></table>
</body>
</html>
