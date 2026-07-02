<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'instance_id',
        'uuid',
        'whatsapp_message_id',
        'from',
        'to',
        'chat_type',
        'participant',
        'from_me',
        'type',
        'status',
        'body',
        'media_path',
        'media_mime',
        'media_name',
        'error',
        'sender_name',
        'sender_phone',
        'received_at',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected $casts = [
        'from_me'      => 'boolean',
        'received_at'  => 'datetime',
        'sent_at'      => 'datetime',
        'delivered_at' => 'datetime',
        'read_at'      => 'datetime',
    ];
}
