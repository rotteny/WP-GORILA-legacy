<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEndpoint extends Model
{
    protected $fillable = [
        'instance_id',
        'name',
        'url',
        'secret',
        'active',
        'events',
    ];

    protected $casts = [
        'active' => 'boolean',
        'events' => 'array',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }
}
