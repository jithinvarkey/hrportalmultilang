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
  body { font-family: "DejaVu Sans", DejaVu Sans, Arial, sans-serif; font-size: 12px; color:#111827; line-height:1.55; }
  h1 { text-align:center; font-size:18px; text-transform:uppercase; margin-bottom:20px; }
  h2 { text-align:center; font-size:16px; margin:0 0 20px; }
  .box { border:1px solid #d1d5db; padding:10px; margin:12px 0; }
  .signature { width:150px; }
  .seal { width:170px; float:right; opacity:.9; }
  .arabic {
    direction: ltr;
    text-align: right;
    unicode-bidi: normal;
    font-family: "Traditional Arabic", "DejaVu Sans", Arial, sans-serif;
    font-size: 15px;
  }
  .arabic * { font-family: inherit; }
</style>
</head>
<body>
  <h1>Non-Disclosure Agreement</h1>
  <h2 class="arabic">{{ $ar('اتفاقية عدم إفصاح') }}</h2>
  <p>This Non-Disclosure Agreement is entered into between Diamond Insurance Broker and <strong>{{ $name }}</strong>, appointed/proposed for the position of <strong>{{ $position ?: 'Employee' }}</strong>.</p>
  <p class="arabic">{{ $ar('تم إبرام اتفاقية عدم الإفصاح هذه بين شركة دايموند لوساطة التأمين و') }} <strong>{{ $name }}</strong>، {{ $ar('المعين أو المرشح لمنصب') }} <strong>{{ $position ?: $ar('موظف') }}</strong>.</p>
  <div class="box">
    <p>The employee agrees to keep all company, customer, financial, operational, technical, and business information confidential during and after employment.</p>
    <p class="arabic">{{ $ar('يوافق الموظف على الحفاظ على سرية جميع معلومات الشركة والعملاء والمعلومات المالية والتشغيلية والفنية والتجارية أثناء فترة العمل وبعد انتهائها.') }}</p>
    <p>The employee shall not disclose, copy, misuse, transfer, or publish confidential information except as required for assigned duties and approved by the company.</p>
    <p class="arabic">{{ $ar('لا يجوز للموظف إفشاء المعلومات السرية أو نسخها أو إساءة استخدامها أو نقلها أو نشرها إلا بالقدر المطلوب لأداء المهام المكلف بها وبعد موافقة الشركة.') }}</p>
    <p>All documents, systems, files, and company property remain the property of Diamond Insurance Broker and must be returned upon request or separation.</p>
    <p class="arabic">{{ $ar('تبقى جميع المستندات والأنظمة والملفات وممتلكات الشركة ملكا لشركة دايموند لوساطة التأمين، ويجب إعادتها عند الطلب أو عند انتهاء العلاقة الوظيفية.') }}</p>
  </div>
  <p>This agreement remains binding during and after employment.</p>
  <p class="arabic">{{ $ar('تظل هذه الاتفاقية ملزمة أثناء العلاقة الوظيفية وبعد انتهائها.') }}</p>
  <table width="100%" style="margin-top:44px"><tr>
    <td width="50%">
      <strong>Employee Name:</strong> {{ $name }}<br>
      <strong>Signature:</strong> ______________________<br>
      <strong>Date:</strong> ______________________
    </td>
    <td width="50%" class="arabic">
      <strong>{{ $ar('اسم الموظف:') }}</strong> {{ $name }}<br>
      <strong>{{ $ar('التوقيع:') }}</strong> ______________________<br>
      <strong>{{ $ar('التاريخ:') }}</strong> ______________________<br><br>
      @if($signature)<img class="signature" src="{{ $signature }}" alt="Signature"><br>@endif
      <strong>For Diamond Insurance Broker</strong><br>
      <strong>{{ $ar('عن شركة دايموند لوساطة التأمين') }}</strong><br>
      @if($seal)<img class="seal" src="{{ $seal }}" alt="Company Seal">@endif
    </td>
  </tr></table>
</body>
</html>
