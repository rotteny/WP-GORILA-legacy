<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Instance extends Model
{
    use HasFactory;

    /** Duração total da rampa de aquecimento de um chip novo (dias). */
    public const WARMING_RAMP_DAYS = 14;

    /** Curva da rampa: [dia, fração do volume alvo]. Interpolada linearmente. */
    private const RAMP_POINTS = [[1, 0.2], [3, 0.4], [7, 0.7], [14, 1.0]];

    protected $fillable = [
        'project_id',
        'slug',
        'name',
        'status',
        'priority',
        'warming_only',
        'warming_started_at',
        'warming_skip_ramp',
        'qr_code',
        'qr_data_url',
        'last_event_at',
    ];

    protected $casts = [
        'last_event_at'      => 'datetime',
        'priority'           => 'integer',
        'warming_only'       => 'boolean',
        'warming_started_at' => 'datetime',
        'warming_skip_ramp'  => 'boolean',
    ];

    /** Expõe a rampa no JSON (a UI usa pra badge "Aquecendo (dia X/14)"). */
    protected $appends = ['warming_ramp'];

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

    /**
     * Estado da rampa de aquecimento deste chip:
     *   ['ramping' => bool, 'day' => int|null, 'total_days' => 14, 'fraction' => float]
     * fraction é o multiplicador (0.2..1.0) aplicado ao volume de warming do chip.
     * Sem warming_started_at ou com skip ligado → 100% (não está em rampa).
     */
    public function warmingRamp(): array
    {
        $total = self::WARMING_RAMP_DAYS;

        if (! $this->warming_started_at || $this->warming_skip_ramp) {
            return ['ramping' => false, 'day' => null, 'total_days' => $total, 'fraction' => 1.0];
        }

        $elapsed = max(0.0, $this->warming_started_at->floatDiffInDays(now()));
        if ($elapsed >= $total) {
            return ['ramping' => false, 'day' => null, 'total_days' => $total, 'fraction' => 1.0];
        }

        $day = min($total, (int) floor($elapsed) + 1);

        return [
            'ramping'    => true,
            'day'        => $day,
            'total_days' => $total,
            'fraction'   => round(self::rampFraction($elapsed), 3),
        ];
    }

    public function getWarmingRampAttribute(): array
    {
        return $this->warmingRamp();
    }

    /** Interpolação linear da curva RAMP_POINTS pra `elapsedDays` dias corridos. */
    private static function rampFraction(float $elapsedDays): float
    {
        $pts = self::RAMP_POINTS;
        if ($elapsedDays <= $pts[0][0]) {
            return $pts[0][1];
        }
        for ($i = 0; $i < count($pts) - 1; $i++) {
            [$x0, $y0] = $pts[$i];
            [$x1, $y1] = $pts[$i + 1];
            if ($elapsedDays <= $x1) {
                $t = ($elapsedDays - $x0) / ($x1 - $x0);
                return $y0 + $t * ($y1 - $y0);
            }
        }
        return 1.0;
    }

    /**
     * Chips que JÁ completaram a rampa (ou nunca entraram nela / têm override).
     * O failover prefere estes — um chip novo em rampa só assume tráfego real
     * quando não há um chip pronto disponível.
     */
    public function scopeFullyRamped(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('warming_started_at')
                ->orWhere('warming_skip_ramp', true)
                ->orWhere('warming_started_at', '<=', now()->subDays(self::WARMING_RAMP_DAYS));
        });
    }
}
