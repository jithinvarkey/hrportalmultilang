<?php

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrefixEmployeeCodes extends Command
{
    protected $signature = 'legacy:prefix-employee-codes {--dry-run : Show summary without updating employees}';

    protected $description = 'Prefix existing numeric employee codes with EMP';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $summary = [
            'processed' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        Employee::withTrashed()
            ->orderBy('id')
            ->get(['id', 'employee_code'])
            ->each(function (Employee $employee) use (&$summary, $dryRun) {
                $summary['processed']++;
                $current = trim((string) $employee->employee_code);
                $next = $this->employeeCode($current);

                if ($current === $next) {
                    $summary['unchanged']++;
                    return;
                }

                $exists = Employee::withTrashed()
                    ->where('employee_code', $next)
                    ->whereKeyNot($employee->id)
                    ->exists();

                if ($exists) {
                    $summary['failed']++;
                    $summary['errors'][] = [
                        'id' => $employee->id,
                        'current' => $current,
                        'next' => $next,
                        'message' => 'Target employee code already exists.',
                    ];
                    return;
                }

                if (!$dryRun) {
                    DB::transaction(fn () => $employee->forceFill(['employee_code' => $next])->save());
                }

                $summary['updated']++;
            });

        $this->line($dryRun ? 'Dry run only. No records were updated.' : 'Employee code prefix repair completed.');
        $this->table(
            ['Processed', 'Updated', 'Unchanged', 'Failed'],
            [[$summary['processed'], $summary['updated'], $summary['unchanged'], $summary['failed']]]
        );

        if ($summary['errors']) {
            $this->warn('Errors:');
            $this->table(['ID', 'Current', 'Next', 'Message'], array_map(fn ($error) => [
                $error['id'],
                $error['current'],
                $error['next'],
                $error['message'],
            ], $summary['errors']));
        }

        return $summary['failed'] ? self::FAILURE : self::SUCCESS;
    }

    private function employeeCode(string $value): string
    {
        return str_starts_with(strtoupper($value), 'EMP') ? strtoupper($value) : 'EMP' . $value;
    }
}
