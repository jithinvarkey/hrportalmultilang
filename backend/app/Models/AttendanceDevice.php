<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class AttendanceDevice extends Model
{
    /** ZKTeco BioTime 8.x default endpoint paths, used when a device leaves them null. */
    public const DEFAULTS = [
        'auth_path'          => '/jwt-api-token-auth/',
        'transactions_path'  => '/att/api/transactionHistory/',
        'employees_path'     => '/personnel/api/employees/',
        'token_ttl_minutes'  => 300,   // 5 hours
        'page_size'          => 500,
    ];

    protected $fillable = [
        'name','brand','ip_address','port','protocol','api_path','api_key',
        'username','password','timeout_seconds','employee_number_field',
        'auth_path','transactions_path','employees_path','token_ttl_minutes','page_size',
        'is_active','last_synced_at','last_sync_status','last_sync_count','last_sync_error',
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'last_synced_at'    => 'datetime',
        'token_ttl_minutes' => 'integer',
        'page_size'         => 'integer',
        'port'              => 'integer',
        'timeout_seconds'   => 'integer',
    ];

    protected $hidden = ['password','api_key'];

    /**
     * Base URL built from the stored protocol, host, and port.
     */
    public function getBaseUrlAttribute(): string
    {
        return rtrim("{$this->protocol}://{$this->ip_address}:{$this->port}", '/');
    }

    /**
     * Resolve a configurable endpoint path, falling back to the BioTime default.
     *
     * @param string $key One of: auth_path, transactions_path, employees_path.
     */
    public function endpoint(string $key): string
    {
        $value = trim((string) ($this->getAttribute($key) ?? ''));
        $path  = $value !== '' ? $value : (self::DEFAULTS[$key] ?? '');
        return '/' . ltrim($path, '/');
    }

    /** Token cache lifetime in minutes (configurable per device). */
    public function tokenTtlMinutes(): int
    {
        return (int) ($this->token_ttl_minutes ?: self::DEFAULTS['token_ttl_minutes']);
    }

    /** Page size for paginated pulls (configurable per device). */
    public function pageSize(): int
    {
        return (int) ($this->page_size ?: self::DEFAULTS['page_size']);
    }

    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getPasswordAttribute($value): ?string
    {
        try { return $value ? Crypt::decryptString($value) : null; }
        catch (\Throwable) { return null; }
    }

    public function setApiKeyAttribute($value)
    {
        $this->attributes['api_key'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getApiKeyAttribute($value): ?string
    {
        try { return $value ? Crypt::decryptString($value) : null; }
        catch (\Throwable) { return null; }
    }

    public function deviceLogs() { return $this->hasMany(DeviceAttendanceLog::class, 'device_id'); }
}
