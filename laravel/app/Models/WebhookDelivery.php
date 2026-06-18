<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WebhookDeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    protected $fillable = [
        'webhook_endpoint_id',
        'message_id',
        'event',
        'payload',
        'attempt',
        'max_attempts',
        'status',
        'response_status',
        'response_body_excerpt',
        'error_message',
        'delivered_at',
        'failed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'status' => WebhookDeliveryStatus::class,
        'attempt' => 'integer',
        'max_attempts' => 'integer',
        'response_status' => 'integer',
    ];

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
