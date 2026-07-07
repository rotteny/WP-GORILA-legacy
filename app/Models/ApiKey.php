<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'instance_slug',
        'name',
        'key_prefix',
        'key_hash',
        'hmac_secret',
        'hostname_hint',
        'last_used_at',
        'revoked_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    protected $hidden = ['key_hash', 'hmac_secret'];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'instance_slug', 'slug');
    }

    /**
     * Projeto a que a chave está escopada (quando é uma chave de projeto).
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Gera uma nova API key.
     *
     * Retorna ['plaintext' => 'wpg_...', 'prefix' => 'wpg_...', 'hash' => '...'].
     * O plaintext só é retornado UMA VEZ — depois disso, só o prefix fica visível.
     */
    public static function generate(): array
    {
        $token     = Str::random(40);
        $plaintext = 'wpg_' . $token;
        $prefix    = substr($plaintext, 0, 12);  // wpg_ + 8 chars
        $hash      = password_hash($plaintext, PASSWORD_BCRYPT);

        return compact('plaintext', 'prefix', 'hash');
    }

    public function verify(string $plaintext): bool
    {
        return password_verify($plaintext, $this->key_hash);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
