<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LoanApprovalService
{
    private const SETTING_KEY = 'loan_approval_levels';

    public function levels(): int
    {
        $fallback = (int) config('loans.approval_levels', 3);

        if (!Schema::hasTable('system_settings')) {
            return $this->normalize($fallback);
        }

        $stored = DB::table('system_settings')
            ->where('key', self::SETTING_KEY)
            ->value('value');

        return $this->normalize($stored === null ? $fallback : (int) $stored);
    }

    public function updateLevels(int $levels): int
    {
        $levels = $this->normalize($levels);
        $previous = $this->levels();

        DB::table('system_settings')->updateOrInsert(
            ['key' => self::SETTING_KEY],
            [
                'value' => (string) $levels,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        activity('system_settings')
            ->event('updated')
            ->causedBy(auth()->user())
            ->withProperties([
                'setting' => self::SETTING_KEY,
                'from' => $previous,
                'to' => $levels,
            ])
            ->log('Loan approval workflow updated.');

        return $levels;
    }

    private function normalize(int $levels): int
    {
        return in_array($levels, [2, 3], true) ? $levels : 3;
    }
}
