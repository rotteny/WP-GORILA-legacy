<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CockpitHeartbeat extends Model
{
    protected $fillable = [
        'api_key_id',
        'hostname',
        'devices_online',
        'received_at',
    ];

    protected $casts = [
        'devices_online' => 'array',
        'received_at'    => 'datetime',
    ];
}
