<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Production runs on MySQL; the suite runs on SQLite. Provide the MySQL date functions the app uses.
        if (DB::getDriverName() === 'sqlite') {
            $pdo = DB::connection()->getPdo();
            $pdo->sqliteCreateFunction('YEAR', fn ($value) => $value === null ? null : (int) substr((string) $value, 0, 4), 1);
            $pdo->sqliteCreateFunction('MONTH', fn ($value) => $value === null ? null : (int) substr((string) $value, 5, 2), 1);
            $pdo->sqliteCreateFunction('DAY', fn ($value) => $value === null ? null : (int) substr((string) $value, 8, 2), 1);
        }
    }
}
