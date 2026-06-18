<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiKey extends Model
{
    protected $fillable = [
        'name',
        'key_hash',
        'last_used_at',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public static function hashKey(string $raw): string
    {
        return hash('sha256', $raw);
    }

    public static function generateRawKey(): string
    {
        return 'wpg_' . bin2hex(random_bytes(24));
    }

    public static function findActiveByRaw(string $raw): ?self
    {
        return static::query()
            ->where('key_hash', static::hashKey($raw))
            ->where('active', true)
            ->first();
    }

    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }
}
