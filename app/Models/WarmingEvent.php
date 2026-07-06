<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de uma mensagem de aquecimento trocada entre duas instâncias de um
 * projeto. Gravado pelo engine no Node via POST /internal/warming-events.
 */
class WarmingEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'sender_slug',
        'receiver_slug',
        'script_id',
        'status',
        'error',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
