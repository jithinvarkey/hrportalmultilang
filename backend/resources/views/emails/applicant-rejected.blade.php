<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px">
  <table width="620" cellpadding="0" cellspacing="0" style="max-width:620px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden">
    <tr><td style="background:#1e3a5f;padding:24px 32px;color:#fff">
      <div style="font-size:20px;font-weight:bold">Diamond Insurance Broker</div>
      <div style="margin-top:4px;color:#bfdbfe;font-size:12px;letter-spacing:1px;text-transform:uppercase">Recruitment Team</div>
    </td></tr>
    <tr><td style="padding:32px;color:#374151;font-size:15px;line-height:1.7">
      <p style="margin:0 0 18px">Dear {{ $application->applicant_name ?: 'Candidate' }},</p>
      <p style="margin:0 0 18px">
        Thank you for your interest in the
        <strong>{{ $application->jobPosting?->title ?? 'applied' }}</strong> position and for the time you invested in our recruitment process.
      </p>
      <p style="margin:0 0 18px">
        After careful consideration, we have decided not to proceed with your application at this time.
        We appreciate the opportunity to learn about your experience and qualifications.
      </p>
      <p style="margin:0 0 18px">
        We encourage you to apply for future opportunities that match your skills and experience.
      </p>
      <p style="margin:24px 0 0">Kind regards,<br><strong>Recruitment Team</strong><br>Diamond Insurance Broker</p>
    </td></tr>
    <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;text-align:center">
      <p style="margin:0;color:#9ca3af;font-size:11px">This is an automated recruitment notification.</p>
    </td></tr>
  </table>
</td></tr></table>
</body>
</html>
