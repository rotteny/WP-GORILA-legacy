<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'instance_id',
        'whatsapp_message_id',
        'from',
        'chat_type',
        'participant',
        'from_me',
        'type',
        'body',
        'sender_name',
        'sender_phone',
        'received_at',
    ];

    protected $casts = [
        'from_me'     => 'boolean',
        'received_at' => 'datetime',
    ];
}
