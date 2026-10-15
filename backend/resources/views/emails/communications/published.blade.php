<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0">
  <tr>
    <td align="center" style="padding:30px 10px">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1)">
        <tr>
          <td style="background:#1e3a5f;padding:24px 32px">
            <p style="margin:0;color:#fff;font-size:20px;font-weight:bold">{{ config('app.name', 'HRMS') }}</p>
            <p style="margin:4px 0 0;color:#93c5fd;font-size:12px;letter-spacing:1px;text-transform:uppercase">Human Resources</p>
          </td>
        </tr>
        <tr>
          <td style="background:#facc15;padding:14px 32px">
            <p style="margin:0;color:#111827;font-size:15px;font-weight:bold;text-transform:uppercase;letter-spacing:.5px">
              New {{ $type === 'policy' ? 'Policy' : 'Announcement' }}
            </p>
          </td>
        </tr>
        <tr>
          <td style="padding:28px 32px">
            <p style="margin:0 0 16px;color:#1a1a2e;font-size:15px">
              Dear <strong>{{ $recipientName }}</strong>,
            </p>
            <p style="color:#374151;font-size:14px;line-height:1.6;margin:0 0 16px">
              A new {{ $type === 'policy' ? 'policy' : 'announcement' }} has been published for you.
            </p>
            <table width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
              @if($type === 'announcement')
              <tr>
                <td width="{{ $titleAr || $bodyAr ? '50%' : '100%' }}" valign="top" dir="ltr" style="padding:16px;text-align:left">
                  <div style="font-size:11px;color:#6b7280;font-weight:bold;text-transform:uppercase;margin-bottom:8px">English</div>
                  <div style="font-size:15px;color:#1a1a2e;font-weight:700;margin-bottom:8px">{{ $title }}</div>
                  @if($body)<div style="font-size:13px;color:#374151;line-height:1.6">{{ $body }}</div>@endif
                </td>
                @if($titleAr || $bodyAr)
                <td width="50%" valign="top" dir="rtl" lang="ar" style="padding:16px;border-left:1px solid #e5e7eb;text-align:right;font-family:Arial,Tahoma,sans-serif">
                  <div style="font-size:11px;color:#6b7280;font-weight:bold;margin-bottom:8px">العربية</div>
                  @if($titleAr)<div style="font-size:15px;color:#1a1a2e;font-weight:700;margin-bottom:8px">{{ $titleAr }}</div>@endif
                  @if($bodyAr)<div style="font-size:13px;color:#374151;line-height:1.6">{{ $bodyAr }}</div>@endif
                </td>
                @endif
              </tr>
              @else
              <tr style="background:#f9fafb">
                <td style="padding:10px 16px;font-size:12px;color:#6b7280;font-weight:bold;text-transform:uppercase;letter-spacing:.05em;width:30%">Title</td>
                <td style="padding:10px 16px;font-size:13px;color:#1a1a2e;font-weight:600">{{ $title }}</td>
              </tr>
              @if($body)
              <tr>
                <td style="padding:10px 16px;font-size:12px;color:#6b7280;font-weight:bold;text-transform:uppercase;letter-spacing:.05em;border-top:1px solid #e5e7eb">Summary</td>
                <td style="padding:10px 16px;font-size:13px;color:#374151;border-top:1px solid #e5e7eb;line-height:1.5">{{ $body }}</td>
              </tr>
              @endif
              @endif
            </table>
            <p style="margin:24px 0 0">
              <a href="{{ $link }}" style="display:inline-block;background:#1e3a5f;color:#fff;text-decoration:none;font-size:14px;font-weight:bold;padding:11px 18px;border-radius:8px">
                View {{ $type === 'policy' ? 'Policy' : 'Announcement' }}
              </a>
            </p>
          </td>
        </tr>
        <tr>
          <td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;text-align:center">
            <p style="margin:0;color:#9ca3af;font-size:11px">
              This is an automated HRMS message, please do not reply.
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
