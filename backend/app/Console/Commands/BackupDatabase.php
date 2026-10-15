<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class BackupDatabase extends Command
{
    protected $signature = 'database:backup {--keep-days= : Override the configured retention period}';
    protected $description = 'Create a consistent MySQL database backup and remove expired backup files';

    public function handle(): int
    {
        $connectionName = (string) config('database.default');
        $connection = (array) config("database.connections.{$connectionName}");

        if (!in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $this->error("Database backups currently support MySQL/MariaDB only; configured driver: {$connectionName}.");
            return self::FAILURE;
        }

        $database = (string) ($connection['database'] ?? '');
        if ($database === '') {
            $this->error('The configured database name is empty.');
            return self::FAILURE;
        }

        $backupDirectory = (string) config('database_backup.directory');
        File::ensureDirectoryExists($backupDirectory, 0750, true);

        $safeDatabase = preg_replace('/[^A-Za-z0-9_.-]/', '_', $database);
        $backupPath = $backupDirectory . DIRECTORY_SEPARATOR
            . $safeDatabase . '_' . now('Asia/Riyadh')->format('Y-m-d_H-i-s') . '.sql';
        $credentialsPath = tempnam(storage_path('framework/cache'), 'db-backup-');

        if ($credentialsPath === false) {
            $this->error('Unable to create a temporary MySQL credentials file.');
            return self::FAILURE;
        }

        try {
            File::put($credentialsPath, $this->credentialsFile($connection));
            @chmod($credentialsPath, 0600);

            $command = [
                (string) config('database_backup.mysqldump_path'),
                '--defaults-extra-file=' . $credentialsPath,
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--events',
                '--default-character-set=utf8mb4',
                '--add-drop-table',
                '--result-file=' . $backupPath,
                '--databases',
                $database,
            ];

            $this->runDump($command);

            if (!File::exists($backupPath) || File::size($backupPath) === 0) {
                throw new RuntimeException('mysqldump completed without creating a valid backup file.');
            }

            $deleted = $this->deleteExpiredBackups($backupDirectory);
            $sizeMb = number_format(File::size($backupPath) / 1048576, 2);

            $this->info("Database backup created: {$backupPath} ({$sizeMb} MB)");
            $this->line("Expired backups removed: {$deleted}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if (File::exists($backupPath)) {
                File::delete($backupPath);
            }

            report($exception);
            $this->error('Database backup failed: ' . $exception->getMessage());
            return self::FAILURE;
        } finally {
            File::delete($credentialsPath);
        }
    }

    private function credentialsFile(array $connection): string
    {
        $lines = [
            '[client]',
            'user=' . $this->quoteOption((string) ($connection['username'] ?? '')),
            'password=' . $this->quoteOption((string) ($connection['password'] ?? '')),
            'host=' . $this->quoteOption((string) ($connection['host'] ?? '127.0.0.1')),
            'port=' . (int) ($connection['port'] ?? 3306),
        ];

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function quoteOption(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /** @param array<int, string> $command */
    private function runDump(array $command): void
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, base_path());
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start mysqldump.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $message = trim((string) ($stderr ?: $stdout));
            throw new RuntimeException($message ?: "mysqldump exited with code {$exitCode}.");
        }
    }

    private function deleteExpiredBackups(string $directory): int
    {
        $keepDays = $this->option('keep-days');
        $retentionDays = $keepDays === null
            ? (int) config('database_backup.retention_days', 30)
            : max(1, (int) $keepDays);
        $cutoff = now()->subDays(max(1, $retentionDays));
        $deleted = 0;

        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'sql' || $file->getMTime() >= $cutoff->getTimestamp()) {
                continue;
            }

            if (File::delete($file->getPathname())) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
