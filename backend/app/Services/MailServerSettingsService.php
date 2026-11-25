<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Outgoing mail (SMTP) server, managed from Admin > Settings instead of .env.
 *
 * The saved settings are used as one unit: once an SMTP host is saved, nothing is taken from the MAIL_* values
 * in .env any more, so the .env password can never be sent to a server chosen on the settings page (or the other
 * way round). Until a host is saved, .env keeps working as before.
 *
 * MAIL_MAILER, MAIL_TEST_EMAIL and MAIL_FORCE_TEST_EMAIL deliberately stay in .env: they protect a development
 * machine from emailing real people, and must survive importing a copy of the production database.
 *
 * Registered as a singleton (see AppServiceProvider) so the original .env values are captured before apply().
 */
class MailServerSettingsService
{
    private const PREFIX = 'mail_server_';

    private const FIELDS = ['host', 'port', 'encryption', 'username', 'password', 'from_address', 'from_name'];

    /** @var array<string,mixed> MAIL_* values from .env / config, before any saved setting replaced them */
    private array $envSmtp;

    private array $envFrom;

    private ?string $appliedFingerprint = null;

    public function __construct()
    {
        $this->envSmtp = (array) config('mail.mailers.smtp');
        $this->envFrom = (array) config('mail.from');
    }

    /** Settings for the admin page. The password itself never leaves the server. */
    public function settings(): array
    {
        $stored = $this->stored();
        $saved = $stored !== null;
        $password = $saved ? $this->decrypt($stored['password']) : (string) ($this->envSmtp['password'] ?? '');

        $effective = $saved ? $stored : $this->envValues();

        return [
            'source' => $saved ? 'database' : 'env',
            'host' => (string) $effective['host'],
            'port' => (int) $effective['port'],
            'encryption' => (string) $effective['encryption'],
            'username' => (string) $effective['username'],
            'from_address' => (string) $effective['from_address'],
            'from_name' => (string) $effective['from_name'],
            'has_password' => $password !== null && $password !== '',
            // Saved with a different APP_KEY (e.g. the database was moved to a new server): type it again.
            'password_unreadable' => $password === null,
            'mailer' => (string) config('mail.default'),
            'redirected_to' => $this->redirectAddress(),
        ];
    }

    public function updateSettings(array $data): array
    {
        $before = $this->settings();
        $username = trim((string) ($data['username'] ?? ''));
        $host = trim((string) $data['host']);

        $rows = [
            'host' => $host,
            'port' => (string) (int) $data['port'],
            'encryption' => (string) $data['encryption'],
            'username' => $username,
            'password' => $this->encryptedPassword($before, $host, $username, (string) ($data['password'] ?? '')),
            'from_address' => trim((string) $data['from_address']),
            'from_name' => trim((string) $data['from_name']),
        ];

        DB::transaction(function () use ($rows) {
            foreach ($rows as $field => $value) {
                DB::table('system_settings')->updateOrInsert(
                    ['key' => self::PREFIX . $field],
                    ['value' => $value, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        });

        $visible = ['host', 'port', 'encryption', 'username', 'from_address', 'from_name'];
        activity('system_settings')
            ->event('updated')
            ->causedBy(auth()->user())
            ->withProperties([
                'setting' => 'mail_server',
                'from' => array_intersect_key($before, array_flip($visible)),
                'to' => array_intersect_key($rows, array_flip($visible)),
                'password_changed' => (string) ($data['password'] ?? '') !== '',
            ])
            ->log('Outgoing mail server settings updated.');

        $this->apply();

        return $this->settings();
    }

    /**
     * Put the saved settings into the mail config. Safe to call repeatedly: a long-running queue worker calls it
     * before every job, so a change on the settings page is picked up without restarting the worker.
     */
    public function apply(?MailManager $manager = null): void
    {
        try {
            $stored = $this->stored();
            $password = $stored === null ? '' : $this->decrypt($stored['password']);
        } catch (Throwable) {
            // No database / settings table yet (initial migrations, outage): keep the current configuration.
            return;
        }

        if ($stored !== null && $password === null) {
            Log::warning('The saved SMTP password cannot be decrypted (APP_KEY changed). Enter it again in Admin > Settings; using the .env mail settings until then.');
            $stored = null;
        }

        $smtp = $stored === null ? $this->envSmtp : array_merge($this->envSmtp, [
            'url' => null,
            'host' => $stored['host'],
            'port' => (int) $stored['port'],
            'timeout' => 30, // a blocked port must not keep a user's request waiting for minutes
            'encryption' => $stored['encryption'] === 'none' ? null : $stored['encryption'],
            // 'smtps' = TLS from the first byte; 'smtp' = plain connection upgraded with STARTTLS when offered.
            'scheme' => $stored['encryption'] === 'ssl' ? 'smtps' : 'smtp',
            'username' => $stored['username'] !== '' ? $stored['username'] : null,
            'password' => $stored['username'] !== '' ? $password : null,
        ]);
        $from = $stored === null ? $this->envFrom : [
            'address' => $stored['from_address'],
            'name' => $stored['from_name'],
        ];

        $fingerprint = hash('sha256', serialize([$smtp, $from]));
        if ($fingerprint === $this->appliedFingerprint) {
            return;
        }

        config(['mail.mailers.smtp' => $smtp, 'mail.from' => $from]);
        $this->appliedFingerprint = $fingerprint;

        // Mailers that were already built keep their old connection and From address: rebuild them.
        $manager ??= app()->resolved('mail.manager') ? app('mail.manager') : null;
        if ($manager instanceof MailManager) {
            $manager->forgetMailers();
        }
    }

    /** Inbox every message is redirected to on test systems, or null when real recipients get their mail. */
    public function redirectAddress(): ?string
    {
        $testEmail = (string) config('mail.test_email');

        return $testEmail !== '' && (config('mail.force_test_email') || app()->environment('local')) ? $testEmail : null;
    }

    /** Saved settings, or null when the page has never been saved. */
    private function stored(): ?array
    {
        $values = DB::table('system_settings')
            ->whereIn('key', array_map(fn ($field) => self::PREFIX . $field, self::FIELDS))
            ->pluck('value', 'key');

        if (blank($values[self::PREFIX . 'host'] ?? null)) {
            return null;
        }

        $stored = [];
        foreach (self::FIELDS as $field) {
            $stored[$field] = (string) ($values[self::PREFIX . $field] ?? '');
        }

        return $stored;
    }

    private function envValues(): array
    {
        $encryption = strtolower((string) ($this->envSmtp['encryption'] ?? ''));

        return [
            'host' => $this->envSmtp['host'] ?? '',
            'port' => $this->envSmtp['port'] ?? 587,
            'encryption' => in_array($encryption, ['tls', 'ssl'], true) ? $encryption : 'none',
            'username' => $this->envSmtp['username'] ?? '',
            'from_address' => $this->envFrom['address'] ?? '',
            'from_name' => $this->envFrom['name'] ?? '',
        ];
    }

    /**
     * A blank password keeps the current one, but only for the same server and account: otherwise changing the
     * host would be enough to have the stored password sent to another server.
     */
    private function encryptedPassword(array $before, string $host, string $username, string $password): string
    {
        if ($username === '') {
            return '';
        }

        if ($password !== '') {
            return Crypt::encryptString($password);
        }

        $sameAccount = strcasecmp($before['host'], $host) === 0 && $before['username'] === $username;
        if (!$sameAccount || !$before['has_password']) {
            throw ValidationException::withMessages([
                'password' => __('Enter the SMTP password. It is required when the server or username changes.'),
            ]);
        }

        $stored = $this->stored();

        return $stored !== null ? $stored['password'] : Crypt::encryptString((string) $this->envSmtp['password']);
    }

    /** Null when a saved password cannot be read with the current APP_KEY. */
    private function decrypt(string $value): ?string
    {
        if ($value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }
}
