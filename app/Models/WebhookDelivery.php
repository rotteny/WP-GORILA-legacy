<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    protected $fillable = [
        'webhook_config_id',
        'instance_id',
        'event',
        'url',
        'attempt',
        'success',
        'status_code',
        'response_body',
        'error',
    ];

    protected $casts = [
        'success' => 'boolean',
    ];

    public function webhookConfig(): BelongsTo
    {
        return $this->belongsTo(WebhookConfig::class);
    }
}
