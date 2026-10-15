<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>New Employee Onboarding Tasks</title></head>
<body style="margin:0;background:#f3f4f6;font-family:Arial,sans-serif;color:#1f2937">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f6;padding:24px">
  <tr><td align="center">
    <table role="presentation" width="620" cellspacing="0" cellpadding="0" style="max-width:620px;width:100%;background:#fff;border-radius:10px;overflow:hidden">
      <tr><td style="background:#1e3a5f;color:#fff;padding:24px 30px"><h1 style="margin:0;font-size:22px">New Employee Onboarding Tasks</h1></td></tr>
      <tr><td style="padding:30px;font-size:15px;line-height:1.6">
        <p style="margin:0 0 18px">Hello {{ $recipientGroup }} Team,</p>
        <p style="margin:0 0 18px">A new employee has been created. The following onboarding tasks require your attention.</p>
        <table role="presentation" width="100%" cellspacing="0" cellpadding="8" style="border-collapse:collapse;background:#f9fafb;margin-bottom:20px">
          <tr><td style="font-weight:bold;width:35%;border-bottom:1px solid #e5e7eb">Employee</td><td style="border-bottom:1px solid #e5e7eb">{{ $employee->full_name }}</td></tr>
          <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">Employee number</td><td style="border-bottom:1px solid #e5e7eb">{{ $employee->employee_code ?: '-' }}</td></tr>
          <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">Department</td><td style="border-bottom:1px solid #e5e7eb">{{ $employee->department?->name ?: '-' }}</td></tr>
          <tr><td style="font-weight:bold">Joining date</td><td>{{ $employee->hire_date?->format('d M Y') ?: '-' }}</td></tr>
        </table>
        <table role="presentation" width="100%" cellspacing="0" cellpadding="9" style="border-collapse:collapse">
          <tr style="background:#e5e7eb"><th align="left">Task</th><th align="left" width="120">Due date</th></tr>
          @foreach ($tasks as $task)
            <tr><td style="border-bottom:1px solid #e5e7eb">{{ $task->title }}</td><td style="border-bottom:1px solid #e5e7eb">{{ $task->due_date?->format('d M Y') ?: '-' }}</td></tr>
          @endforeach
        </table>
        <p style="margin:20px 0 0;color:#6b7280;font-size:13px">Please coordinate with HR and update the task status in the employee's Onboarding tab.</p>
      </td></tr>
      <tr><td style="background:#f9fafb;padding:16px 30px;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:11px;text-align:center">This is an automated onboarding notification.</td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
