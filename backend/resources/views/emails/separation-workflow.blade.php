@php
  $isRtl = \App\Support\Locale\SupportedLocales::isRtl();
  $dir = \App\Support\Locale\SupportedLocales::dir();
  $font = $isRtl ? "'IBM Plex Sans Arabic', Tahoma, Arial, sans-serif" : 'Arial,sans-serif';
  $L = \App\Support\Locale\LocalName::class;
  $banner = in_array($event, ['submitted', 'manager_approved', 'hr_approved', 'offboarding_tasks'], true) ? $event : 'default';
  $category = $L::option('mail_separation.categories', $taskCategory, '');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $dir }}">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body dir="{{ $dir }}" style="margin:0;padding:0;background:#f0f4f8;font-family:{{ $font }};color:#1f2937">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:30px 10px">
<table width="600" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1);text-align:{{ $isRtl ? 'right' : 'left' }}">
  <tr><td style="background:#1e3a5f;padding:24px 32px">
    <div style="color:#fff;font-size:20px;font-weight:bold">{{ __('mail.company') }}</div>
    <div style="margin-top:4px;color:#93c5fd;font-size:12px;letter-spacing:{{ $isRtl ? '0' : '1px' }};text-transform:uppercase">{{ __('mail.department') }}</div>
  </td></tr>
  <tr><td style="background:#2563eb;padding:13px 32px;color:#fff;font-size:14px;font-weight:bold;text-transform:uppercase">
    {{ __('mail_separation.banner.' . $banner) }}
  </td></tr>
  <tr><td style="padding:28px 32px">
    <p style="margin:0 0 16px">{!! __('mail.greeting', ['name' => '<strong>' . e($recipientName ?: __('mail_separation.colleague')) . '</strong>']) !!}</p>
    @if($event === 'submitted')
      <p style="font-size:14px;line-height:1.6">{{ __('mail_separation.intro.submitted') }}</p>
    @elseif($event === 'manager_approved')
      <p style="font-size:14px;line-height:1.6">{{ __('mail_separation.intro.manager_approved') }}</p>
    @elseif($event === 'hr_approved')
      <p style="font-size:14px;line-height:1.6">{{ __('mail_separation.intro.hr_approved') }}</p>
    @elseif($event === 'offboarding_tasks')
      <p style="font-size:14px;line-height:1.6">{!! __('mail_separation.intro.offboarding_tasks', ['category' => e($category)]) !!}</p>
    @else
      <p style="font-size:14px;line-height:1.6">
        @if($completedByName)
          {!! __('mail_separation.intro.completed_by', ['category' => e($category), 'name' => e($completedByName)]) !!}
        @else
          {!! __('mail_separation.intro.completed', ['category' => e($category)]) !!}
        @endif
      </p>
    @endif

    <table width="100%" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="margin:18px 0;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
      <tr style="background:#f9fafb"><td style="padding:10px 14px;font-size:12px;color:#6b7280;font-weight:bold;width:38%">{{ __('mail.labels.reference') }}</td><td style="padding:10px 14px;font-size:13px;font-weight:600">{{ $separation->reference }}</td></tr>
      <tr><td style="padding:10px 14px;font-size:12px;color:#6b7280;font-weight:bold;border-top:1px solid #e5e7eb">{{ __('mail.labels.employee') }}</td><td style="padding:10px 14px;font-size:13px;border-top:1px solid #e5e7eb">{{ $L::employee($separation->employee, '') }}</td></tr>
      <tr style="background:#f9fafb"><td style="padding:10px 14px;font-size:12px;color:#6b7280;font-weight:bold;border-top:1px solid #e5e7eb">{{ __('mail.labels.type') }}</td><td style="padding:10px 14px;font-size:13px;border-top:1px solid #e5e7eb">{{ $L::option('mail_separation.types', $separation->type, '') }}</td></tr>
      <tr><td style="padding:10px 14px;font-size:12px;color:#6b7280;font-weight:bold;border-top:1px solid #e5e7eb">{{ __('mail_separation.last_working_day') }}</td><td style="padding:10px 14px;font-size:13px;border-top:1px solid #e5e7eb">{{ $L::date($separation->last_working_day) }}</td></tr>
    </table>

    @if(in_array($event, ['offboarding_tasks', 'department_tasks_completed'], true) && count($tasks))
      <div style="font-size:13px;font-weight:bold;margin:18px 0 8px">{{ $event === 'offboarding_tasks' ? __('mail_separation.assigned_tasks') : __('mail_separation.resolved_tasks') }}</div>
      <table width="100%" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
        @foreach($tasks as $task)
          <tr><td style="padding:10px 14px;font-size:13px;border-top:{{ $loop->first ? '0' : '1px' }} solid #e5e7eb">&#9744;&nbsp; {{ $task }}</td></tr>
        @endforeach
      </table>
    @endif

    <p style="margin:24px 0 8px;text-align:center"><a href="{{ $separationUrl }}" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:7px">{{ __('mail_separation.button') }}</a></p>
  </td></tr>
  <tr><td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;text-align:center;color:#9ca3af;font-size:11px">{{ __('mail.company') }} &bull; {{ __('mail.department') }} &bull; {{ __('mail_separation.automated') }}</td></tr>
</table></td></tr></table>
</body></html>
