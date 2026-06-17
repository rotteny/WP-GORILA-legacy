<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Instance extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'status',
        'qr_code',
        'qr_data_url',
        'last_event_at',
    ];

    protected $casts = [
        'last_event_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
