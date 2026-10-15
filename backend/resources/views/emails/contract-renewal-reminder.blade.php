<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif">
@php
  $employee = $renewal->employee;
  $contract = $renewal->contract;
@endphp
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px">
  <table width="620" cellpadding="0" cellspacing="0" style="max-width:620px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
    <tr><td style="background:#1e3a5f;padding:24px 32px;color:#fff;font-size:20px;font-weight:bold">{{ config('app.name', 'HRMS') }}</td></tr>
    <tr><td style="padding:32px;color:#374151;font-size:15px;line-height:1.7">
      <p style="margin:0 0 18px">Dear Team,</p>
      <p style="margin:0 0 18px">
        A contract renewal request has been auto-created for
        <strong>{{ $employee?->full_name ?? 'Employee' }}</strong>.
      </p>
      <table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;border:1px solid #e5e7eb;border-radius:6px">
        <tr><td style="padding:14px 16px;background:#f9fafb;color:#111827;font-weight:bold">Renewal Details</td></tr>
        <tr><td style="padding:14px 16px">
          <div><strong>Reference:</strong> {{ $renewal->reference }}</div>
          <div><strong>Employee Code:</strong> {{ $employee?->employee_code ?? '-' }}</div>
          <div><strong>Current Contract:</strong> {{ $contract?->reference ?? '-' }}</div>
          <div><strong>Current Expiry:</strong> {{ $contract?->end_date?->format('d M Y') ?? '-' }}</div>
          <div><strong>Proposed Period:</strong> {{ $renewal->proposed_start_date?->format('d M Y') ?? '-' }} to {{ $renewal->proposed_end_date?->format('d M Y') ?? 'Unlimited' }}</div>
          <div><strong>Proposed Type:</strong> {{ ucwords(str_replace('_', ' ', (string) $renewal->proposed_type)) }}</div>
          <div><strong>Proposed Salary:</strong> SAR {{ number_format((float) $renewal->proposed_salary, 2) }}</div>
        </td></tr>
      </table>
      <p style="margin:0 0 18px">
        The request is waiting for manager review and will continue through the contract renewal approval workflow.
      </p>
      <p style="margin:0">Please log in to HRMS to review the request.</p>
    </td></tr>
    <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:11px;text-align:center">This is an automated contract renewal notification.</td></tr>
  </table>
</td></tr></table>
</body>
</html>
