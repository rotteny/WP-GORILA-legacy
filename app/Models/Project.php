<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    /** Config default do aquecimento quando warming_config é null. */
    public const WARMING_DEFAULTS = [
        'intensity'    => 'media', // baixa | media | alta
        'window_start' => 8,       // hora do dia (0-23) em que o aquecimento pode rodar
        'window_end'   => 22,
    ];

    protected $fillable = [
        'slug',
        'name',
        'responsible_email',
        'active_instance_id',
        'failover_webhook_url',
        'failover_webhook_secret',
        'warming_enabled',
        'warming_config',
        'warming_paused_at',
    ];

    protected $hidden = ['failover_webhook_secret'];

    protected $casts = [
        'warming_enabled'   => 'boolean',
        'warming_config'    => 'array',
        'warming_paused_at' => 'datetime',
    ];

    /** Config efetiva do aquecimento: defaults sobrescritos pelo que o projeto salvou. */
    public function effectiveWarmingConfig(): array
    {
        return array_merge(self::WARMING_DEFAULTS, $this->warming_config ?? []);
    }

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

    /**
     * Histórico de mensagens de aquecimento trocadas entre as instâncias do projeto.
     */
    public function warmingEvents(): HasMany
    {
        return $this->hasMany(WarmingEvent::class);
    }
}
