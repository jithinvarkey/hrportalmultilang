<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px">
  <table role="presentation" width="620" cellpadding="0" cellspacing="0" style="width:100%;max-width:620px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden">
    <tr><td style="background:#1e3a5f;padding:24px 32px;color:#fff">
      <div style="font-size:20px;font-weight:bold">Diamond Insurance Broker</div>
      <div style="margin-top:4px;color:#bfdbfe;font-size:12px;letter-spacing:1px;text-transform:uppercase">Recruitment Team</div>
    </td></tr>
    <tr><td style="padding:32px;color:#374151;font-size:15px;line-height:1.7">
      <p style="margin:0 0 18px">Dear HR Manager,</p>
      <p style="margin:0 0 18px">
        {{ $type === 'job'
            ? 'A new job has been posted and is ready for your review.'
            : 'A new application has been submitted and is ready for your review.' }}
      </p>

      <table role="presentation" width="100%" cellspacing="0" cellpadding="8" style="border-collapse:collapse;background:#f9fafb">
        @if ($type === 'job')
          <tr><td style="font-weight:bold;width:38%;border-bottom:1px solid #e5e7eb">Job title</td><td style="border-bottom:1px solid #e5e7eb">{{ $record->title }}</td></tr>
          <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">Department</td><td style="border-bottom:1px solid #e5e7eb">{{ $record->department?->name ?? 'Not specified' }}</td></tr>
          <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">Employment type</td><td style="border-bottom:1px solid #e5e7eb">{{ ucfirst(str_replace('_', ' ', $record->employment_type)) }}</td></tr>
          <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">Status</td><td style="border-bottom:1px solid #e5e7eb">{{ ucfirst(str_replace('_', ' ', $record->status)) }}</td></tr>
          <tr><td style="font-weight:bold">Vacancies</td><td>{{ $record->vacancies }}</td></tr>
        @else
          <tr><td style="font-weight:bold;width:38%;border-bottom:1px solid #e5e7eb">Applicant</td><td style="border-bottom:1px solid #e5e7eb">{{ $record->applicant_name }}</td></tr>
          <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">Added by</td><td style="border-bottom:1px solid #e5e7eb">{{ $addedBy?->name ?? 'Public applicant' }}@if($addedBy && $addedBy->roles->isNotEmpty()) ({{ $addedBy->roles->pluck('name')->map(fn ($role) => ucwords(str_replace('_', ' ', $role)))->join(', ') }})@endif</td></tr>
          <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">Email</td><td style="border-bottom:1px solid #e5e7eb">{{ $record->applicant_email }}</td></tr>
          <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">Phone</td><td style="border-bottom:1px solid #e5e7eb">{{ $record->applicant_phone ?? 'Not provided' }}</td></tr>
          <tr><td style="font-weight:bold;border-bottom:1px solid #e5e7eb">Job title</td><td style="border-bottom:1px solid #e5e7eb">{{ $record->jobPosting?->title ?? 'Not specified' }}</td></tr>
          <tr><td style="font-weight:bold">Department</td><td>{{ $record->jobPosting?->department?->name ?? 'Not specified' }}</td></tr>
        @endif
      </table>

      <p style="margin:20px 0 0">Please sign in to the HR portal to review the details.</p>
    </td></tr>
    <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;text-align:center">
      <p style="margin:0;color:#9ca3af;font-size:11px">This is an automated recruitment notification.</p>
    </td></tr>
  </table>
</td></tr></table>
</body>
</html>
