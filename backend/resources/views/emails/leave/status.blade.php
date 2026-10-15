@php
    use App\Mail\Support\MailFormat;
    use App\Support\Locale\StatusLabel;

    $isRtl = \App\Support\Locale\SupportedLocales::isRtl();
    $align = MailFormat::start();
    $hrEmailLink = '<a href="mailto:diamond-hr@dbroker.com.sa" style="color:#1e3a5f">diamond-hr@dbroker.com.sa</a>';

    $statusColor = match($action) {

    'submitted' => '#f59e0b',
    'manager_approved' => '#3b82f6',
    'manager_rejected' => '#ef4444',
    'hr_approved' => '#10b981',
    'hr_rejected' => '#ef4444',
    'cancelled' => '#6b7280',

    default => '#f59e0b'
    };

    $statusText = in_array($action, ['submitted', 'manager_approved', 'manager_rejected', 'hr_approved', 'hr_rejected', 'cancelled'], true)
        ? __('mail_leave.banner.' . $action)
        : __('mail_leave.banner.default');

    $displayName = MailFormat::name($recipientName, $recipientNameAr ?? null);

    $isHourlyLeave = (bool) ($leave->leaveType?->is_hourly ?? false)
        || in_array(strtoupper((string) ($leave->leaveType?->code ?? '')), ['BE', 'PE'], true);

    if ($isHourlyLeave) {
        $startTime = $leave->start_time
            ? \Carbon\Carbon::parse($leave->start_time)->format('H:i')
            : null;
        $endTime = $leave->end_time
            ? \Carbon\Carbon::parse($leave->end_time)->format('H:i')
            : null;
        $durationText = MailFormat::hours($leave->total_hours ?? 0)
            . ($startTime && $endTime ? " ({$startTime} – {$endTime})" : '');
    } else {
        $durationText = MailFormat::days($leave->total_days ?? 0);
    }
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ \App\Support\Locale\SupportedLocales::dir() }}">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
    </head>

    <body dir="{{ \App\Support\Locale\SupportedLocales::dir() }}" style="margin:0;padding:0;background:#f0f4f8;font-family:{{ MailFormat::font('Arial,sans-serif') }};direction:{{ $isRtl ? 'rtl' : 'ltr' }};text-align:{{ $align }};">

        <table width="100%" cellpadding="0" cellspacing="0" dir="{{ \App\Support\Locale\SupportedLocales::dir() }}">
            <tr>
                <td align="center" style="padding:30px 10px">

                    <table width="600" cellpadding="0" cellspacing="0" dir="{{ \App\Support\Locale\SupportedLocales::dir() }}"
                           style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1);text-align:{{ $align }}">

                        <!-- Header -->
                        <tr>
                            <td style="background:#1e3a5f;padding:24px 32px">
                                <p style="margin:0;color:#fff;font-size:20px;font-weight:bold">
                                    {{ __('mail.company') }}
                                </p>

                                <p style="margin:4px 0 0;color:#93c5fd;font-size:12px;letter-spacing:{{ $isRtl ? '0' : '1px' }};text-transform:uppercase">
                                    {{ __('mail.department') }}
                                </p>
                            </td>
                        </tr>

                        <!-- Banner -->
                        <tr>
                            <td style="background:{{ $statusColor }};padding:14px 32px">

                                <p style="margin:0;color:#fff;font-size:15px;font-weight:bold;text-transform:uppercase">
                                    {{ $statusText }}
                                </p>

                            </td>
                        </tr>

                        <!-- Body -->
                        <tr>
                            <td style="padding:28px 32px">

                                <p style="margin:0 0 16px;color:#1a1a2e;font-size:15px">
                                    {!! __('mail.greeting', ['name' => '<strong>' . e($displayName) . '</strong>']) !!}
                                </p>

                                @if(in_array($action, ['submitted', 'manager_approved', 'manager_rejected', 'hr_approved', 'hr_rejected', 'cancelled'], true))

                                <p style="color:#374151;font-size:14px;line-height:1.6">
                                    {{ __('mail_leave.intro.' . $action) }}
                                </p>

                                @endif

                                @if(!empty($remarks))

                                <p style="margin-top:15px;color:#374151;font-size:14px;">
                                    <strong>{{ __('mail.labels.remarks') }}:</strong> {{ $remarks }}
                                </p>

                                @endif





                                <!-- Leave Details -->

                                <table width="100%" cellpadding="0" cellspacing="0"
                                       style="margin:20px 0;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">


                                    <tr>
                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;font-weight:bold">
                                            {{ __('mail.labels.employee') }}
                                        </td>

                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb">
                                            {{ MailFormat::name($leave->employee?->full_name, $leave->employee?->arabic_name) }}
                                        </td>
                                    </tr>

                                    <tr style="background:#f9fafb">
                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;font-weight:bold">
                                            {{ __('mail_leave.labels.leave_type') }}
                                        </td>

                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb">
                                            {{ MailFormat::localized($leave->leaveType) }}
                                        </td>
                                    </tr>

                                    <tr>
                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;font-weight:bold">
                                            {{ __('mail_leave.labels.from_date') }}
                                        </td>

                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb">
                                            {{ MailFormat::date($leave->start_date) }}
                                        </td>
                                    </tr>

                                    <tr style="background:#f9fafb">
                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;font-weight:bold">
                                            {{ __('mail_leave.labels.to_date') }}
                                        </td>

                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb">
                                            {{ MailFormat::date($leave->end_date) }}
                                        </td>
                                    </tr>

                                    <tr>
                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;font-weight:bold">
                                            {{ __('mail.labels.duration') }}
                                        </td>

                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb;font-weight:600">
                                            {{ $durationText }}
                                        </td>
                                    </tr>

                                    <tr style="background:#f9fafb">
                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;font-weight:bold">
                                            {{ __('mail.labels.status') }}
                                        </td>

                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb;font-weight:600">
                                            {{ $statusText }}
                                        </td>
                                    </tr>

                                </table>

                                <table width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0">
                                    <tr>
                                        <td align="center">
                                            <a href="{{ $requestUrl }}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;font-size:14px;font-weight:bold;padding:12px 22px;border-radius:7px">
                                                {{ __('mail_leave.view_request') }}
                                            </a>
                                        </td>
                                    </tr>
                                </table>

                                <!-- REMARKS --> @if(!empty($remarks))
                                <div style=" margin-top:20px; padding:15px; background:#f9fafb; border-{{ $align }}:4px solid #3b82f6; "> <strong>{{ __('mail.labels.remarks') }}</strong><br> {{ $remarks }} </div> @endif
                                <!-- CONFLICT WARNING --> @if( $action == 'submitted' && isset($conflicts) && $conflicts->count() > 0 ) <div style=" margin-top:25px; padding:15px; background:#fff7ed; border-{{ $align }}:4px solid #f97316; "> <strong> ⚠ {{ __('mail_leave.conflict.title') }} </strong> <p style="margin-top:10px;"> {{ __('mail_leave.conflict.intro') }} </p>
                                    <table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin-top:10px">
                                        <tr style="background:#fed7aa"> <th align="{{ $align }}">{{ __('mail.labels.employee') }}</th> <th align="{{ $align }}">{{ __('mail.labels.from') }}</th> <th align="{{ $align }}">{{ __('mail.labels.to') }}</th> <th align="{{ $align }}">{{ __('mail.labels.status') }}</th> </tr> @foreach($conflicts as $conflict) <tr> <td>{{ MailFormat::name($conflict->employee?->full_name, $conflict->employee?->arabic_name) }}</td> <td>{{ MailFormat::date($conflict->start_date) }}</td> <td>{{ MailFormat::date($conflict->end_date) }}</td> <td>{{ StatusLabel::for($conflict->status) }}</td> </tr> @endforeach </table> </div> @endif <p style="margin-top:25px;color:#6b7280;font-size:13px"> {!! __('mail_leave.contact_hr', ['email' => $hrEmailLink]) !!} </p> </td> </tr> <!-- FOOTER --> <tr> <td style="background:#f9fafb;padding:18px;text-align:center;border-top:1px solid #e5e7eb"> <div style="font-size:11px;color:#9ca3af"> {{ __('mail.company') }} • {{ __('mail.location') }} </div> <div style="font-size:11px;color:#9ca3af;margin-top:4px"> {{ __('mail_leave.auto_notice') }} </div> </td> </tr>
                    </table>

                </td>
            </tr>
        </table>

    </body>
</html>
