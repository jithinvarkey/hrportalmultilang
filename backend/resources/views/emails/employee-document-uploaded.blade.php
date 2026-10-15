<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif">
@php
  $documentType = ucwords(str_replace('_', ' ', (string) $document->type));
@endphp
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px">
  <table width="620" cellpadding="0" cellspacing="0" style="max-width:620px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
    <tr><td style="background:#1e3a5f;padding:24px 32px;color:#fff;font-size:20px;font-weight:bold">{{ config('app.name', 'HRMS') }}</td></tr>
    <tr><td style="padding:32px;color:#374151;font-size:15px;line-height:1.7">
      <p style="margin:0 0 18px">Dear HR Team,</p>
      <p style="margin:0 0 18px">
        <strong>{{ $employee->full_name ?? 'An employee' }}</strong> uploaded a document from the employee profile.
        Please verify it in HRMS.
      </p>
      <table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;border:1px solid #e5e7eb;border-radius:6px">
        <tr><td style="padding:14px 16px;background:#f9fafb;color:#111827;font-weight:bold">Document Details</td></tr>
        <tr><td style="padding:14px 16px">
          <div><strong>Employee:</strong> {{ $employee->full_name ?? '-' }}</div>
          <div><strong>Employee Code:</strong> {{ $employee->employee_code ?? '-' }}</div>
          <div><strong>Document Title:</strong> {{ $document->title }}</div>
          <div><strong>Document Type:</strong> {{ $documentType ?: '-' }}</div>
          <div><strong>File Name:</strong> {{ $document->file_name ?? '-' }}</div>
          <div><strong>Uploaded At:</strong> {{ $document->created_at?->format('d M Y h:i A') ?? now()->format('d M Y h:i A') }}</div>
        </td></tr>
      </table>
      <p style="margin:0">Please log in to HRMS and verify the uploaded employee document.</p>
    </td></tr>
    <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:11px;text-align:center">This is an automated employee document verification notification.</td></tr>
  </table>
</td></tr></table>
</body>
</html>
