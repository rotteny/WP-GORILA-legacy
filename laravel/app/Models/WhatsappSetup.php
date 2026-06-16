<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappSetup extends Model
{
    protected $fillable = [
        'status',
        'qr_code',
        'qr_data_url',
        'last_event_at',
    ];

    protected $casts = [
        'last_event_at' => 'datetime',
    ];
}
