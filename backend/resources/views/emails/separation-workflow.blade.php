<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:Arial,sans-serif;color:#1f2937">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:30px 10px">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1)">
  <tr><td style="background:#1e3a5f;padding:24px 32px">
    <div style="color:#fff;font-size:20px;font-weight:bold">Diamond Insurance Broker</div>
    <div style="margin-top:4px;color:#93c5fd;font-size:12px;letter-spacing:1px;text-transform:uppercase">Human Resources</div>
  </td></tr>
  <tr><td style="background:#2563eb;padding:13px 32px;color:#fff;font-size:14px;font-weight:bold;text-transform:uppercase">
    @if($event === 'submitted') New Separation Request
    @elseif($event === 'manager_approved') Manager Approval Completed
    @elseif($event === 'hr_approved') HR Approval Completed
    @elseif($event === 'offboarding_tasks') Offboarding Tasks Assigned
    @else Department Offboarding Tasks Completed
    @endif
  </td></tr>
  <tr><td style="padding:28px 32px">
    <p style="margin:0 0 16px">Dear <strong>{{ $recipientName }}</strong>,</p>
    @if($event === 'submitted')
      <p style="font-size:14px;line-height:1.6">A new separation request has been submitted and requires review.</p>
    @elseif($event === 'manager_approved')
      <p style="font-size:14px;line-height:1.6">The direct manager has approved this separation request. HR approval is now required.</p>
    @elseif($event === 'hr_approved')
      <p style="font-size:14px;line-height:1.6">HR has approved this resignation request. Direct manager approval is now required.</p>
    @elseif($event === 'offboarding_tasks')
      <p style="font-size:14px;line-height:1.6">Offboarding has started. Please complete the following <strong>{{ ucfirst($taskCategory ?? '') }}</strong> tasks:</p>
    @else
      <p style="font-size:14px;line-height:1.6">
        All <strong>{{ ucfirst($taskCategory ?? '') }}</strong> offboarding tasks have been resolved
        @if($completedByName) by <strong>{{ $completedByName }}</strong>@endif.
        HR may now review this department's checklist completion.
      </p>
    @endif

    <table width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
      <tr style="background:#f9fafb"><td style="padding:10px 14px;font-size:12px;color:#6b7280;font-weight:bold;width:38%">Reference</td><td style="padding:10px 14px;font-size:13px;font-weight:600">{{ $separation->reference }}</td></tr>
      <tr><td style="padding:10px 14px;font-size:12px;color:#6b7280;font-weight:bold;border-top:1px solid #e5e7eb">Employee</td><td style="padding:10px 14px;font-size:13px;border-top:1px solid #e5e7eb">{{ $separation->employee?->full_name }}</td></tr>
      <tr style="background:#f9fafb"><td style="padding:10px 14px;font-size:12px;color:#6b7280;font-weight:bold;border-top:1px solid #e5e7eb">Type</td><td style="padding:10px 14px;font-size:13px;border-top:1px solid #e5e7eb">{{ ucwords(str_replace('_', ' ', $separation->type)) }}</td></tr>
      <tr><td style="padding:10px 14px;font-size:12px;color:#6b7280;font-weight:bold;border-top:1px solid #e5e7eb">Last Working Day</td><td style="padding:10px 14px;font-size:13px;border-top:1px solid #e5e7eb">{{ \Carbon\Carbon::parse($separation->last_working_day)->format('d M Y') }}</td></tr>
    </table>

    @if(in_array($event, ['offboarding_tasks', 'department_tasks_completed'], true) && count($tasks))
      <div style="font-size:13px;font-weight:bold;margin:18px 0 8px">{{ $event === 'offboarding_tasks' ? 'Assigned tasks' : 'Resolved tasks' }}</div>
      <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
        @foreach($tasks as $task)
          <tr><td style="padding:10px 14px;font-size:13px;border-top:{{ $loop->first ? '0' : '1px' }} solid #e5e7eb">&#9744;&nbsp; {{ $task }}</td></tr>
        @endforeach
      </table>
    @endif

    <p style="margin:24px 0 8px;text-align:center"><a href="{{ $separationUrl }}" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:7px">Open Separation Request</a></p>
  </td></tr>
  <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;text-align:center;color:#9ca3af;font-size:11px">Diamond Insurance Broker &bull; Human Resources &bull; Automated notification</td></tr>
</table></td></tr></table>
</body></html>
