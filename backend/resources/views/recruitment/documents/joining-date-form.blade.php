<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<style>
  @font-face {
    font-family: "Traditional Arabic";
    src: url("{{ $arabic_font_regular }}") format("truetype");
    font-weight: normal;
  }
  @font-face {
    font-family: "Traditional Arabic";
    src: url("{{ $arabic_font_bold }}") format("truetype");
    font-weight: bold;
  }
  @page { margin: 26px 28px; }
  body {
    font-family: "DejaVu Sans", DejaVu Sans, Arial, sans-serif;
    font-size: 11px;
    color: #111;
    line-height: 1.35;
  }
  .title {
    text-align: center;
    font-size: 18px;
    font-weight: 700;
    margin: 4px 0 16px;
  }
  .header { text-align: left; margin-bottom: 8px; }
  .logo { width: 185px; max-height: 46px; }
  .section-title {
    background: #d9eaf7;
    border: 1px solid #6f8ea8;
    font-weight: 700;
    padding: 6px 9px;
    margin-top: 12px;
  }
  table {
    width: 100%;
    border-collapse: collapse;
  }
  td, th {
    border: 1px solid #6f8ea8;
    padding: 7px 8px;
    vertical-align: middle;
  }
  .label {
    width: 24%;
    background: #f2f7fb;
    font-weight: 700;
  }
  .value {
    width: 26%;
    min-height: 18px;
    font-weight: 600;
  }
  .ar {
    direction: ltr;
    text-align: right;
    unicode-bidi: normal;
    font-family: "Traditional Arabic", "DejaVu Sans", Arial, sans-serif;
    font-size: 13px;
  }
  .ar * { font-family: inherit; }
  .check-row {
    padding: 12px 10px;
    border: 1px solid #6f8ea8;
    border-top: 0;
  }
  .checkbox {
    display: inline-block;
    width: 11px;
    height: 11px;
    border: 1px solid #111;
    margin: 0 5px -2px 14px;
  }
  .approval-note {
    border-left: 1px solid #6f8ea8;
    border-right: 1px solid #6f8ea8;
    padding: 10px;
  }
  .signature-line {
    display: inline-block;
    border-bottom: 1px solid #111;
    width: 150px;
    height: 16px;
  }
  .hr-lines {
    border-left: 1px solid #6f8ea8;
    border-right: 1px solid #6f8ea8;
    padding: 10px 12px;
    min-height: 78px;
  }
  .footer-sign {
    margin-top: 22px;
  }
</style>
</head>
<body>
  <div class="header">
    @if($logo)
      <img class="logo" src="{{ $logo }}" alt="Diamond Insurance Broker">
    @endif
  </div>

  <div class="title">
    <div class="ar">{{ $ar('إشعار مباشرة العمل') }}</div>
    <div>Effective Date Notice</div>
  </div>

  <div class="section-title">
    <span>1 Employee Data</span>
    <span class="ar" style="float:right">{{ $ar('بيانات الموظف') }}</span>
  </div>
  <table>
    <tr>
      <td class="label">Name<br><span class="ar">{{ $ar('الاسم') }}</span></td>
      <td class="value">{{ $name ?: '-' }}</td>
      <td class="label">Management<br><span class="ar">{{ $ar('الإدارة') }}</span></td>
      <td class="value">{{ $department ?: '-' }}</td>
    </tr>
    <tr>
      <td class="label">Job title<br><span class="ar">{{ $ar('المسمى الوظيفي') }}</span></td>
      <td class="value">{{ $position ?: '-' }}</td>
      <td class="label">Nationality<br><span class="ar">{{ $ar('الجنسية') }}</span></td>
      <td class="value">{{ $nationality ?? '-' }}</td>
    </tr>
    <tr>
      <td class="label">Employee number<br><span class="ar">{{ $ar('الرقم الوظيفي') }}</span></td>
      <td class="value">{{ $employee_code ?? '-' }}</td>
      <td class="label">Commencement Date<br><span class="ar">{{ $ar('تاريخ المباشرة') }}</span></td>
      <td class="value"></td>
    </tr>
    <tr>
      <td class="label">Employee Signature<br><span class="ar">{{ $ar('توقيع الموظف') }}</span></td>
      <td colspan="3" style="height:38px"></td>
    </tr>
  </table>

  <div class="section-title">
    <span>2 Management Manager Approval</span>
    <span class="ar" style="float:right">{{ $ar('اعتماد مدير الإدارة') }}</span>
  </div>
  <div class="approval-note">
    <div class="ar">{{ $ar('إلى: إدارة الموارد البشرية') }}</div>
    <div>To: HR Management</div>
    <div class="ar">{{ $ar('نأمل اعتماد مباشرة العمل للموظف') }}</div>
    <div>Kindly approve the commencement of work for the employee</div>
  </div>
  <div class="check-row">
    <span class="checkbox"></span>
    <span>Started work for the first time</span>
    <span class="ar">{{ $ar('التحق بالعمل لأول مرة') }}</span>
    <span class="checkbox"></span>
    <span>Joined work after vacation</span>
    <span class="ar">{{ $ar('التحق بالعمل بعد الإجازة') }}</span>
  </div>
  <table>
    <tr>
      <td class="label">Management<br><span class="ar">{{ $ar('الإدارة') }}</span></td>
      <td class="value">{{ $department ?: '-' }}</td>
      <td class="label">Job Title<br><span class="ar">{{ $ar('المسمى الوظيفي') }}</span></td>
      <td class="value">{{ $position ?: '-' }}</td>
    </tr>
    <tr>
      <td class="label">Name<br><span class="ar">{{ $ar('الاسم') }}</span></td>
      <td class="value">{{ $manager_name ?? '' }}</td>
      <td class="label">Date<br><span class="ar">{{ $ar('التاريخ') }}</span></td>
      <td class="value"></td>
    </tr>
    <tr>
      <td class="label">Signature<br><span class="ar">{{ $ar('التوقيع') }}</span></td>
      <td colspan="3" style="height:34px"></td>
    </tr>
  </table>

  <div class="section-title">
    <span>3 HR Confirmation</span>
    <span class="ar" style="float:right">{{ $ar('تأكيد الموارد البشرية') }}</span>
  </div>
  <div class="hr-lines">
    <div class="ar">{{ $ar('الموظف باشر في التاريخ المحدد ويُدرج اسمه بكشوفات الرواتب اعتبارا من:') }}</div>
    <div>The employee began on the specified date and is included in payroll records effective from:
      <span class="signature-line"></span>
    </div>
    <br>
    <div class="ar">{{ $ar('الموظف باشر العمل متأخرا ____ يوما ويُدرج اسمه بكشوفات الرواتب اعتبارا من:') }}</div>
    <div>The employee began work late by <span class="signature-line" style="width:48px"></span> days and is included in payroll records effective from:
      <span class="signature-line"></span>
    </div>
  </div>
  <table>
    <tr>
      <td class="label">Management<br><span class="ar">{{ $ar('الإدارة') }}</span></td>
      <td class="value">Human Resources</td>
      <td class="label">Job Title<br><span class="ar">{{ $ar('المسمى الوظيفي') }}</span></td>
      <td class="value"></td>
    </tr>
    <tr>
      <td class="label">Name<br><span class="ar">{{ $ar('الاسم') }}</span></td>
      <td class="value">Human Resources</td>
      <td class="label">Date<br><span class="ar">{{ $ar('التاريخ') }}</span></td>
      <td class="value"></td>
    </tr>
    <tr>
      <td class="label">Signature<br><span class="ar">{{ $ar('التوقيع') }}</span></td>
      <td colspan="3" style="height:38px"></td>
    </tr>
  </table>

  <div class="footer-sign">
    <strong>Human Resources</strong>
  </div>
</body>
</html>
