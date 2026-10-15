<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:Arial,sans-serif">
@php
    $approved = $action === 'approved';
    $rejected = $action === 'rejected';
    $statusColor = $approved ? '#10b981' : ($rejected ? '#ef4444' : '#2563eb');
    $statusText = $approved ? 'Loan Request Approved' : ($rejected ? 'Loan Request Rejected' : 'Loan Request Requires Review');
@endphp
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:30px 10px">
  <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb">
    <tr><td style="background:#1e3a5f;padding:24px 32px;color:#fff;font-size:20px;font-weight:bold">{{ config('app.name', 'HRMS') }}</td></tr>
    <tr><td style="background:{{ $statusColor }};padding:14px 32px;color:#fff;font-size:15px;font-weight:bold;text-transform:uppercase">{{ $statusText }}</td></tr>
    <tr><td style="padding:28px 32px;color:#374151;font-size:14px;line-height:1.6">
      <p style="margin:0 0 16px">Dear <strong>{{ $recipientName }}</strong>,</p>
      @if($approved)
        <p>Your loan request has been fully approved.</p>
      @elseif($rejected)
        <p>Your loan request has been rejected.@if($loan->rejection_reason) <strong>Reason:</strong> {{ $loan->rejection_reason }}@endif</p>
      @else
        <p>A loan request has reached your approval stage and requires your review.</p>
      @endif

      <table width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
        <tr style="background:#f9fafb"><td style="padding:10px 16px;color:#6b7280;font-weight:bold;width:40%">Reference</td><td style="padding:10px 16px;font-weight:600">{{ $loan->reference }}</td></tr>
        <tr><td style="padding:10px 16px;border-top:1px solid #e5e7eb;color:#6b7280;font-weight:bold">Employee</td><td style="padding:10px 16px;border-top:1px solid #e5e7eb">{{ $loan->employee?->full_name }}</td></tr>
        <tr style="background:#f9fafb"><td style="padding:10px 16px;border-top:1px solid #e5e7eb;color:#6b7280;font-weight:bold">Loan Type</td><td style="padding:10px 16px;border-top:1px solid #e5e7eb">{{ $loan->loanType?->name }}</td></tr>
        <tr><td style="padding:10px 16px;border-top:1px solid #e5e7eb;color:#6b7280;font-weight:bold">Requested Amount</td><td style="padding:10px 16px;border-top:1px solid #e5e7eb;font-weight:600">SAR {{ number_format((float) $loan->requested_amount, 2) }}</td></tr>
        <tr style="background:#f9fafb"><td style="padding:10px 16px;border-top:1px solid #e5e7eb;color:#6b7280;font-weight:bold">Status</td><td style="padding:10px 16px;border-top:1px solid #e5e7eb">{{ ucwords(str_replace('_', ' ', $loan->status)) }}</td></tr>
      </table>

      <p style="margin:24px 0 0;text-align:center"><a href="{{ $requestUrl }}" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:7px">View Loan Request</a></p>
      <p style="margin:20px 0 0;color:#6b7280;font-size:13px">Questions? Contact HR: <a href="mailto:diamond-hr@dbroker.com.sa" style="color:#1e3a5f">diamond-hr@dbroker.com.sa</a></p>
    </td></tr>
    <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:11px;text-align:center">This is an automated message. Please do not reply.</td></tr>
  </table>
</td></tr></table>
</body>
</html>
