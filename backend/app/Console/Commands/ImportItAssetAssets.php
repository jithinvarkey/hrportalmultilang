<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ImportItAssetAssets extends Command
{
    protected $signature = 'assets:import-itasset
        {--commit : Write changes; without this option the command is a dry run}
        {--include-deleted : Include soft-deleted source assets}';

    protected $description = 'Import Snipe-IT assets and match custodians by email or username employee code';

    private const SOURCE = 'itasset';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $stats = [
            'source' => 0, 'created' => 0, 'updated' => 0,
            'assigned' => 0, 'matched_by_email' => 0, 'matched_by_employee_code' => 0,
            'unmatched' => 0, 'unassigned' => 0, 'duplicate_serials_cleared' => 0,
        ];

        try {
            DB::connection('itasset')->getPdo();
        } catch (Throwable $e) {
            $this->error('Cannot connect to the itasset database: '.$e->getMessage());
            return self::FAILURE;
        }

        $employeeRecords = Employee::query()
            ->get(['id', 'email', 'employee_code']);
        $employeesByEmail = $employeeRecords
            ->filter(function (Employee $employee) {
                return $this->normalizeEmail($employee->email) !== '';
            })
            ->keyBy(function (Employee $employee) {
                return $this->normalizeEmail($employee->email);
            });
        $employeesByCode = $employeeRecords
            ->filter(function (Employee $employee) {
                return $this->normalizeEmployeeCode($employee->employee_code) !== '';
            })
            ->keyBy(function (Employee $employee) {
                return $this->normalizeEmployeeCode($employee->employee_code);
            });

        $query = DB::connection('itasset')->table('assets as a')
            ->leftJoin('models as m', 'm.id', '=', 'a.model_id')
            ->leftJoin('manufacturers as mf', 'mf.id', '=', 'm.manufacturer_id')
            ->leftJoin('categories as c', 'c.id', '=', 'm.category_id')
            ->leftJoin('status_labels as sl', 'sl.id', '=', 'a.status_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.assigned_to')
            ->leftJoin('locations as l', 'l.id', '=', DB::raw('COALESCE(a.location_id, a.rtd_location_id)'))
            ->leftJoin('suppliers as s', 's.id', '=', 'a.supplier_id')
            ->select([
                'a.id', 'a.name', 'a.asset_tag', 'a.serial', 'a.purchase_date',
                'a.purchase_cost', 'a.notes', 'a.order_number', 'a.warranty_months',
                'a.assigned_to', 'a.last_checkout', 'a.created_at', 'a.deleted_at',
                'm.name as model_name', 'm.model_number', 'mf.name as manufacturer_name',
                'c.name as category_name', 'sl.name as status_name', 'sl.archived as status_archived',
                'u.email as custodian_email', 'u.username as custodian_username',
                'l.name as location_name', 's.name as supplier_name',
            ])
            ->orderBy('a.id');

        if (!$this->option('include-deleted')) {
            $query->whereNull('a.deleted_at');
        }

        $rows = $query->get();
        $stats['source'] = $rows->count();

        // HR Portal requires serial_number to be unique, but the legacy source
        // contains duplicates. Keep it on the first source asset and preserve
        // it in the description of every later duplicate.
        $firstSourceIdBySerial = [];
        $duplicateSerials = [];
        foreach ($rows as $sourceRow) {
            $serial = $this->nullableTrim($sourceRow->serial);
            if ($serial === null) {
                continue;
            }

            $serialKey = Str::lower($serial);
            if (isset($firstSourceIdBySerial[$serialKey])) {
                $duplicateSerials[$sourceRow->id] = $serial;
            } else {
                $firstSourceIdBySerial[$serialKey] = $sourceRow->id;
            }
        }

        foreach ($rows as $row) {
            $duplicateSerial = $duplicateSerials[$row->id] ?? null;
            if ($duplicateSerial !== null) {
                $stats['duplicate_serials_cleared']++;
            }
            $email = $this->normalizeEmail($row->custodian_email);
            $usernameCode = $this->employeeCodeFromUsername($row->custodian_username);
            /** @var Employee|null $employee */
            $employee = $email !== '' ? $employeesByEmail->get($email) : null;
            $matchMethod = $employee ? 'email' : null;

            if (!$employee && $usernameCode !== '') {
                $employee = $employeesByCode->get($usernameCode);
                $matchMethod = $employee ? 'employee_code' : null;
            }

            if ($matchMethod === 'email') {
                $stats['matched_by_email']++;
            } elseif ($matchMethod === 'employee_code') {
                $stats['matched_by_employee_code']++;
            }

            if ($row->assigned_to && !$employee) {
                $stats['unmatched']++;
            } elseif (!$row->assigned_to) {
                $stats['unassigned']++;
            }

            if (!$commit) {
                $existing = Asset::where('source_system', self::SOURCE)->where('source_id', $row->id)->exists();
                $stats[$existing ? 'updated' : 'created']++;
                if ($employee) {
                    $stats['assigned']++;
                }
                continue;
            }

            DB::transaction(function () use ($row, $employee, $duplicateSerial, &$stats): void {
                $category = $this->category($row->category_name ?: 'Uncategorized');
                $existing = Asset::where('source_system', self::SOURCE)->where('source_id', $row->id)->first();
                $asset = Asset::updateOrCreate(
                    ['source_system' => self::SOURCE, 'source_id' => $row->id],
                    [
                        'category_id' => $category->id,
                        'name' => $row->name ?: $row->model_name ?: 'Asset '.$row->asset_tag,
                        'asset_code' => trim((string) $row->asset_tag),
                        'brand' => $row->manufacturer_name,
                        'model' => $row->model_number ?: $row->model_name,
                        'serial_number' => $duplicateSerial === null ? $this->nullableTrim($row->serial) : null,
                        'description' => $this->description($row, $duplicateSerial),
                        'status' => $this->status($row, $employee),
                        'condition' => 'good',
                        'purchase_price' => $row->purchase_cost,
                        'purchase_date' => $row->purchase_date,
                        'vendor' => $row->supplier_name,
                        'warranty_expiry' => $this->warrantyExpiry($row->purchase_date, $row->warranty_months),
                        'location' => $row->location_name,
                        'custodian_employee_id' => $employee ? $employee->id : null,
                    ]
                );

                $stats[$existing ? 'updated' : 'created']++;
                $this->syncAssignment($asset, $employee, $row, $stats);
            });
        }

        $this->table(['Result', 'Count'], collect($stats)->map(function ($value, $key) {
            return [$key, $value];
        })->values()->all());
        $this->newLine();
        $this->info($commit ? 'Import completed.' : 'Dry run only. Run again with --commit to write these changes.');

        return self::SUCCESS;
    }

    private function category(string $name): AssetCategory
    {
        $slug = Str::slug(trim($name)) ?: 'uncategorized';
        return AssetCategory::firstOrCreate(['slug' => $slug], ['name' => trim($name), 'is_active' => true]);
    }

    private function syncAssignment(Asset $asset, ?Employee $employee, object $row, array &$stats): void
    {
        $active = AssetAssignment::where('asset_id', $asset->id)->whereNull('return_date')->first();

        if (!$employee) {
            if ($active) {
                $active->update(['return_date' => now()->toDateString()]);
            }
            return;
        }

        if ($active && $active->employee_id !== $employee->id) {
            $active->update(['return_date' => now()->toDateString()]);
            $active = null;
        }

        if (!$active) {
            AssetAssignment::create([
                'asset_id' => $asset->id,
                'employee_id' => $employee->id,
                'assigned_date' => Carbon::parse($row->last_checkout ?: $row->created_at ?: now())->toDateString(),
                'condition_at_assign' => 'good',
                'notes' => 'Imported from itasset source asset #'.$row->id,
            ]);
        }

        $stats['assigned']++;
    }

    private function status(object $row, ?Employee $employee): string
    {
        if ($row->deleted_at || $row->status_archived) {
            return 'disposed';
        }
        return $employee ? 'assigned' : 'available';
    }

    private function description(object $row, ?string $duplicateSerial = null): ?string
    {
        $parts = array_filter([
            $this->nullableTrim($row->notes),
            $row->order_number ? 'Order: '.trim($row->order_number) : null,
            $row->status_name ? 'Source status: '.trim($row->status_name) : null,
            $duplicateSerial ? 'Source serial (duplicate): '.$duplicateSerial : null,
        ]);
        return $parts ? implode("\n", $parts) : null;
    }

    private function warrantyExpiry($purchaseDate, $months): ?string
    {
        return $purchaseDate && $months ? Carbon::parse($purchaseDate)->addMonths((int) $months)->toDateString() : null;
    }

    private function normalizeEmail($email): string
    {
        return Str::lower(trim((string) $email));
    }

    private function employeeCodeFromUsername($username): string
    {
        if (!preg_match('/(\d+)/', trim((string) $username), $matches)) {
            return '';
        }

        return $this->normalizeEmployeeCode($matches[1]);
    }

    private function normalizeEmployeeCode($code): string
    {
        $code = trim((string) $code);
        return $code === '' ? '' : (ltrim($code, '0') ?: '0');
    }

    private function nullableTrim($value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
