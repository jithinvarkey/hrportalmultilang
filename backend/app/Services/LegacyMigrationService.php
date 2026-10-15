<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Policy;
use App\Models\PolicyCategory;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class LegacyMigrationService
{
    private const MODULE_ORDER = ['departments', 'job_positions', 'employees', 'employee_managers', 'leave_records', 'loan_records', 'documents'];
    private array $departmentAliases = [];
    private array $unitAliases = [];
    private ?string $skipReason = null;
    private ?string $documentSourceFolder = null;

    public function migrate(UploadedFile $file, string $scope = 'all', bool $dryRun = false, ?string $sourceFolder = null, ?int $legacyCategoryId = null): array
    {
        $this->departmentAliases = [];
        $this->unitAliases = [];
        $this->documentSourceFolder = $this->resolveSourceFolder($sourceFolder, $scope);
        $rowsByModule = $this->readFile($file, $scope);
        $summary = [];

        foreach (self::MODULE_ORDER as $module) {
            if ($scope !== 'all' && $scope !== $module) {
                continue;
            }

            $rows = $rowsByModule[$module] ?? [];
            if ($module === 'documents' && $legacyCategoryId !== null) {
                $rows = array_values(array_filter(
                    $rows,
                    fn (array $row) => (int) ($row['category_id'] ?? 0) === $legacyCategoryId
                ));
            }
            $moduleSummary = [
                'module' => $module,
                'label' => $this->moduleLabel($module),
                'processed' => count($rows),
                'success' => 0,
                'skipped' => 0,
                'failed' => 0,
                'errors' => [],
                'skipped_rows' => [],
            ];

            foreach ($rows as $index => $row) {
                if ($index % 100 === 0) {
                    @set_time_limit(120);
                }

                $line = $row['_line'] ?? ($index + 2);
                $this->skipReason = null;
                try {
                    if ($this->rowIsEmpty($row)) {
                        $moduleSummary['skipped']++;
                        $moduleSummary['skipped_rows'][] = [
                            'row' => $line,
                            'message' => 'Empty row.',
                            'data' => $this->publicRow($row),
                        ];
                        continue;
                    }

                    $result = 'success';
                    if ($dryRun) {
                        $this->validateRow($module, $row);
                        if ($module === 'employee_managers') {
                            $result = $this->employeeManager($row, true);
                        }
                    } else {
                        $result = DB::transaction(fn () => $this->migrateRow($module, $row));
                    }

                    if ($result === 'skipped') {
                        $moduleSummary['skipped']++;
                        $skippedRow = [
                            'row' => $line,
                            'message' => $this->skipReason ?: 'Skipped because no migration change was needed.',
                            'data' => $this->publicRow($row),
                        ];
                        $moduleSummary['skipped_rows'][] = $skippedRow;
                        Log::warning('Legacy migration row skipped.', [
                            'module' => $module,
                            'row' => $line,
                            'reason' => $skippedRow['message'],
                            'data' => $skippedRow['data'],
                        ]);
                    } else {
                        $moduleSummary['success']++;
                    }
                } catch (\Throwable $e) {
                    $moduleSummary['failed']++;
                    $moduleSummary['errors'][] = [
                        'row' => $line,
                        'message' => $e->getMessage(),
                        'data' => $this->publicRow($row),
                    ];
                    Log::warning('Legacy migration row failed.', [
                        'module' => $module,
                        'row' => $line,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $summary[] = $moduleSummary;
        }

        return [
            'dry_run' => $dryRun,
            'file' => $file->getClientOriginalName(),
            'scope' => $scope,
            'legacy_category_id' => $legacyCategoryId,
            'modules' => $summary,
            'totals' => [
                'processed' => array_sum(array_column($summary, 'processed')),
                'success' => array_sum(array_column($summary, 'success')),
                'skipped' => array_sum(array_column($summary, 'skipped')),
                'failed' => array_sum(array_column($summary, 'failed')),
            ],
        ];
    }

    private function readFile(UploadedFile $file, string $scope): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === 'csv') {
            return $this->groupRows($this->readCsv($file->getRealPath()), $scope);
        }

        if ($extension === 'xlsx') {
            return $this->readXlsx($file->getRealPath(), $scope);
        }

        throw new \RuntimeException('Unsupported file type. Upload CSV or Excel file.');
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new \RuntimeException('Unable to read uploaded CSV file.');
        }

        $headers = null;
        $rows = [];
        $line = 0;
        while (($data = fgetcsv($handle)) !== false) {
            $line++;
            if ($headers === null) {
                $headers = array_map(fn ($h) => $this->key($h), $data);
                continue;
            }
            $row = [];
            foreach ($headers as $i => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = trim((string) ($data[$i] ?? ''));
            }
            $row['_line'] = $line;
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private function readXlsx(string $path, string $scope): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('Excel import requires PHP ZipArchive. CSV upload is still supported.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Unable to read uploaded Excel file.');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $sheets = $this->workbookSheets($zip);
        $rowsByModule = array_fill_keys(self::MODULE_ORDER, []);

        foreach ($sheets as $sheet) {
            $module = $this->moduleFromName($sheet['name']);
            if (!$module || ($scope !== 'all' && $scope !== $module)) {
                continue;
            }
            $rowsByModule[$module] = array_merge(
                $rowsByModule[$module],
                $this->readWorksheet($zip, $sheet['path'], $sharedStrings)
            );
        }

        $zip->close();

        return $rowsByModule;
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (!$xml) {
            return [];
        }
        $doc = simplexml_load_string($xml);
        $strings = [];
        foreach ($doc->si ?? [] as $si) {
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
            } else {
                $text = '';
                foreach ($si->r ?? [] as $run) {
                    $text .= (string) $run->t;
                }
                $strings[] = $text;
            }
        }
        return $strings;
    }

    private function workbookSheets(ZipArchive $zip): array
    {
        $workbook = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $rels = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
        $targets = [];
        foreach ($rels->Relationship ?? [] as $rel) {
            $targets[(string) $rel['Id']] = 'xl/' . ltrim((string) $rel['Target'], '/');
        }

        $sheets = [];
        foreach ($workbook->sheets->sheet ?? [] as $sheet) {
            $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $rid = (string) $attrs['id'];
            if (!empty($targets[$rid])) {
                $sheets[] = ['name' => (string) $sheet['name'], 'path' => $targets[$rid]];
            }
        }
        return $sheets;
    }

    private function readWorksheet(ZipArchive $zip, string $path, array $sharedStrings): array
    {
        $xml = $zip->getFromName($path);
        if (!$xml) {
            return [];
        }

        $sheet = simplexml_load_string($xml);
        $headers = [];
        $rows = [];
        foreach ($sheet->sheetData->row ?? [] as $rowNode) {
            $line = (int) $rowNode['r'];
            $values = [];
            foreach ($rowNode->c ?? [] as $cell) {
                $ref = (string) $cell['r'];
                $col = $this->columnIndex($ref);
                $values[$col] = $this->cellValue($cell, $sharedStrings);
            }

            if (!$headers) {
                ksort($values);
                $headers = array_map(fn ($h) => $this->key($h), array_values($values));
                continue;
            }

            $item = [];
            foreach ($headers as $i => $header) {
                if ($header === '') {
                    continue;
                }
                $item[$header] = trim((string) ($values[$i] ?? ''));
            }
            $item['_line'] = $line;
            $rows[] = $item;
        }

        return $rows;
    }

    private function cellValue(\SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) $cell['t'];
        if ($type === 's') {
            return (string) ($sharedStrings[(int) $cell->v] ?? '');
        }
        if ($type === 'inlineStr') {
            return (string) ($cell->is->t ?? '');
        }
        return (string) ($cell->v ?? '');
    }

    private function columnIndex(string $ref): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
        $number = 0;
        foreach (str_split($letters) as $letter) {
            $number = ($number * 26) + (ord($letter) - 64);
        }
        return max(0, $number - 1);
    }

    private function groupRows(array $rows, string $scope): array
    {
        $rowsByModule = array_fill_keys(self::MODULE_ORDER, []);
        foreach ($rows as $row) {
            $module = $scope === 'all'
                ? $this->moduleFromName((string) ($row['module'] ?? ''))
                : $scope;
            if ($module) {
                $rowsByModule[$module][] = $row;
            }
        }
        return $rowsByModule;
    }

    private function migrateRow(string $module, array $row): string
    {
        $this->validateRow($module, $row);
        return match ($module) {
            'departments' => $this->department($row),
            'job_positions' => $this->jobPosition($row),
            'employees' => $this->employee($row),
            'employee_managers' => $this->employeeManager($row),
            'leave_records' => $this->leaveRecord($row),
            'loan_records' => $this->loanRecord($row),
            'documents' => $this->document($row),
            default => throw new \InvalidArgumentException('Unknown migration module.'),
        };
    }

    private function validateRow(string $module, array $row): void
    {
        $row = $this->normalizeLegacyAliases($row);
        $required = match ($module) {
            'departments' => ['name'],
            'job_positions' => ['title'],
            'employees' => ['first_name', 'last_name', 'email', 'hire_date'],
            'employee_managers' => [],
            'leave_records' => ['employee_code', 'leave_type', 'start_date'],
            'loan_records' => ['employee_code', 'loan_type', 'amount'],
            'documents' => ['document_name', 'file_name'],
            default => [],
        };
        foreach ($required as $field) {
            if (($row[$field] ?? '') === '') {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if ($module === 'documents') {
            $file = $this->legacyDocumentFile((string) ($row['file_name'] ?? ''));
            $this->documentSourcePath($file['new_name']);
        }
    }

    private function department(array $row): string
    {
        $row = $this->normalizeLegacyAliases($row);
        $name = trim($row['name']);
        $legacyCode = trim((string) ($row['code'] ?? ''));
        $unitId = trim((string) ($row['unitid'] ?? $row['unit_id'] ?? ''));
        $branch = trim((string) ($row['branch'] ?? $row['branch_name'] ?? $unitId));
        $this->findOrCreateUnit($unitId, $row['unit_name'] ?? $row['branch_name'] ?? null);
        $description = $this->departmentDescription(
            trim((string) ($row['description'] ?? '')),
            $legacyCode,
            $branch
        );

        $department = Department::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();

        if (!$department) {
            $code = $legacyCode ?: Str::upper(Str::slug($name, '_'));
            $department = Department::updateOrCreate(
                ['code' => $this->uniqueDepartmentCode($code)],
                [
                    'name' => $name,
                    'description' => $description ?: null,
                    'headcount_budget' => $this->departmentHeadcountBudget($row['headcount_budget'] ?? null),
                    'is_active' => $this->bool($row['is_active'] ?? true),
                ]
            );
        } else {
            $department->update([
                'description' => $this->mergeDepartmentDescription($department->description, $description),
                'headcount_budget' => $department->headcount_budget ?? $this->departmentHeadcountBudget($row['headcount_budget'] ?? null),
                'is_active' => $department->is_active || $this->bool($row['is_active'] ?? true),
            ]);
        }

        $this->rememberDepartmentAlias($department, $legacyCode, $name);
        return 'success';
    }

    private function jobPosition(array $row): string
    {
        $row = $this->normalizeLegacyAliases($row);
        $department = $this->findDepartment($row['department_code'] ?? null, $row['department'] ?? null);
        Designation::updateOrCreate(
            ['title' => $row['title'], 'department_id' => $department?->id],
            [
                'level' => ($row['level'] ?? '') ?: 'staff',
                'min_salary' => $this->nullableDecimal($row['min_salary'] ?? null),
                'max_salary' => $this->nullableDecimal($row['max_salary'] ?? null),
                'is_active' => $this->bool($row['is_active'] ?? true),
            ]
        );
        return 'success';
    }

    private function employee(array $row): string
    {
        $row = $this->normalizeLegacyAliases($row);
        $email = strtolower($row['email']);
        $legacyPasswordMd5 = $this->legacyPasswordMd5($row['legacy_password_md5'] ?? $row['password'] ?? null);
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'password' => $legacyPasswordMd5
                    ? Hash::make(Str::random(40))
                    : Hash::make(($row['password'] ?? '') ?: 'Password@123'),
                'legacy_password_md5' => $legacyPasswordMd5,
            ]
        );
        if ($legacyPasswordMd5 && !$user->legacy_password_md5) {
            $user->forceFill(['legacy_password_md5' => $legacyPasswordMd5])->save();
        }
        if (!$user->hasRole('employee')) {
            $user->assignRole('employee');
        }

        $department = $this->findDepartment($row['department_code'] ?? null, $row['department'] ?? null);
        $unit = $this->findOrCreateUnit($row['unitid'] ?? $row['unit_id'] ?? null, $row['unit_name'] ?? $row['branch_name'] ?? null);
        $designation = $this->findDesignation($row['job_position'] ?? $row['designation'] ?? $row['title'] ?? null, $department?->id);
        $code = $this->employeeCode($row['employee_code'] ?? null) ?: $this->nextEmployeeCode();
        $isActive = $this->bool($row['is_active'] ?? true);

        Employee::updateOrCreate(
            ['email' => $email],
            [
                'user_id' => $user->id,
                'employee_code' => $code,
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'phone' => $row['phone'] ?? null,
                'dob' => $this->date($row['dob'] ?? null),
                'gender' => $this->enum($row['gender'] ?? null, ['male', 'female', 'other']),
                'marital_status' => $this->enum($row['marital_status'] ?? null, ['single', 'married', 'divorced', 'widowed']),
                'hire_date' => $this->date($row['hire_date']) ?: now()->toDateString(),
                'confirmation_date' => $this->date($row['confirmation_date'] ?? null),
                'termination_date' => $this->date($row['termination_date'] ?? null),
                'employment_type' => $this->enum($row['employment_type'] ?? null, ['full_time', 'part_time', 'contract', 'intern']) ?: 'full_time',
                'status' => $this->enum($row['status'] ?? null, ['active', 'inactive', 'terminated', 'on_leave', 'probation']) ?: ($isActive ? 'active' : 'inactive'),
                'salary' => $this->nullableDecimal($row['salary'] ?? null) ?? 0,
                'department_id' => $department?->id,
                'unit_id' => $unit?->id,
                'designation_id' => $designation?->id,
                'address' => $row['address'] ?? null,
                'city' => $row['city'] ?? null,
                'country' => $row['country'] ?? null,
                'national_id' => $row['national_id'] ?? null,
                'bank_name' => $row['bank_name'] ?? null,
                'bank_account' => $row['bank_account'] ?? null,
                'emergency_contact_name' => $row['emergency_contact_name'] ?? null,
                'emergency_contact_phone' => $row['emergency_contact_phone'] ?? null,
            ]
        );
        return 'success';
    }

    private function employeeManager(array $row, bool $dryRun = false): string
    {
        $row = $this->normalizeLegacyAliases($row);
        $employee = $this->findEmployeeForManagerMigration(
            $this->firstLegacyValue($row, ['employee_code', 'empnum', 'empcode', 'employee_number', 'employee_no']),
            $this->firstLegacyValue($row, ['email', 'emailaddress', 'email_address', 'employee_email']),
            'employee'
        );

        $managerCode = $this->firstLegacyValue($row, [
            'manager_empnum',
            'manager_employee_code',
            'manager_empcode',
            'manager_employee_number',
            'manager_employee_no',
            'direct_manager_empnum',
            'direct_manager_employee_code',
            'directmanager_empnum',
            'directmanagerempnum',
            'reporting_manager_empnum',
            'reportingmanager_empnum',
        ]);
        $managerEmail = $this->firstLegacyValue($row, [
            'manager_email',
            'manager_emailaddress',
            'manager_email_address',
            'direct_manager_email',
            'direct_manager_emailaddress',
            'directmanager_email',
            'directmanageremail',
            'reporting_manager_email',
            'reportingmanager_email',
        ]);
        $managerName = $this->firstLegacyValue($row, [
            'manager_name',
            'manager_full_name',
            'direct_manager_name',
            'direct_manager_full_name',
            'directmanager_name',
            'directmanagername',
            'reporting_manager_name',
            'reportingmanager_name',
            'reportingmanagername',
        ]);

        if (!$managerCode && !$managerEmail && !$managerName) {
            return 'skipped';
        }

        $manager = $this->findEmployeeForManagerMigration($managerCode, $managerEmail, 'manager', $managerName, true);
        if (!$manager) {
            return 'skipped';
        }

        if ((int) $employee->id === (int) $manager->id) {
            throw new \InvalidArgumentException('Employee cannot be their own manager.');
        }

        if ($this->managerAssignmentCreatesCycle($employee, $manager)) {
            throw new \InvalidArgumentException('Manager assignment would create a reporting cycle.');
        }

        if ((int) $employee->manager_id === (int) $manager->id) {
            return 'skipped';
        }

        if (!$dryRun) {
            $employee->forceFill(['manager_id' => $manager->id])->save();
        }

        return 'success';
    }

    private function leaveRecord(array $row): string
    {
        $row = $this->normalizeLegacyAliases($row);
        $employeeCode = $this->cleanLegacyValue($row['employee_code'] ?? null);
        $employeeEmail = $this->cleanLegacyValue($row['email'] ?? null);
        $employee = $this->employeeByCodeOrEmail($employeeCode, $employeeEmail);
        if (!$employee) {
            $identifier = $employeeEmail ? "{$employeeCode} / {$employeeEmail}" : $employeeCode;
            $this->skipReason = "Employee not found for code/email [{$identifier}].";
            Log::warning('Legacy leave record skipped: employee not found.', [
                'employee_code' => $employeeCode,
                'employee_email' => $employeeEmail,
                'leave_type' => $this->cleanLegacyValue($row['leave_type'] ?? null),
                'start_date' => $this->cleanLegacyValue($row['start_date'] ?? null),
            ]);

            return 'skipped';
        }

        $leaveType = $this->findOrCreateLeaveType($row['leave_type']);
        $startDate = $this->legacyDate($row['start_date'] ?? null);
        $endDate = $this->legacyDate($row['end_date'] ?? null);

        if (!$startDate && $endDate) {
            $startDate = $endDate;
        }
        if (!$startDate) {
            throw new \InvalidArgumentException('Missing from/start date.');
        }
        if (!$endDate || strcmp($endDate, $startDate) < 0) {
            $endDate = $startDate;
        }

        $startTime = $this->legacyTime($row['start_time'] ?? $row['from_time'] ?? null);
        $endTime = $this->legacyTime($row['end_time'] ?? $row['to_time'] ?? null);
        $totalDays = $this->nullableDecimal($row['total_days'] ?? null);
        if ($totalDays === null) {
            $totalDays = ((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;
        }

        $leaveDay = strtolower((string) ($row['leaveday'] ?? $row['leave_day'] ?? ''));
        $isHalfDay = str_contains($leaveDay, 'half') || (float) $totalDays === 0.5;
        $managerStatus = $this->key($row['manager_status'] ?? $row['status'] ?? null);
        $hrStatus = $this->key($row['hr_status'] ?? null);
        $status = $this->leaveMigrationStatus($row['manager_status'] ?? $row['status'] ?? null, $row['hr_status'] ?? null);
        $comments = $this->cleanLegacyValue($row['approver_comments'] ?? $row['comments'] ?? null);
        $reason = $this->cleanLegacyValue($row['reason'] ?? null) ?: 'Legacy migration';
        $createdAt = $this->legacyDateTime($row['created_at'] ?? $row['createddate'] ?? null) ?: now();
        $updatedAt = $this->legacyDateTime($row['updated_at'] ?? $row['modifieddate'] ?? null) ?: $createdAt;
        $ticketCount = (int) ($this->nullableDecimal($row['ticket_count'] ?? null) ?? 0);

        $lookup = [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => $startDate,
            'start_time' => $startTime,
            'end_date' => $endDate,
            'end_time' => $endTime,
            'total_days' => (float) $totalDays,
            'reason' => $reason,
        ];

        $values = [
            ...$lookup,
            'total_hours' => $this->legacyHours($startTime, $endTime),
            'is_half_day' => $isHalfDay,
            'half_day_period' => $isHalfDay && str_contains($leaveDay, 'second') ? 'afternoon' : ($isHalfDay ? 'morning' : null),
            'requires_exit_reentry' => $this->bool($row['exit_entry_flag'] ?? $row['requires_exit_reentry'] ?? false),
            'requires_ticket' => $ticketCount > 0,
            'ticket_year' => $ticketCount > 0 ? (int) substr($startDate, 0, 4) : null,
            'ticket_count' => $ticketCount,
            'status' => $status,
            'rejection_reason' => $status === 'rejected' ? $comments : null,
            'manager_approved_at' => $managerStatus === 'approved' ? $updatedAt : null,
            'manager_notes' => $comments,
            'hr_notes' => $this->cleanLegacyValue($row['hr_status'] ?? null),
            'rejected_stage' => $status === 'rejected' ? ($managerStatus === 'rejected' ? 'manager' : 'hr') : null,
            'approved_at' => $hrStatus === 'approved' ? $updatedAt : null,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];

        $leave = LeaveRequest::where($lookup)->first();
        if (!$leave) {
            $leave = new LeaveRequest();
        }
        $leave->forceFill($values)->save();

        return 'success';
    }

    private function loanRecord(array $row): string
    {
        $row = $this->normalizeLegacyAliases($row);
        $employee = $this->employeeByCodeOrEmail($row['employee_code'] ?? null, $row['email'] ?? null);
        if (!$employee) {
            $employeeCode = $this->cleanLegacyValue($row['employee_code'] ?? null);
            $employeeEmail = $this->cleanLegacyValue($row['email'] ?? null);
            $identifier = $employeeEmail ? "{$employeeCode} / {$employeeEmail}" : $employeeCode;
            $this->skipReason = "Employee not found for code/email [{$identifier}].";
            Log::warning('Legacy loan record skipped: employee not found.', [
                'employee_code' => $employeeCode,
                'employee_email' => $employeeEmail,
                'loan_type' => $this->cleanLegacyValue($row['loan_type'] ?? null),
                'loan_id' => $this->cleanLegacyValue($row['loan_id'] ?? null),
            ]);

            return 'skipped';
        }
        $amount = $this->nullableDecimal($row['amount']) ?? 0;
        $installments = max(1, (int) ($row['installments'] ?? $row['installmentnumber'] ?? 1));
        $emiAmount = $this->nullableDecimal($row['emi_amount'] ?? null);
        $emiDate = $this->date($row['emi_date'] ?? null);
        $reference = $this->legacyLoanReference($row);
        $loanType = LoanType::firstOrCreate(
            ['code' => Str::upper(Str::slug($row['loan_type'], '_'))],
            ['name' => $row['loan_type'], 'max_amount' => 0, 'max_installments' => max(12, $installments), 'is_active' => true]
        );

        $loan = Loan::updateOrCreate(
            ['reference' => $reference],
            [
                'employee_id' => $employee->id,
                'loan_type_id' => $loanType->id,
                'requested_amount' => $amount,
                'approved_amount' => $this->nullableDecimal($row['approved_amount'] ?? null) ?? $amount,
                'installments' => $installments,
                'monthly_installment' => $emiAmount ?: round($amount / $installments, 2),
                'purpose' => ($row['purpose'] ?? '') ?: 'Legacy migration',
                'notes' => $row['notes'] ?? null,
                'status' => $this->legacyLoanStatus($row),
                'disbursed_date' => $this->date($row['disbursed_date'] ?? null),
                'first_installment_date' => $this->date($row['first_installment_date'] ?? null) ?: $emiDate,
                'total_paid' => $this->nullableDecimal($row['total_paid'] ?? null) ?? 0,
                'balance_remaining' => $this->nullableDecimal($row['balance_remaining'] ?? null) ?? $amount,
            ]
        );

        if ($emiDate && $emiAmount !== null) {
            $installment = LoanInstallment::updateOrCreate(
                [
                    'loan_id' => $loan->id,
                    'due_date' => $emiDate,
                ],
                [
                    'installment_no' => 1,
                    'amount' => $emiAmount,
                    'paid_amount' => $this->legacyInstallmentIsPaid($row, $emiDate) ? $emiAmount : 0,
                    'status' => $this->legacyInstallmentStatus($row, $emiDate),
                    'paid_date' => $this->legacyInstallmentIsPaid($row, $emiDate) ? $emiDate : null,
                    'notes' => $this->legacyLoanInstallmentNotes($row),
                ]
            );

            $this->renumberLoanInstallments($loan);
            $this->refreshLoanTotals($loan);
        }

        return 'success';
    }

    private function document(array $row): string
    {
        $row = $this->normalizeLegacyAliases($row);
        $legacyId = $this->nullableInt($row['id'] ?? null);
        $file = $this->legacyDocumentFile((string) $row['file_name']);
        $source = $this->documentSourcePath($file['new_name']);
        $originalName = $file['original_name'];
        $title = trim((string) $row['document_name']);

        if ($legacyId && Policy::where('legacy_document_id', $legacyId)->exists()) {
            $this->skipReason = "Legacy document {$legacyId} has already been migrated.";
            return 'skipped';
        }

        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $storedName = Str::random(40) . ($extension ? '.' . strtolower($extension) : '');
        $destination = "policies/{$storedName}";
        $stream = fopen($source, 'rb');
        if (!$stream || !Storage::disk('local')->put($destination, $stream)) {
            if (is_resource($stream)) fclose($stream);
            throw new \RuntimeException("Unable to copy source file [{$originalName}] into document storage.");
        }
        fclose($stream);

        try {
            $legacyCategoryId = $this->nullableInt($row['category_id'] ?? null);
            $policy = new Policy([
                'legacy_document_id' => $legacyId,
                'category_id' => $legacyCategoryId
                    ? PolicyCategory::where('legacy_category_id', $legacyCategoryId)->value('id')
                    : null,
                'legacy_category_id' => $legacyCategoryId,
                'legacy_subcategory_id' => $this->nullableInt($row['subcategory_id'] ?? null),
                'title' => $title,
                'content' => $this->cleanLegacyValue($row['description'] ?? null),
                'version' => $this->cleanLegacyValue($row['document_version'] ?? null) ?: '1.0',
                'document_type' => $this->cleanLegacyValue($row['document_type'] ?? null),
                'requires_acknowledgement' => false,
                'mandatory' => false,
                'is_published' => $this->bool($row['isactive'] ?? false),
                'status' => $this->bool($row['isactive'] ?? false) ? 'approved' : 'draft',
                'attachment_path' => $destination,
                'attachment_name' => $originalName,
                'attachment_mime' => function_exists('mime_content_type') ? (mime_content_type($source) ?: null) : null,
                'attachment_size' => filesize($source) ?: null,
                'created_by' => auth()->id(),
                'legacy_created_by' => $this->nullableInt($row['createdby'] ?? null),
                'legacy_modified_by' => $this->nullableInt($row['modifiedby'] ?? null),
            ]);
            $policy->created_at = $this->legacyDateTime($row['createddate'] ?? null) ?: now();
            $policy->updated_at = $this->legacyDateTime($row['modifieddate'] ?? null) ?: $policy->created_at;
            $policy->save();
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($destination);
            throw $e;
        }

        if (!unlink($source)) {
            Storage::disk('local')->delete($destination);
            throw new \RuntimeException("Document was copied but the source file could not be removed: {$originalName}");
        }

        return 'success';
    }

    private function resolveSourceFolder(?string $folder, string $scope): ?string
    {
        $folder = trim((string) $folder);
        if ($folder === '') {
            if ($scope === 'documents') {
                throw new \InvalidArgumentException('Source document folder is required for document migration.');
            }
            return null;
        }

        $resolved = realpath($folder);
        if (!$resolved || !is_dir($resolved) || !is_readable($resolved) || !is_writable($resolved)) {
            throw new \InvalidArgumentException('Source document folder does not exist or is not readable and writable by the server.');
        }
        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    private function documentSourcePath(string $relativeName): string
    {
        if (!$this->documentSourceFolder) {
            throw new \InvalidArgumentException('Source document folder is required when the migration file contains Documents.');
        }
        $relativeName = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeName));
        if ($relativeName === '' || preg_match('/^(?:[A-Za-z]:|[\\\\\/])/', $relativeName)) {
            throw new \InvalidArgumentException('file_name must be a relative path inside the source document folder.');
        }
        $resolved = realpath($this->documentSourceFolder . DIRECTORY_SEPARATOR . $relativeName);
        $root = strtolower($this->documentSourceFolder . DIRECTORY_SEPARATOR);
        if (!$resolved || !is_file($resolved) || !str_starts_with(strtolower($resolved), $root)) {
            throw new \InvalidArgumentException("Source document not found inside the selected folder: {$relativeName}");
        }
        return $resolved;
    }

    private function legacyDocumentFile(string $json): array
    {
        $files = json_decode(trim($json), true);
        if (!is_array($files) || count($files) !== 1 || !is_array($files[0])) {
            throw new \InvalidArgumentException('file_name must contain one valid legacy file JSON object.');
        }
        $newName = trim((string) ($files[0]['new_name'] ?? ''));
        $originalName = trim((string) ($files[0]['original_name'] ?? ''));
        if ($newName === '') {
            throw new \InvalidArgumentException('The file_name JSON is missing new_name.');
        }
        return [
            'new_name' => $newName,
            'original_name' => $originalName !== '' ? basename($originalName) : basename($newName),
        ];
    }

    private function legacyLoanReference(array $row): string
    {
        $legacyLoanId = $this->cleanLegacyValue($row['loan_id'] ?? $row['loanid'] ?? null);
        if ($legacyLoanId) {
            return 'LEG-LOAN-' . Str::upper(Str::slug($legacyLoanId, '-'));
        }

        return ($this->cleanLegacyValue($row['reference'] ?? null)) ?: $this->nextLoanReference();
    }

    private function legacyLoanStatus(array $row): string
    {
        $explicit = $this->enum($row['status'] ?? null, ['pending_manager', 'pending_hr', 'pending_finance', 'approved', 'disbursed', 'completed', 'rejected', 'cancelled']);
        if ($explicit) {
            return $explicit;
        }

        $financeStatus = $this->key($row['financemanagerstatus'] ?? $row['finance_status'] ?? null);
        $managerStatus = $this->key($row['reportingmanagerstatus'] ?? $row['manager_status'] ?? null);

        if (in_array('rejected', [$financeStatus, $managerStatus], true)) {
            return 'rejected';
        }
        if (in_array('cancelled', [$financeStatus, $managerStatus], true) || in_array('canceled', [$financeStatus, $managerStatus], true)) {
            return 'cancelled';
        }
        if ($financeStatus === 'approved') {
            return 'disbursed';
        }
        if ($managerStatus === 'approved') {
            return 'pending_finance';
        }

        return 'pending_manager';
    }

    private function legacyInstallmentStatus(array $row, string $emiDate): string
    {
        if ($this->legacyInstallmentIsPaid($row, $emiDate)) {
            return 'paid';
        }

        return strtotime($emiDate) < strtotime(now()->toDateString()) ? 'overdue' : 'pending';
    }

    private function legacyInstallmentIsPaid(array $row, string $emiDate): bool
    {
        $financeStatus = $this->key($row['financemanagerstatus'] ?? $row['finance_status'] ?? null);
        $managerStatus = $this->key($row['reportingmanagerstatus'] ?? $row['manager_status'] ?? null);
        $paidStatus = $this->key($row['installment_status'] ?? $row['emi_status'] ?? $row['payment_status'] ?? null);

        if (in_array($paidStatus, ['paid', 'completed', 'settled'], true)) {
            return true;
        }
        if (in_array($paidStatus, ['pending', 'unpaid', 'overdue', 'skipped'], true)) {
            return false;
        }

        return $financeStatus === 'approved'
            && $managerStatus === 'approved'
            && strtotime($emiDate) <= strtotime(now()->toDateString());
    }

    private function legacyLoanInstallmentNotes(array $row): ?string
    {
        $parts = array_filter([
            $this->cleanLegacyValue($row['finance_manager_comment'] ?? null),
            $this->cleanLegacyValue($row['rep_manager_comment'] ?? null),
            $this->cleanLegacyValue($row['purpose'] ?? null),
        ]);

        return $parts ? implode("\n", array_unique($parts)) : null;
    }

    private function renumberLoanInstallments(Loan $loan): void
    {
        $loan->installments()
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->values()
            ->each(function (LoanInstallment $installment, int $index) {
                $number = $index + 1;
                if ((int) $installment->installment_no !== $number) {
                    $installment->forceFill(['installment_no' => $number])->save();
                }
            });
    }

    private function refreshLoanTotals(Loan $loan): void
    {
        $installments = LoanInstallment::where('loan_id', $loan->id)
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        $totalPaid = (float) $installments->sum('paid_amount');
        $paidCount = $installments->where('status', 'paid')->count();
        $skippedCount = $installments->where('status', 'skipped')->count();
        $approvedAmount = (float) ($loan->approved_amount ?: $loan->requested_amount);
        $balance = max(0, round($approvedAmount - $totalPaid, 2));
        $status = $loan->status;

        if (!in_array($status, ['rejected', 'cancelled'], true)) {
            $status = $balance <= 0 && $installments->count() > 0 ? 'completed' : $status;
        }

        $loan->forceFill([
            'installments' => max((int) $loan->getAttribute('installments'), $installments->count()),
            'total_paid' => $totalPaid,
            'balance_remaining' => $balance,
            'installments_paid' => $paidCount,
            'installments_skipped' => $skippedCount,
            'first_installment_date' => $loan->first_installment_date ?: optional($installments->first())->due_date,
            'status' => $status,
        ])->save();
    }

    private function findDepartment(?string $code, ?string $name): ?Department
    {
        if (!$code && !$name) {
            return null;
        }

        $nameKey = $this->key($name);
        if ($nameKey && isset($this->departmentAliases[$nameKey])) {
            return Department::find($this->departmentAliases[$nameKey]);
        }

        if ($name) {
            $byName = Department::whereRaw('LOWER(name) = ?', [strtolower(trim($name))])->first();
            if ($byName) {
                return $byName;
            }
        }

        $codeKey = $this->key($code);
        if ($codeKey && isset($this->departmentAliases[$codeKey])) {
            return Department::find($this->departmentAliases[$codeKey]);
        }

        if ($code) {
            return Department::where('code', $code)
                ->orWhere('description', 'like', '%Legacy code: ' . $code . '%')
                ->first();
        }

        return null;
    }

    private function rememberDepartmentAlias(Department $department, ?string $legacyCode, ?string $name): void
    {
        foreach ([$legacyCode, $name, $department->code, $department->name] as $value) {
            $key = $this->key($value);
            if ($key) {
                $this->departmentAliases[$key] = $department->id;
            }
        }
    }

    private function findOrCreateUnit($unitId, ?string $name = null): ?Unit
    {
        $unitId = trim((string) $unitId);
        $label = $this->legacyUnitName($name);

        if ($unitId === '') {
            if (!$label) {
                return null;
            }

            $key = $this->key('unitname_' . $label);
            if (isset($this->unitAliases[$key])) {
                return Unit::find($this->unitAliases[$key]);
            }

            $unit = Unit::whereRaw('LOWER(name) = ?', [strtolower($label)])->first();
            if ($unit && $unit->name !== $label) {
                $unit->forceFill(['name' => $label])->save();
            }
            if (!$unit) {
                $unit = Unit::firstOrCreate(
                    ['code' => $this->uniqueUnitCode($label)],
                    ['name' => $label, 'is_active' => true]
                );
            }

            $this->unitAliases[$key] = $unit->id;
            return $unit;
        }

        $key = $this->key('unitid_' . $unitId);
        if (isset($this->unitAliases[$key])) {
            return Unit::find($this->unitAliases[$key]);
        }

        $unit = Unit::where('legacy_unitid', $unitId)->first();
        if (!$unit) {
            $label = $label ?: ('Unit ' . $unitId);
            $unit = Unit::firstOrCreate(
                ['code' => $this->uniqueUnitCode('UNIT_' . $unitId)],
                ['name' => $label, 'legacy_unitid' => $unitId, 'is_active' => true]
            );
        }

        $this->unitAliases[$key] = $unit->id;
        return $unit;
    }

    private function legacyUnitName(?string $name): ?string
    {
        $name = $this->cleanLegacyValue($name);
        if (!$name) {
            return null;
        }

        return $this->key($name) === 'riyadh' ? 'Head Office' : $name;
    }

    private function uniqueUnitCode(string $code): string
    {
        $base = Str::upper(Str::slug($code ?: 'UNIT', '_'));
        if (!Unit::where('code', $base)->exists()) {
            return $base;
        }

        $i = 2;
        while (Unit::where('code', "{$base}_{$i}")->exists()) {
            $i++;
        }
        return "{$base}_{$i}";
    }

    private function findOrCreateLeaveType(string $name): LeaveType
    {
        $name = trim($name);
        $existing = LeaveType::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
        if ($existing) {
            return $existing;
        }

        return LeaveType::firstOrCreate(
            ['code' => $this->uniqueLeaveTypeCode($name)],
            [
                'name' => $name,
                'days_allowed' => 0,
                'is_paid' => true,
                'is_active' => true,
                'is_annual' => str_contains(strtolower($name), 'annual'),
            ]
        );
    }

    private function uniqueLeaveTypeCode(string $name): string
    {
        $base = Str::upper(Str::slug($name ?: 'LEAVE', '_'));
        if (!LeaveType::where('code', $base)->exists()) {
            return $base;
        }

        $i = 2;
        while (LeaveType::where('code', "{$base}_{$i}")->exists()) {
            $i++;
        }
        return "{$base}_{$i}";
    }

    private function uniqueDepartmentCode(string $code): string
    {
        $base = Str::upper(Str::slug($code ?: 'DEPT', '_'));
        if (!Department::where('code', $base)->exists()) {
            return $base;
        }

        $i = 2;
        while (Department::where('code', "{$base}_{$i}")->exists()) {
            $i++;
        }
        return "{$base}_{$i}";
    }

    private function departmentDescription(string $description, string $legacyCode, string $branch): ?string
    {
        $parts = [];
        if ($description) {
            $parts[] = $description;
        }
        if ($legacyCode) {
            $parts[] = 'Legacy code: ' . $legacyCode;
        }
        if ($branch) {
            $parts[] = 'Legacy unitid: ' . $branch;
        }

        return $parts ? implode("\n", array_unique($parts)) : null;
    }

    private function mergeDepartmentDescription(?string $current, ?string $incoming): ?string
    {
        $lines = [];
        foreach ([$current, $incoming] as $text) {
            foreach (preg_split('/\R/', (string) $text) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $lines[$line] = $line;
                }
            }
        }

        return $lines ? implode("\n", array_values($lines)) : null;
    }

    private function findDesignation(?string $title, ?int $departmentId): ?Designation
    {
        if (!$title) {
            return null;
        }
        return Designation::where('title', $title)
            ->when($departmentId, fn ($q) => $q->where(fn ($s) => $s->whereNull('department_id')->orWhere('department_id', $departmentId)))
            ->first();
    }

    private function employeeByCode(string $code): Employee
    {
        $employee = $this->employeeByCodeOrNull($code);
        if ($employee) {
            return $employee;
        }

        throw new \InvalidArgumentException("Employee not found for code/email [{$code}].");
    }

    private function employeeByCodeOrEmail(?string $code, ?string $email): ?Employee
    {
        return $this->employeeByCodeOrNull($code) ?: $this->employeeByCodeOrNull($email);
    }

    private function employeeByCodeOrNull(?string $code): ?Employee
    {
        $code = $this->cleanLegacyValue($code);
        if (!$code) {
            return null;
        }

        $formattedCode = $this->employeeCode($code);

        return Employee::where(function ($query) use ($code, $formattedCode) {
                $query->where('employee_code', $formattedCode ?: $code)
                    ->orWhere('employee_code', $code)
                    ->orWhere('email', $code);
            })
            ->first();
    }

    private function findEmployeeForManagerMigration(?string $code, ?string $email, string $label, ?string $name = null, bool $allowMissing = false): ?Employee
    {
        $code = $this->cleanLegacyValue($code);
        $formattedCode = $this->employeeCode($code);
        $email = strtolower((string) $this->cleanLegacyValue($email));
        $name = $this->cleanLegacyValue($name);

        if (!$code && !$email && !$name) {
            throw new \InvalidArgumentException("Missing {$label} empnum/email/name.");
        }

        $byCode = $code ? Employee::whereIn('employee_code', array_values(array_unique(array_filter([$formattedCode, $code]))))->first() : null;
        $byEmail = $email ? Employee::whereRaw('LOWER(email) = ?', [$email])->first() : null;
        $byName = $name ? $this->findEmployeeByLegacyName($name, $label) : null;

        if ($byCode && $byEmail && (int) $byCode->id !== (int) $byEmail->id) {
            throw new \InvalidArgumentException("Conflicting {$label} empnum/email identify different employees.");
        }
        if ($byCode && $byName && (int) $byCode->id !== (int) $byName->id) {
            throw new \InvalidArgumentException("Conflicting {$label} empnum/name identify different employees.");
        }
        if ($byEmail && $byName && (int) $byEmail->id !== (int) $byName->id) {
            throw new \InvalidArgumentException("Conflicting {$label} email/name identify different employees.");
        }

        $employee = $byCode ?: $byEmail ?: $byName;

        if (!$employee && !$allowMissing) {
            $identifier = $code ?: $email ?: $name;
            throw new \InvalidArgumentException(ucfirst($label) . " not found: {$identifier}");
        }

        return $employee;
    }

    private function findEmployeeByLegacyName(string $name, string $label): ?Employee
    {
        $needle = $this->nameKey($name);
        $matches = Employee::query()
            ->select(['id', 'manager_id', 'first_name', 'last_name'])
            ->get()
            ->filter(fn (Employee $employee) => $this->nameKey($employee->full_name) === $needle)
            ->values();

        if ($matches->count() > 1) {
            throw new \InvalidArgumentException("Ambiguous {$label} name: {$name}");
        }

        return $matches->first();
    }

    private function managerAssignmentCreatesCycle(Employee $employee, Employee $manager): bool
    {
        $seen = [(int) $employee->id => true];
        $current = $manager;

        while ($current) {
            if (isset($seen[(int) $current->id])) {
                return true;
            }

            $seen[(int) $current->id] = true;
            $current = $current->manager_id ? Employee::find($current->manager_id) : null;
        }

        return false;
    }

    private function firstLegacyValue(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && !$this->isBlankLegacyValue($row[$key])) {
                return trim((string) $row[$key]);
            }
        }

        return null;
    }

    private function nextEmployeeCode(): string
    {
        $last = Employee::withTrashed()->orderByDesc('id')->value('employee_code');
        $next = $last ? ((int) preg_replace('/\D/', '', $last) + 1) : 1;
        return 'EMP' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function employeeCode($value): ?string
    {
        $value = $this->cleanLegacyValue($value);
        if (!$value) {
            return null;
        }

        return str_starts_with(strtoupper($value), 'EMP') ? strtoupper($value) : 'EMP' . $value;
    }

    private function nextLoanReference(): string
    {
        return 'LOAN-' . now()->format('Y') . '-' . str_pad((string) (Loan::count() + 1), 5, '0', STR_PAD_LEFT);
    }

    private function moduleFromName(string $name): ?string
    {
        $key = $this->key($name);
        return [
            'department' => 'departments',
            'departments' => 'departments',
            'job_position' => 'job_positions',
            'job_positions' => 'job_positions',
            'designation' => 'job_positions',
            'designations' => 'job_positions',
            'employee' => 'employees',
            'employees' => 'employees',
            'employee_manager' => 'employee_managers',
            'employee_managers' => 'employee_managers',
            'manager' => 'employee_managers',
            'managers' => 'employee_managers',
            'manager_mapping' => 'employee_managers',
            'manager_mappings' => 'employee_managers',
            'reporting_manager' => 'employee_managers',
            'reporting_managers' => 'employee_managers',
            'leave_record' => 'leave_records',
            'leave_records' => 'leave_records',
            'loan_record' => 'loan_records',
            'loan_records' => 'loan_records',
            'document' => 'documents',
            'documents' => 'documents',
            'employee_document' => 'documents',
            'employee_documents' => 'documents',
            'documents_new' => 'documents',
        ][$key] ?? null;
    }

    private function moduleLabel(string $module): string
    {
        return ucwords(str_replace('_', ' ', $module));
    }

    private function normalizeLegacyAliases(array $row): array
    {
        $aliases = [
            'deptname' => 'name',
            'deptcode' => 'code',
            'isactive' => 'is_active',
            'createddate' => 'created_at',
            'modifieddate' => 'updated_at',
            'positionname' => 'title',
            'position_name' => 'title',
            'firstname' => 'first_name',
            'lastname' => 'last_name',
            'userfullname' => 'full_name',
            'arabicname' => 'arabic_name',
            'emailaddress' => 'email',
            'emppassword' => 'legacy_password_md5',
            'empnum' => 'employee_code',
            'contactnumber' => 'phone',
            'businessunit_name' => 'unit_name',
            'department_name' => 'department',
            'emp_status_name' => 'employment_type',
            'emprole_name' => 'role',
            'accountnumber' => 'bank_account',
            'bankname' => 'bank_name',
            'date_of_joining' => 'hire_date',
            'date_of_confirmation' => 'confirmation_date',
            'date_of_leaving' => 'termination_date',
            'designation' => 'job_position',
            'position' => 'job_position',
            'empcode' => 'employee_code',
            'employeecode' => 'employee_code',
            'employeeid' => 'employee_code',
            'employee_id' => 'employee_code',
            'leavetype_name' => 'leave_type',
            'leavetype' => 'leave_type',
            'appliedleavescount' => 'total_days',
            'from_date' => 'start_date',
            'to_date' => 'end_date',
            'from_time' => 'start_time',
            'to_time' => 'end_time',
            'leavestatus' => 'manager_status',
            'loantype' => 'loan_type',
            'loantype_name' => 'loan_type',
            'loanid' => 'loan_id',
            'installmentnumber' => 'installments',
            'reason' => 'purpose',
            'document_title' => 'title',
            'document_type' => 'type',
            'filename' => 'file_name',
            'file' => 'file_name',
            'file_path' => 'file_name',
            'expirydate' => 'expiry_date',
        ];

        foreach ($aliases as $legacy => $canonical) {
            if (($row[$canonical] ?? '') === '' && array_key_exists($legacy, $row)) {
                $row[$canonical] = $row[$legacy];
            }
        }

        return $row;
    }

    private function key(?string $value): string
    {
        return Str::of((string) $value)->trim()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $key => $value) {
            if (!str_starts_with((string) $key, '_') && trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    private function publicRow(array $row): array
    {
        unset($row['password']);
        return array_filter($row, fn ($key) => !str_starts_with((string) $key, '_'), ARRAY_FILTER_USE_KEY);
    }

    private function nullableInt($value): ?int
    {
        return $this->isBlankLegacyValue($value) ? null : (int) $value;
    }

    private function departmentHeadcountBudget($value): int
    {
        $value = is_string($value) ? trim($value) : $value;

        if ($value === null || $value === '' || strtolower((string) $value) === 'null') {
            return 5;
        }

        return max(1, (int) $value);
    }

    private function nullableDecimal($value): ?float
    {
        return $this->isBlankLegacyValue($value) ? null : (float) str_replace(',', '', (string) $value);
    }

    private function bool($value): bool
    {
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'active'], true);
    }

    private function enum(?string $value, array $allowed): ?string
    {
        $value = $this->key($value);
        return in_array($value, $allowed, true) ? $value : null;
    }

    private function date($value): ?string
    {
        if ($this->isBlankLegacyValue($value)) {
            return null;
        }
        if (is_numeric($value)) {
            return gmdate('Y-m-d', ((int) $value - 25569) * 86400);
        }
        return date('Y-m-d', strtotime((string) $value));
    }

    private function legacyDate($value): ?string
    {
        if ($this->isBlankLegacyValue($value)) {
            return null;
        }

        $timestamp = strtotime((string) $value);
        if (!$timestamp) {
            return null;
        }

        $date = date('Y-m-d', $timestamp);
        return ((int) substr($date, 0, 4)) < 1990 ? null : $date;
    }

    private function legacyDateTime($value)
    {
        if ($this->isBlankLegacyValue($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return gmdate('Y-m-d H:i:s', (int) round(((float) $value - 25569) * 86400));
        }

        $timestamp = strtotime((string) $value);
        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private function legacyTime($value): ?string
    {
        if ($this->isBlankLegacyValue($value) || trim((string) $value) === '00:00:00') {
            return null;
        }

        $timestamp = strtotime((string) $value);
        return $timestamp ? date('H:i:s', $timestamp) : null;
    }

    private function legacyHours(?string $startTime, ?string $endTime): ?float
    {
        if (!$startTime || !$endTime) {
            return null;
        }

        $start = strtotime($startTime);
        $end = strtotime($endTime);
        if (!$start || !$end || $end <= $start) {
            return null;
        }

        return round(($end - $start) / 3600, 2);
    }

    private function leaveMigrationStatus($managerStatus, $hrStatus): string
    {
        $managerStatus = $this->key($managerStatus);
        $hrStatus = $this->key($hrStatus);

        if (in_array($managerStatus, ['cancel', 'cancelled', 'canceled'], true) || in_array($hrStatus, ['cancel', 'cancelled', 'canceled'], true)) {
            return 'cancelled';
        }
        if ($managerStatus === 'rejected' || $hrStatus === 'rejected') {
            return 'rejected';
        }
        if ($hrStatus === 'approved') {
            return 'approved';
        }
        if ($managerStatus === 'approved') {
            return 'manager_approved';
        }

        return 'pending';
    }

    private function legacyPasswordMd5($value): ?string
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/^[a-f0-9]{32}$/', $value) ? $value : null;
    }

    private function cleanLegacyValue($value): ?string
    {
        return $this->isBlankLegacyValue($value) ? null : trim((string) $value);
    }

    private function nameKey(string $value): string
    {
        return Str::of($value)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }

    private function isBlankLegacyValue($value): bool
    {
        return $value === null || trim((string) $value) === '' || strtolower(trim((string) $value)) === 'null';
    }
}
