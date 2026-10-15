<?php

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MigrateEmployeeManagers extends Command
{
    protected $signature = 'legacy:migrate-managers
        {file : Full path to the CSV file}
        {--dry-run : Validate and show summary without updating employees}
        {--skip-missing-manager : Skip rows where the manager identifier does not match an employee}
        {--fail-on-missing-manager : Treat missing manager value as a failed row instead of skipped}';

    protected $description = 'Connect employees to their direct managers from a legacy CSV using empnum/email identifiers';

    private array $employeeCodeKeys = ['empnum', 'employee_code', 'empcode', 'employee_number', 'employee_no'];
    private array $employeeEmailKeys = ['email', 'emailaddress', 'email_address', 'employee_email'];
    private array $managerCodeKeys = [
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
    ];
    private array $managerEmailKeys = [
        'manager_email',
        'manager_emailaddress',
        'manager_email_address',
        'direct_manager_email',
        'direct_manager_emailaddress',
        'directmanager_email',
        'directmanageremail',
        'reporting_manager_email',
        'reportingmanager_email',
    ];
    private array $managerNameKeys = [
        'manager_name',
        'manager_full_name',
        'direct_manager_name',
        'direct_manager_full_name',
        'directmanager_name',
        'directmanagername',
        'reporting_manager_name',
        'reportingmanager_name',
        'reportingmanagername',
    ];

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');

        if (!is_file($path) || !is_readable($path)) {
            $this->error("CSV file not found or not readable: {$path}");
            return self::FAILURE;
        }

        $rows = $this->readCsv($path);
        $summary = [
            'processed' => count($rows),
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($rows as $row) {
            try {
                if ($this->rowIsEmpty($row)) {
                    $summary['skipped']++;
                    continue;
                }

                $employee = $this->findEmployee(
                    $this->firstValue($row, $this->employeeCodeKeys),
                    $this->firstValue($row, $this->employeeEmailKeys),
                    'employee'
                );

                $managerCode = $this->firstValue($row, $this->managerCodeKeys);
                $managerEmail = $this->firstValue($row, $this->managerEmailKeys);
                $managerName = $this->firstValue($row, $this->managerNameKeys);

                if (!$managerCode && !$managerEmail && !$managerName) {
                    if ($this->option('fail-on-missing-manager')) {
                        throw new \InvalidArgumentException('Missing direct manager empnum/email/name.');
                    }
                    $summary['skipped']++;
                    continue;
                }

                try {
                    $manager = $this->findEmployee($managerCode, $managerEmail, 'manager', $managerName);
                } catch (\InvalidArgumentException $e) {
                    if ($this->option('skip-missing-manager') && str_starts_with($e->getMessage(), 'Manager not found:')) {
                        $summary['skipped']++;
                        continue;
                    }

                    throw $e;
                }

                if ((int) $employee->id === (int) $manager->id) {
                    throw new \InvalidArgumentException('Employee cannot be their own manager.');
                }

                if ($this->wouldCreateCycle($employee, $manager)) {
                    throw new \InvalidArgumentException('Manager assignment would create a reporting cycle.');
                }

                if ((int) $employee->manager_id === (int) $manager->id) {
                    $summary['unchanged']++;
                    continue;
                }

                if (!$dryRun) {
                    DB::transaction(function () use ($employee, $manager) {
                        $employee->forceFill(['manager_id' => $manager->id])->save();
                    });
                }

                $summary['updated']++;
            } catch (\Throwable $e) {
                $summary['failed']++;
                $summary['errors'][] = [
                    'row' => $row['_line'] ?? null,
                    'message' => $e->getMessage(),
                    'employee' => $this->firstValue($row, $this->employeeCodeKeys) ?: $this->firstValue($row, $this->employeeEmailKeys),
                    'manager' => $this->firstValue($row, $this->managerCodeKeys)
                        ?: $this->firstValue($row, $this->managerEmailKeys)
                        ?: $this->firstValue($row, $this->managerNameKeys),
                ];
            }
        }

        $this->line($dryRun ? 'Dry run only. No records were updated.' : 'Manager migration completed.');
        $this->table(
            ['Processed', 'Updated', 'Unchanged', 'Skipped', 'Failed'],
            [[
                $summary['processed'],
                $summary['updated'],
                $summary['unchanged'],
                $summary['skipped'],
                $summary['failed'],
            ]]
        );

        if ($summary['errors']) {
            $this->warn('Errors:');
            $this->table(['Row', 'Employee', 'Manager', 'Message'], array_map(fn ($error) => [
                $error['row'],
                $error['employee'],
                $error['manager'],
                $error['message'],
            ], $summary['errors']));
        }

        return $summary['failed'] ? self::FAILURE : self::SUCCESS;
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new \RuntimeException('Unable to open CSV file.');
        }

        $headers = null;
        $rows = [];
        $line = 0;

        while (($data = fgetcsv($handle)) !== false) {
            $line++;
            if ($headers === null) {
                $headers = array_map(fn ($header) => $this->key($header), $data);
                continue;
            }

            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = trim((string) ($data[$index] ?? ''));
            }
            $row['_line'] = $line;
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    private function findEmployee(?string $code, ?string $email, string $label, ?string $name = null): Employee
    {
        $code = $this->clean($code);
        $formattedCode = $this->employeeCode($code);
        $email = strtolower((string) $this->clean($email));
        $name = $this->clean($name);

        if (!$code && !$email && !$name) {
            throw new \InvalidArgumentException("Missing {$label} empnum/email/name.");
        }

        $byCode = $code ? Employee::whereIn('employee_code', array_values(array_unique(array_filter([$formattedCode, $code]))))->first() : null;
        $byEmail = $email ? Employee::whereRaw('LOWER(email) = ?', [$email])->first() : null;
        $byName = $name ? $this->findEmployeeByName($name, $label) : null;

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

        if (!$employee) {
            $identifier = $code ?: $email ?: $name;
            throw new \InvalidArgumentException(ucfirst($label) . " not found: {$identifier}");
        }

        return $employee;
    }

    private function findEmployeeByName(string $name, string $label): ?Employee
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

    private function wouldCreateCycle(Employee $employee, Employee $manager): bool
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

    private function firstValue(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && !$this->blank($row[$key])) {
                return trim((string) $row[$key]);
            }
        }

        return null;
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $key => $value) {
            if (!str_starts_with((string) $key, '_') && !$this->blank($value)) {
                return false;
            }
        }

        return true;
    }

    private function key(?string $value): string
    {
        return Str::of((string) $value)->trim()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
    }

    private function clean(?string $value): ?string
    {
        return $this->blank($value) ? null : trim((string) $value);
    }

    private function employeeCode(?string $value): ?string
    {
        $value = $this->clean($value);
        if (!$value) {
            return null;
        }

        return str_starts_with(strtoupper($value), 'EMP') ? strtoupper($value) : 'EMP' . $value;
    }

    private function nameKey(string $value): string
    {
        return Str::of($value)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }

    private function blank($value): bool
    {
        $value = trim((string) $value);

        return $value === '' || strtolower($value) === 'null';
    }
}
