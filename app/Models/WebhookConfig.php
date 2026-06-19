<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookConfig extends Model
{
    protected $fillable = ['instance_id', 'event', 'url', 'active', 'secret'];

    protected $casts = ['active' => 'boolean'];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class);
    }
}
