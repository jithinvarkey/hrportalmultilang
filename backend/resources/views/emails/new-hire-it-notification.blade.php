<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>New Hire IT Setup</title>
</head>
<body style="margin:0;background:#f3f4f6;font-family:Arial,sans-serif;color:#1f2937">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f6;padding:24px">
    <tr><td align="center">
      <table role="presentation" width="620" cellspacing="0" cellpadding="0" style="max-width:620px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden">
        <tr><td style="background:#1e3a5f;color:#ffffff;padding:24px 30px">
          <h1 style="margin:0;font-size:22px">New Hire IT Setup</h1>
        </td></tr>
        <tr><td style="padding:30px;font-size:15px;line-height:1.6">
          <p style="margin:0 0 18px">Hello IT Manager,</p>
          <p style="margin:0 0 18px">A new employee has submitted their onboarding details. Please prepare the required IT accounts, access, and equipment.</p>
          <table role="presentation" width="100%" cellspacing="0" cellpadding="8" style="border-collapse:collapse;background:#f9fafb">
            <tr><td style="font-weight:bold;width:38%;border-bottom:1px solid #e5e7eb">Employee</td><td style="border-bottom:1px solid #e5e7eb">{{ $employee->full_name }}</td></tr>
            <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">Employee number</td><td style="border-bottom:1px solid #e5e7eb">{{ $employee->employee_code ?: '-' }}</td></tr>
            <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">Email</td><td style="border-bottom:1px solid #e5e7eb">{{ $employee->email ?: '-' }}</td></tr>
            <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">Job title</td><td style="border-bottom:1px solid #e5e7eb">{{ $employee->designation?->title ?: '-' }}</td></tr>
            <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">Department</td><td style="border-bottom:1px solid #e5e7eb">{{ $employee->department?->name ?: '-' }}</td></tr>
            <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">Manager</td><td style="border-bottom:1px solid #e5e7eb">{{ $employee->manager?->full_name ?: '-' }}</td></tr>
            <tr><td style="font-weight:bold">Joining date</td><td>{{ $employee->hire_date?->format('d M Y') ?: '-' }}</td></tr>
          </table>
          <p style="margin:20px 0 0;color:#6b7280;font-size:13px">This is an automated HR notification.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
