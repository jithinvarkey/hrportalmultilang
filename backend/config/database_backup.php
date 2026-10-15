<?php

return [
    'mysqldump_path' => env(
        'MYSQLDUMP_PATH',
        PHP_OS_FAMILY === 'Windows' ? 'E:\\Xampp_new\\mysql\\bin\\mysqldump.exe' : 'mysqldump'
    ),

    'directory' => storage_path('app/backups/database'),
    'retention_days' => (int) env('DATABASE_BACKUP_RETENTION_DAYS', 30),

    // Riyadh local time: 01:00 and 13:00.
    'schedule_hours' => [1, 13],
];
