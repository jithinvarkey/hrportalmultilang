<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Unit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RepairEmployeeUnitsFromLegacyCsv extends Command
{
    protected $signature = 'legacy:repair-employee-units
        {file : Full path to the employee CSV file}
        {--dry-run : Validate and show summary without updating employees}';

    protected $description = 'Assign employee units from a legacy employee CSV, mapping Riyadh business unit to Head Office';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');

        if (!is_file($path) || !is_readable($path)) {
            $this->error("CSV file not found or not readable: {$path}");
            return self::FAILURE;
        }

        $summary = [
            'processed' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($this->readCsv($path) as $row) {
            $summary['processed']++;

            try {
                if ($this->rowIsEmpty($row)) {
                    $summary['skipped']++;
                    continue;
                }

                $unitName = $this->legacyUnitName($this->firstValue($row, ['businessunit_name', 'unit_name', 'branch_name', 'unit']));
                if (!$unitName) {
                    $summary['skipped']++;
                    continue;
                }

                $employee = $this->findEmployee(
                    $this->firstValue($row, ['empnum', 'employee_code', 'empcode', 'employee_number']),
                    $this->firstValue($row, ['emailaddress', 'email', 'email_address'])
                );
                $unit = $this->findOrCreateUnit($unitName);

                if ((int) $employee->unit_id === (int) $unit->id) {
                    $summary['unchanged']++;
                    continue;
                }

                if (!$dryRun) {
                    DB::transaction(fn () => $employee->forceFill(['unit_id' => $unit->id])->save());
                }

                $summary['updated']++;
            } catch (\Throwable $e) {
                $summary['failed']++;
                $summary['errors'][] = [
                    'row' => $row['_line'] ?? null,
                    'employee' => $this->firstValue($row, ['empnum', 'employee_code', 'emailaddress', 'email']),
                    'message' => $e->getMessage(),
                ];
            }
        }

        $this->line($dryRun ? 'Dry run only. No records were updated.' : 'Employee unit repair completed.');
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
            $this->table(['Row', 'Employee', 'Message'], array_map(fn ($error) => [
                $error['row'],
                $error['employee'],
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

    private function findEmployee(?string $code, ?string $email): Employee
    {
        $code = $this->clean($code);
        $formattedCode = $this->employeeCode($code);
        $email = strtolower((string) $this->clean($email));

        if (!$code && !$email) {
            throw new \InvalidArgumentException('Missing employee empnum/email.');
        }

        $byCode = $code ? Employee::whereIn('employee_code', array_values(array_unique(array_filter([$formattedCode, $code]))))->first() : null;
        $byEmail = $email ? Employee::whereRaw('LOWER(email) = ?', [$email])->first() : null;

        if ($byCode && $byEmail && (int) $byCode->id !== (int) $byEmail->id) {
            throw new \InvalidArgumentException('Conflicting empnum/email identify different employees.');
        }

        $employee = $byCode ?: $byEmail;
        if (!$employee) {
            throw new \InvalidArgumentException('Employee not found: ' . ($code ?: $email));
        }

        return $employee;
    }

    private function findOrCreateUnit(string $name): Unit
    {
        $unit = Unit::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
        if ($unit) {
            if ($unit->name !== $name) {
                $unit->forceFill(['name' => $name])->save();
            }
            return $unit;
        }

        return Unit::firstOrCreate(
            ['code' => $this->uniqueUnitCode($name)],
            ['name' => $name, 'is_active' => true]
        );
    }

    private function uniqueUnitCode(string $name): string
    {
        $base = Str::upper(Str::slug($name ?: 'UNIT', '_'));
        if (!Unit::where('code', $base)->exists()) {
            return $base;
        }

        $i = 2;
        while (Unit::where('code', "{$base}_{$i}")->exists()) {
            $i++;
        }

        return "{$base}_{$i}";
    }

    private function legacyUnitName(?string $name): ?string
    {
        $name = $this->clean($name);
        if (!$name) {
            return null;
        }

        return $this->key($name) === 'riyadh' ? 'Head Office' : $name;
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

    private function blank($value): bool
    {
        $value = trim((string) $value);

        return $value === '' || strtolower($value) === 'null';
    }
}
