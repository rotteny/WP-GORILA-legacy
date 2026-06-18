<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class);
    }

    /**
     * Alias usado pelo route model binding com scopeBindings (segmento `{webhook}`).
     * Laravel resolve o filho via `parent->webhooks()`; manter o nome canonico
     * `webhookEndpoints` para clareza de dominio e este alias para o router.
     */
    public function webhooks(): HasMany
    {
        return $this->webhookEndpoints();
    }
}
