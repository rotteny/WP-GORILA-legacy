<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class RepairTask extends Model
{
    protected $fillable = [
        'instance_id',
        'instance_slug',
        'pairing_code',
        'status',
        'dispatched_to',
        'dispatched_at',
        'result_at',
        'error_message',
        'screenshot_ref',
        'attempt',
        'expires_at',
    ];

    protected $casts = [
        'dispatched_at' => 'datetime',
        'result_at'     => 'datetime',
        'expires_at'    => 'datetime',
        'attempt'       => 'integer',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }

    // pairing_code é encriptado no banco. Accessor/mutator descriptografam transparentemente.
    public function setPairingCodeAttribute(?string $value): void
    {
        $this->attributes['pairing_code'] = $value === null ? null : Crypt::encryptString($value);
    }

    public function getPairingCodeAttribute(?string $value): ?string
    {
        return $value === null ? null : Crypt::decryptString($value);
    }
}
