<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Instance extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'slug',
        'name',
        'status',
        'priority',
        'qr_code',
        'qr_data_url',
        'last_event_at',
    ];

    protected $casts = [
        'last_event_at' => 'datetime',
        'priority'      => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function webhookConfigs(): HasMany
    {
        return $this->hasMany(WebhookConfig::class);
    }

    /**
     * Projeto dono deste telefone (nulo se a instância for avulsa).
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
