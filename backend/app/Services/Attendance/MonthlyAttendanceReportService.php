<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MonthlyAttendanceReportService
{
    private int $fullDayMinutes = 480;
    private array $weekendDays = [5, 6];

    public function __construct(private AttendancePolicyService $attendancePolicy)
    {
    }

    public function download(Request $request)
    {
        $request->validate([
            'month' => 'nullable|required_without_all:from,to|date_format:Y-m',
            'from' => 'nullable|required_with:to|date',
            'to' => 'nullable|required_with:from|date|after_or_equal:from',
            'department_id' => 'nullable|integer|exists:departments,id',
        ]);

        [$periodStart, $periodEnd] = $this->reportPeriod($request);
        $this->loadAttendanceSettings();

        $employees = Employee::with(['department'])
            ->whereIn('status', ['active', 'probation'])
            ->when($request->department_id, fn($query) => $query->where('department_id', $request->department_id))
            ->orderBy('employee_code')
            ->get();

        $employeeIds = $employees->pluck('id');
        $logs = AttendanceLog::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->get()
            ->keyBy(fn(AttendanceLog $log) => $log->employee_id . '|' . $log->date->toDateString());

        $leaveRequests = LeaveRequest::with('leaveType')
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('start_date', '<=', $periodEnd->toDateString())
            ->whereDate('end_date', '>=', $periodStart->toDateString())
            ->whereIn('status', ['approved', 'pending', 'manager_approved'])
            ->get();

        $leavesByDate = $this->leavesByEmployeeDate($leaveRequests, $periodStart, $periodEnd);
        $holidays = $this->holidayNamesByDate($periodStart, $periodEnd);

        $summaryRows = [];
        $sheets = [];

        foreach ($employees as $employee) {
            $employeeRows = [];
            $requiredMinutes = 0;
            $workedMinutes = 0;
            $pendingNotes = [];

            foreach (CarbonPeriod::create($periodStart, $periodEnd) as $day) {
                $date = $day->toDateString();
                $dateKey = $employee->id . '|' . $date;
                $log = $logs->get($dateKey);
                $dayLeaves = $leavesByDate[$dateKey] ?? collect();
                $pendingLeaves = $dayLeaves->filter(fn(LeaveRequest $leave) => in_array($leave->status, ['pending', 'manager_approved'], true));
                $approvedLeaves = $dayLeaves->filter(fn(LeaveRequest $leave) => $leave->status === 'approved');
                $isWeekend = in_array($day->dayOfWeek, $this->weekendDays, true);
                $holidayName = $holidays[$date] ?? null;
                $minimum = (!$isWeekend && !$holidayName) ? $this->fullDayMinutes : 0;

                $requiredMinutes += $minimum;
                $row = $this->dailyRow($day, $log, $minimum, $isWeekend, $holidayName, $approvedLeaves, $pendingLeaves);
                $workedMinutes += (int) $row[4];
                $employeeRows[] = $row;

                if ($pendingLeaves->isNotEmpty()) {
                    $pendingNotes[] = $date . ': ' . $this->leaveDetails($pendingLeaves, true);
                }
            }

            $delta = $workedMinutes - $requiredMinutes;
            $summaryRows[] = [
                $employee->employee_code,
                $employee->full_name,
                $requiredMinutes,
                $workedMinutes,
                $delta,
                $delta / 60,
                implode('; ', array_unique($pendingNotes)),
            ];

            $sheets[] = [
                'name' => $employee->full_name,
                'headers' => ['Date', 'DateString', 'First In', 'Last Out', 'Total', 'Minimum hours', 'Status', 'Leave request'],
                'rows' => $employeeRows,
            ];
        }

        array_unshift($sheets, [
            'name' => 'Sheet1',
            'headers' => [
                'Employee Code ',
                'Employee Name ',
                'Total working Time in Minutes ',
                'Employee working time in Minutes ',
                'Remaining /Extra time in Minutes ',
                'Remaining /Extra time in Hours',
                'Notes',
            ],
            'rows' => $summaryRows,
        ]);

        $filename = 'AttendanceReport - ' . $periodStart->format('Y-m-d') . ' to ' . $periodEnd->format('Y-m-d') . '.xlsx';

        return $this->xlsxDownload($filename, $sheets);
    }

    private function reportPeriod(Request $request): array
    {
        if ($request->filled('from') && $request->filled('to')) {
            return [
                Carbon::parse($request->from)->startOfDay(),
                Carbon::parse($request->to)->startOfDay(),
            ];
        }

        return $this->payrollPeriod((string) $request->month);
    }

    private function payrollPeriod(string $month): array
    {
        $selected = Carbon::createFromFormat('Y-m', $month)->startOfMonth();

        return [
            $selected->copy()->subMonthNoOverflow()->day(17)->startOfDay(),
            $selected->copy()->day(16)->startOfDay(),
        ];
    }

    private function loadAttendanceSettings(): void
    {
        $settings = rescue(fn() => json_decode(file_get_contents(storage_path('app/attendance_settings.json')), true) ?: [], [], false);
        $this->fullDayMinutes = (int) round(((float) ($settings['full_day_hours'] ?? 8)) * 60);
        $this->weekendDays = array_map('intval', $settings['weekend_days'] ?? [5, 6]);
    }

    private function leavesByEmployeeDate(Collection $leaves, Carbon $periodStart, Carbon $periodEnd): array
    {
        $mapped = [];

        foreach ($leaves as $leave) {
            $start = Carbon::parse($leave->start_date)->max($periodStart)->startOfDay();
            $end = Carbon::parse($leave->end_date)->min($periodEnd)->startOfDay();

            foreach (CarbonPeriod::create($start, $end) as $day) {
                $key = $leave->employee_id . '|' . $day->toDateString();
                $mapped[$key] ??= collect();
                $mapped[$key]->push($leave);
            }
        }

        return $mapped;
    }

    private function dailyRow(
        Carbon $day,
        ?AttendanceLog $log,
        int $minimum,
        bool $isWeekend,
        ?string $holidayName,
        Collection $approvedLeaves,
        Collection $pendingLeaves
    ): array {
        $date = $day->toDateString();
        $firstIn = $log?->check_in ? $date . ' ' . $log->check_in : '';
        $lastOut = $log?->check_out ? $date . ' ' . $log->check_out : '';
        $total = $this->workedMinutes($day, $log);
        $reportStatus = $log
            ? $this->attendancePolicy->statusForReport(
                $log->check_in ? (string) $log->check_in : null,
                (string) $log->status,
                $log->source ? (string) $log->source : null
            )
            : 'absent';
        $status = ucfirst(str_replace('_', ' ', $reportStatus));
        $note = trim((string) ($log?->notes ?? ''));

        if ($isWeekend) {
            return [$date, $day->format('l'), $firstIn, $lastOut, 0, 0, 'Weekend', $note];
        }

        if ($holidayName) {
            return [$date, $day->format('l'), $firstIn, $lastOut, $total, 0, 'Holiday', trim($holidayName . ($note ? '; ' . $note : ''))];
        }

        if ($approvedLeaves->isNotEmpty()) {
            $pendingDetails = $pendingLeaves->isNotEmpty()
                ? 'Pending: ' . $this->leaveDetails($pendingLeaves, true)
                : '';
            return [
                $date,
                $day->format('l'),
                $firstIn,
                $lastOut,
                $minimum,
                $minimum,
                'Leave',
                $this->appendNote($pendingDetails, $note),
            ];
        }

        if ($pendingLeaves->isNotEmpty()) {
            $pendingDetails = 'Pending: ' . $this->leaveDetails($pendingLeaves, true);
            $total = max($total, $minimum);
            $status = 'Present';
            $note = $this->appendNote($pendingDetails, $note);
        }

        if (!$log && $pendingLeaves->isEmpty()) {
            $total = 0;
        }

        return [$date, $day->format('l'), $firstIn, $lastOut, $total, $minimum, $status, $note];
    }

    private function workedMinutes(Carbon $day, ?AttendanceLog $log): int
    {
        if (!$log) {
            return 0;
        }

        if ($log->total_minutes !== null) {
            return (int) $log->total_minutes;
        }

        if ($log->check_in && $log->check_out) {
            return (int) Carbon::parse($day->toDateString() . ' ' . $log->check_in)
                ->diffInMinutes(Carbon::parse($day->toDateString() . ' ' . $log->check_out));
        }

        return 0;
    }

    private function leaveDetails(Collection $leaves, bool $includeStatus = false): string
    {
        return $leaves
            ->map(function (LeaveRequest $leave) use ($includeStatus) {
                $parts = [];
                if ($includeStatus) {
                    $parts[] = ucfirst(str_replace('_', ' ', (string) $leave->status));
                }
                $parts[] = $leave->leaveType?->name ?: 'Leave';
                $parts[] = optional($leave->start_date)->format('Y-m-d') . ' to ' . optional($leave->end_date)->format('Y-m-d');
                if ($leave->start_time || $leave->end_time) {
                    $parts[] = trim(($leave->start_time ?: '') . '-' . ($leave->end_time ?: ''), '-');
                }
                if ($leave->reason) {
                    $parts[] = $leave->reason;
                }
                return implode(' | ', array_filter($parts, fn($part) => $part !== ''));
            })
            ->implode('; ');
    }

    private function appendNote(string $note, string $extra): string
    {
        return trim(implode('; ', array_filter([$note, $extra], fn($part) => $part !== '')));
    }

    private function holidayNamesByDate(Carbon $start, Carbon $end): array
    {
        $years = range((int) $start->year, (int) $end->year);
        $dates = [];

        foreach (Holiday::query()->orderBy('date')->get() as $holiday) {
            $holidayStart = Carbon::parse($holiday->date)->startOfDay();
            $holidayEnd = Carbon::parse($holiday->end_date ?: $holiday->date)->startOfDay();
            $duration = max(0, $holidayStart->diffInDays($holidayEnd));

            $ranges = $holiday->is_recurring
                ? collect($years)->map(fn(int $year) => Carbon::create($year, $holidayStart->month, $holidayStart->day)->startOfDay())
                : collect([$holidayStart]);

            foreach ($ranges as $rangeStart) {
                $rangeEnd = $rangeStart->copy()->addDays($duration);
                foreach (CarbonPeriod::create($rangeStart, $rangeEnd) as $day) {
                    if ($day->betweenIncluded($start, $end)) {
                        $dates[$day->toDateString()] = $holiday->name;
                    }
                }
            }
        }

        return $dates;
    }

    private function xlsxDownload(string $filename, array $sheets)
    {
        $dir = storage_path('app/reports');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $dir . '/' . uniqid('monthly_attendance_', true) . '.xlsx';
        $zip = new \ZipArchive();

        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Could not create attendance report.');
        }

        $sheetNames = $this->uniqueSheetNames(array_column($sheets, 'name'));

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml(count($sheets)));
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml($sheetNames));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml(count($sheets)));

        foreach ($sheets as $index => $sheet) {
            $zip->addFromString(
                'xl/worksheets/sheet' . ($index + 1) . '.xml',
                $this->sheetXml($sheet['headers'], $sheet['rows'])
            );
        }

        $zip->close();

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function sheetXml(array $headers, array $rows): string
    {
        $allRows = array_merge([$headers], $rows);
        $columnCount = max(array_map('count', $allRows));
        $lastColumn = $this->columnName($columnCount);
        $lastRow = max(1, count($allRows));

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:' . $lastColumn . $lastRow . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . '<cols>';

        for ($i = 1; $i <= $columnCount; $i++) {
            $width = in_array($i, [2, 3, 4, 7, 8], true) ? 24 : 15;
            $xml .= '<col min="' . $i . '" max="' . $i . '" width="' . $width . '" customWidth="1"/>';
        }

        $xml .= '</cols><sheetData>';

        foreach ($allRows as $rowIndex => $row) {
            $rowNo = $rowIndex + 1;
            $xml .= '<row r="' . $rowNo . '">';
            foreach (array_values($row) as $colIndex => $value) {
                $cell = $this->columnName($colIndex + 1) . $rowNo;
                if (is_int($value) || is_float($value)) {
                    $xml .= '<c r="' . $cell . '"><v>' . $this->escape((string) $value) . '</v></c>';
                } else {
                    $xml .= '<c r="' . $cell . '" t="inlineStr"><is><t>' . $this->escape((string) $value) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }

        return $xml . '</sheetData><pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/></worksheet>';
    }

    private function uniqueSheetNames(array $names): array
    {
        $used = [];

        return array_map(function (string $name) use (&$used) {
            $base = trim(preg_replace('/[\[\]\:\*\?\/\\\\]/', ' ', $name)) ?: 'Sheet';
            $base = substr($base, 0, 31);
            $candidate = $base;
            $i = 2;

            while (isset($used[strtolower($candidate)])) {
                $suffix = ' ' . $i++;
                $candidate = substr($base, 0, 31 - strlen($suffix)) . $suffix;
            }

            $used[strtolower($candidate)] = true;
            return $candidate;
        }, $names);
    }

    private function contentTypesXml(int $sheetCount): string
    {
        $overrides = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . $overrides
            . '</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(array $sheetNames): string
    {
        $sheets = '';
        foreach ($sheetNames as $index => $name) {
            $id = $index + 1;
            $sheets .= '<sheet name="' . $this->escape($name) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheets . '</sheets></workbook>';
    }

    private function workbookRelsXml(int $sheetCount): string
    {
        $rels = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels
            . '</Relationships>';
    }

    private function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
