<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookEndpoint extends Model
{
    protected $fillable = [
        'instance_id',
        'name',
        'url',
        'secret',
        'active',
        'events',
        'last_success_at',
        'last_failure_at',
        'consecutive_failures',
    ];

    protected $casts = [
        'active' => 'boolean',
        'events' => 'array',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'consecutive_failures' => 'integer',
    ];

    /**
     * `secret` jamais e serializado em respostas — Resource ja controla,
     * mas hidden e o cinto-suspensorio caso algum endpoint use ->toArray().
     */
    protected $hidden = ['secret'];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
