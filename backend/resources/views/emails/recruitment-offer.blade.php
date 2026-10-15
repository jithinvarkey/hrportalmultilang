<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px">
  <table width="620" cellpadding="0" cellspacing="0" style="max-width:620px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
    <tr><td style="background:#1e3a5f;padding:24px 32px;color:#fff;font-size:20px;font-weight:bold">Diamond Insurance Broker</td></tr>
    <tr><td style="padding:32px;color:#374151;font-size:15px;line-height:1.7">
      <p style="margin:0 0 18px">Dear {{ $application->applicant_name ?? 'Candidate' }},</p>
      <p style="margin:0 0 18px">
        We are pleased to share your job offer letter. Please review the attached PDF carefully.
      </p>
      <p style="margin:0 0 18px">
        If you agree with the offer, please reply to this email. The onboarding form and NDA will be shared after HR confirms the hiring step.
      </p>
      <p style="margin:0;color:#6b7280;font-size:13px">This is an automated recruitment notification.</p>
    </td></tr>
  </table>
</td></tr></table>
</body>
</html>
