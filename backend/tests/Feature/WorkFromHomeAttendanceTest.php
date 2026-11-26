<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Attendance\MonthlyAttendanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Applied Work From Home days in the attendance log and the attendance reports, with or without punches. */
class WorkFromHomeAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;
    private User $user;
    private Employee $employee;
    private Employee $colleague;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-12-10 12:00:00');
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        $department = Department::factory()->create()->id;
        $this->hr = User::factory()->create();
        $this->hr->assignRole('hr_manager');
        $this->user = User::factory()->create();
        $this->user->assignRole('employee');
        $this->employee = Employee::factory()->create(['user_id' => $this->user->id, 'department_id' => $department, 'status' => 'active']);
        $this->colleague = Employee::factory()->create(['department_id' => $department, 'status' => 'active']);
    }

    private function wfh(Employee $employee, string $from, string $to, string $status = 'approved'): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id' => $employee->id, 'leave_type_id' => LeaveType::where('code', 'WFH')->value('id'),
            'start_date' => $from, 'end_date' => $to, 'total_days' => 1, 'status' => $status, 'reason' => 'Working from home',
        ]);
    }

    private function log(Employee $employee, string $date): AttendanceLog
    {
        return AttendanceLog::create([
            'employee_id' => $employee->id, 'date' => $date, 'check_in' => '08:00:00', 'check_out' => '16:00:00',
            'total_minutes' => 480, 'status' => 'present', 'source' => 'api',
        ]);
    }

    private function attendance(User $as, array $params = []): array
    {
        return $this->actingAs($as, 'sanctum')->getJson('/api/v1/attendance/report?' . http_build_query($params))->assertOk()->json();
    }

    /** "employeeId date status" per row, in order. */
    private function summary(array $response): array
    {
        return array_map(fn ($r) => $r['employee_id'] . ' ' . substr($r['date'], 0, 10) . ' ' . $r['status'], $response['data']);
    }

    public function test_a_day_without_punches_is_listed_with_its_approval_stage(): void
    {
        $this->wfh($this->employee, '2026-12-06', '2026-12-06', 'pending');
        $this->wfh($this->employee, '2026-12-07', '2026-12-07', 'approved');

        $rows = $this->attendance($this->user)['data'];

        $this->assertSame(['2026-12-07', '2026-12-06'], array_map(fn ($r) => substr($r['date'], 0, 10), $rows));
        $this->assertSame(['work_from_home', 'work_from_home'], array_column($rows, 'status'));
        $this->assertSame(['completed', 'pending_manager'], array_map(fn ($r) => $r['wfh']['status'], $rows));
        $this->assertSame($this->employee->id, $rows[0]['employee']['id']);
    }

    public function test_a_punched_day_keeps_its_record_and_is_not_listed_twice(): void
    {
        $this->wfh($this->employee, '2026-12-06', '2026-12-06');
        $log = $this->log($this->employee, '2026-12-06');

        $rows = $this->attendance($this->user)['data'];

        $this->assertCount(1, $rows);
        $this->assertSame($log->id, $rows[0]['id']);
        $this->assertSame('present', $rows[0]['status']);
        $this->assertSame('completed', $rows[0]['wfh']['status']);
    }

    public function test_rejected_cancelled_weekend_and_future_days_are_left_out(): void
    {
        $this->wfh($this->employee, '2026-12-01', '2026-12-01', 'rejected');
        $this->wfh($this->employee, '2026-12-02', '2026-12-02', 'cancelled');
        $this->wfh($this->employee, '2026-12-03', '2026-12-06'); // Thursday to Sunday
        $this->wfh($this->employee, '2026-12-14', '2026-12-14'); // after "today"

        $id = $this->employee->id;
        $this->assertSame(["{$id} 2026-12-06 work_from_home", "{$id} 2026-12-03 work_from_home"], $this->summary($this->attendance($this->user)));

        $december = $this->summary($this->attendance($this->user, ['date_from' => '2026-12-01', 'date_to' => '2026-12-31']));
        $this->assertContains("{$id} 2026-12-14 work_from_home", $december);
    }

    public function test_employees_only_see_their_own_days_and_hr_sees_everyones(): void
    {
        $this->wfh($this->employee, '2026-12-06', '2026-12-06');
        $this->wfh($this->colleague, '2026-12-06', '2026-12-06');

        $this->assertSame(["{$this->employee->id} 2026-12-06 work_from_home"], $this->summary($this->attendance($this->user)));
        $this->assertCount(2, $this->attendance($this->hr)['data']);
        $this->assertCount(1, $this->attendance($this->hr, ['employee_id' => $this->colleague->id])['data']);
    }

    public function test_a_manager_sees_the_team_but_not_their_own_days_in_the_team_list(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('department_manager');
        $record = Employee::factory()->create(['user_id' => $manager->id, 'department_id' => Department::factory()->create()->id]);
        $this->employee->update(['manager_id' => $record->id]);
        $this->wfh($this->employee, '2026-12-06', '2026-12-06');
        $this->wfh($this->colleague, '2026-12-06', '2026-12-06');
        $this->wfh($record, '2026-12-06', '2026-12-06');

        $this->assertSame(["{$this->employee->id} 2026-12-06 work_from_home"], $this->summary($this->attendance($manager, ['scope' => 'team'])));
        $this->assertSame(["{$record->id} 2026-12-06 work_from_home"], $this->summary($this->attendance($manager, ['scope' => 'mine'])));
    }

    public function test_the_status_filter(): void
    {
        $this->wfh($this->employee, '2026-12-06', '2026-12-07');
        $this->log($this->employee, '2026-12-07');
        $this->log($this->employee, '2026-12-08');

        $id = $this->employee->id;
        $this->assertSame(["{$id} 2026-12-07 present", "{$id} 2026-12-06 work_from_home"], $this->summary($this->attendance($this->user, ['status' => 'work_from_home'])));
        $this->assertSame(["{$id} 2026-12-08 present", "{$id} 2026-12-07 present"], $this->summary($this->attendance($this->user, ['status' => 'present'])));
    }

    public function test_pages_hold_every_row_once_in_date_order(): void
    {
        // 24 punched days and 4 Work From Home days spread through them.
        for ($day = 1; $day <= 30; $day++) {
            $date = sprintf('2026-11-%02d', $day);
            if (in_array($day, [1, 11, 19, 30], true)) {
                $this->wfh($this->employee, $date, $date);
            } elseif (!in_array($day, [6, 13], true)) {
                $this->log($this->employee, $date);
            }
        }

        $all = [];
        foreach ([1, 2, 3] as $page) {
            $response = $this->attendance($this->user, ['per_page' => 10, 'page' => $page]);
            $this->assertSame(28, $response['total']);
            $this->assertSame(3, $response['last_page']);
            $this->assertCount($page === 3 ? 8 : 10, $response['data']);
            $all = array_merge($all, array_map(fn ($r) => substr($r['date'], 0, 10), $response['data']));
        }

        $sorted = $all;
        rsort($sorted);
        $this->assertSame($sorted, $all);
        $this->assertCount(28, array_unique($all));
    }

    public function test_the_attendance_report_lists_applied_days(): void
    {
        $this->wfh($this->employee, '2026-12-06', '2026-12-06', 'manager_approved');
        $this->wfh($this->employee, '2026-11-30', '2026-11-30');
        $this->log($this->employee, '2026-12-07');

        $rows = $this->actingAs($this->hr, 'sanctum')->getJson('/api/v1/reports/attendance?month=2026-12')->assertOk()->json('data');

        $this->assertSame(['07 Dec 2026', '06 Dec 2026'], array_column($rows, 'date'));
        $this->assertSame('work_from_home', $rows[1]['status']);
        $this->assertStringContainsString('(Awaiting HR approval): Working from home', $rows[1]['wfh']);

        $rows = $this->getJson('/api/v1/reports/attendance?month=2026-12&status=work_from_home')->assertOk()->json('data');
        $this->assertSame(['06 Dec 2026'], array_column($rows, 'date'));
    }

    /** Rows of one worksheet of the monthly workbook (1 = summary, then one per employee by employee code). */
    private function workbookRows(string $from, string $to, int $sheet): array
    {
        $response = app(MonthlyAttendanceReportService::class)->download(new \Illuminate\Http\Request(compact('from', 'to')));
        $path = $response->getFile()->getPathname();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        $xml = simplexml_load_string($zip->getFromName("xl/worksheets/sheet{$sheet}.xml"));
        $zip->close();
        unlink($path);

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $cells[] = isset($cell->is) ? (string) $cell->is->t : (int) $cell->v;
            }
            $rows[] = $cells;
        }

        return array_slice($rows, 1); // without the headings
    }

    public function test_the_monthly_workbook_counts_approved_days_as_full_days(): void
    {
        $this->colleague->delete();
        Employee::whereKeyNot($this->employee->id)->update(['status' => 'terminated']);
        $this->wfh($this->employee, '2026-12-03', '2026-12-06');                      // approved, Thursday to Sunday
        $this->wfh($this->employee, '2026-12-07', '2026-12-07', 'pending');
        $this->wfh($this->employee, '2026-12-08', '2026-12-08', 'manager_approved');
        $this->wfh($this->employee, '2026-12-10', '2026-12-10');                      // approved, with punches
        $this->log($this->employee, '2026-12-10')->update(['check_out' => '13:00:00', 'total_minutes' => 300]);

        $days = array_map(
            fn ($row) => $row[0] . ' ' . $row[4] . ' ' . $row[6],
            $this->workbookRows('2026-12-03', '2026-12-10', 2)
        );

        $this->assertSame([
            '2026-12-03 480 Work From Home',
            '2026-12-04 0 Weekend',
            '2026-12-05 0 Weekend',
            '2026-12-06 480 Work From Home',
            '2026-12-07 0 Work From Home (pending)',
            '2026-12-08 0 Work From Home (pending)',
            '2026-12-09 0 Absent',
            '2026-12-10 300 Present',
        ], $days);

        $summary = $this->workbookRows('2026-12-03', '2026-12-10', 1)[0];
        $this->assertSame(6 * 480, $summary[2]);         // required
        $this->assertSame(2 * 480 + 300, $summary[3]);   // worked
        $this->assertSame(2 * 480 + 300 - 6 * 480, $summary[4]);
    }
}
