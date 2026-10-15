<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
    </head>

    <body style="margin:0;padding:0;background:#f0f4f8;font-family:Arial,sans-serif;">

        @php

        $statusColor = match($action) {

        'submitted' => '#f59e0b',
        'manager_approved' => '#3b82f6',
        'manager_rejected' => '#ef4444',
        'hr_approved' => '#10b981',
        'hr_rejected' => '#ef4444',
        'cancelled' => '#6b7280',

        default => '#f59e0b'
        };

        $statusText = match($action) {

        'submitted' => 'Leave Request Submitted',
        'manager_approved' => 'Manager Approval Completed',
        'manager_rejected' => 'Rejected By Manager',
        'hr_approved' => 'Leave Approved',
        'hr_rejected' => 'Rejected By HR',
        'cancelled' => 'Leave Cancelled',

        default => 'Leave Request'
        };

        $isHourlyLeave = (bool) ($leave->leaveType?->is_hourly ?? false)
            || in_array(strtoupper((string) ($leave->leaveType?->code ?? '')), ['BE', 'PE'], true);

        if ($isHourlyLeave) {
            $hours = (float) ($leave->total_hours ?? 0);
            $formattedHours = rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
            $hourUnit = abs($hours - 1) < 0.001 ? 'hour' : 'hours';
            $startTime = $leave->start_time
                ? \Carbon\Carbon::parse($leave->start_time)->format('H:i')
                : null;
            $endTime = $leave->end_time
                ? \Carbon\Carbon::parse($leave->end_time)->format('H:i')
                : null;
            $durationText = $formattedHours . ' ' . $hourUnit
                . ($startTime && $endTime ? " ({$startTime} – {$endTime})" : '');
        } else {
            $durationText = $leave->total_days . ' day(s)';
        }

        @endphp

        <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td align="center" style="padding:30px 10px">

                    <table width="600" cellpadding="0" cellspacing="0"
                           style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1)">

                        <!-- Header -->
                        <tr>
                            <td style="background:#1e3a5f;padding:24px 32px">
                                <p style="margin:0;color:#fff;font-size:20px;font-weight:bold">
                                    Diamond Insurance Broker
                                </p>

                                <p style="margin:4px 0 0;color:#93c5fd;font-size:12px;letter-spacing:1px;text-transform:uppercase">
                                    Human Resources
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
                                    Dear <strong>{{ $recipientName }}</strong>,
                                </p>

                                @if($action == 'submitted')

                                <p style="color:#374151;font-size:14px;line-height:1.6">
                                    A new leave request has been submitted and requires your review.
                                </p>

                                @elseif($action == 'manager_approved')

                                <p style="color:#374151;font-size:14px;line-height:1.6">
                                    The leave request has been approved by the reporting manager and is awaiting HR approval.
                                </p>

                                @elseif($action == 'manager_rejected')

                                <p style="color:#374151;font-size:14px;line-height:1.6">
                                    The leave request has been rejected by the reporting manager.
                                </p>

                                @elseif($action == 'hr_approved')

                                <p style="color:#374151;font-size:14px;line-height:1.6">
                                    Your leave request has been fully approved by HR.
                                </p>

                                @elseif($action == 'hr_rejected')

                                <p style="color:#374151;font-size:14px;line-height:1.6">
                                    Your leave request has been rejected by HR.
                                </p>

                                @elseif($action == 'cancelled')

                                <p style="color:#374151;font-size:14px;line-height:1.6">
                                    The leave request has been cancelled.
                                </p>

                                @endif

                                @if(!empty($remarks))

                                <p style="margin-top:15px;color:#374151;font-size:14px;">
                                    <strong>Remarks:</strong> {{ $remarks }}
                                </p>

                                @endif





                                <!-- Leave Details -->

                                <table width="100%" cellpadding="0" cellspacing="0"
                                       style="margin:20px 0;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">


                                    <tr>
                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;font-weight:bold">
                                            Employee
                                        </td>

                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb">
                                            {{ $leave->employee?->full_name }}
                                        </td>
                                    </tr>

                                    <tr style="background:#f9fafb">
                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;font-weight:bold">
                                            Leave Type
                                        </td>

                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb">
                                            {{ $leave->leaveType?->name }}
                                        </td>
                                    </tr>

                                    <tr>
                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;font-weight:bold">
                                            From Date
                                        </td>

                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb">
                                            {{ \Carbon\Carbon::parse($leave->start_date)->format('d M Y') }}
                                        </td>
                                    </tr>

                                    <tr style="background:#f9fafb">
                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;font-weight:bold">
                                            To Date
                                        </td>

                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb">
                                            {{ \Carbon\Carbon::parse($leave->end_date)->format('d M Y') }}
                                        </td>
                                    </tr>

                                    <tr>
                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;font-weight:bold">
                                            Duration
                                        </td>

                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb;font-weight:600">
                                            {{ $durationText }}
                                        </td>
                                    </tr>

                                    <tr style="background:#f9fafb">
                                        <td style="padding:10px 16px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;font-weight:bold">
                                            Status
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
                                                View Leave Request
                                            </a>
                                        </td>
                                    </tr>
                                </table>

                                <!-- REMARKS --> @if(!empty($remarks)) 
                                <div style=" margin-top:20px; padding:15px; background:#f9fafb; border-left:4px solid #3b82f6; "> <strong>Remarks</strong><br> {{ $remarks }} </div> @endif
                                <!-- CONFLICT WARNING --> @if( $action == 'submitted' && isset($conflicts) && $conflicts->count() > 0 ) <div style=" margin-top:25px; padding:15px; background:#fff7ed; border-left:4px solid #f97316; "> <strong> ⚠ Department Leave Conflict Warning </strong> <p style="margin-top:10px;"> This employee has applied for more than 5 days of Annual Leave. The following employees from the same department have overlapping leave requests: </p> 
                                    <table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin-top:10px">
                                        <tr style="background:#fed7aa"> <th align="left">Employee</th> <th align="left">From</th> <th align="left">To</th> <th align="left">Status</th> </tr> @foreach($conflicts as $conflict) <tr> <td>{{ $conflict->employee?->full_name }}</td> <td>{{ date('d M Y', strtotime($conflict->start_date)) }}</td> <td>{{ date('d M Y', strtotime($conflict->end_date)) }}</td> <td>{{ ucfirst(str_replace('_',' ', $conflict->status)) }}</td> </tr> @endforeach </table> </div> @endif <p style="margin-top:25px;color:#6b7280;font-size:13px"> If you have any questions regarding this request, contact HR at <a href="mailto:diamond-hr@dbroker.com.sa" style="color:#1e3a5f">diamond-hr@dbroker.com.sa</a>. </p> </td> </tr> <!-- FOOTER --> <tr> <td style="background:#f9fafb;padding:18px;text-align:center;border-top:1px solid #e5e7eb"> <div style="font-size:11px;color:#9ca3af"> Diamond Insurance Broker • Riyadh, Saudi Arabia </div> <div style="font-size:11px;color:#9ca3af;margin-top:4px"> This is an automated email. Please do not reply. </div> </td> </tr>
                    </table>

                </td>
            </tr>
        </table>

    </body>
</html>
