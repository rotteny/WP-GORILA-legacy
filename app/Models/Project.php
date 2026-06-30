<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'responsible_email',
        'active_instance_id',
        'failover_webhook_url',
        'failover_webhook_secret',
    ];

    protected $hidden = ['failover_webhook_secret'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Telefones (instâncias) que pertencem ao projeto, em ordem de failover.
     */
    public function instances(): HasMany
    {
        return $this->hasMany(Instance::class)->orderBy('priority');
    }

    /**
     * Telefone ativo atual — por onde as mensagens do projeto saem.
     */
    public function activeInstance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'active_instance_id');
    }
}
