<?php

declare(strict_types=1);

return [

    'service_url' => env('WHATSAPP_SERVICE_URL', 'http://whatsapp-service:3000'),

    'webhook_secret' => env('WEBHOOK_SECRET', ''),

    // Rate limit por API key (middleware api.throttle).
    // Aplicado em todo o grupo /api/v1; default 60 req/min.
    'rate_limit_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 60),

    // Throttle anti-ban por instancia (servico AntiBanThrottle).
    // Janela deslizante: max `anti_ban_burst` envios em
    // `anti_ban_burst / anti_ban_limit_per_second` segundos.
    'anti_ban_limit_per_second' => (int) env('ANTI_BAN_LIMIT_PER_SECOND', 1),
    'anti_ban_burst' => (int) env('ANTI_BAN_BURST', 5),

    'webhooks' => [
        'local_allowlist' => array_filter(array_map('trim', explode(',', (string) env('WEBHOOK_LOCAL_ALLOWLIST', 'localhost,127.0.0.1')))),
        'request_timeout' => (int) env('WEBHOOK_REQUEST_TIMEOUT', 10),
        'max_body_kb' => (int) env('WEBHOOK_MAX_BODY_KB', 256),
        'deactivate_after_failures' => (int) env('WEBHOOK_DEACTIVATE_AFTER_FAILURES', 20),
    ],

    'media' => [
        'disk' => env('WHATSAPP_MEDIA_DISK', 'whatsapp_media'),
        'max_mb' => (int) env('WHATSAPP_MEDIA_MAX_MB', 20),
        'path_prefix' => 'media',

        'allowed_mimes' => [
            'image' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            'video' => ['video/mp4', 'video/quicktime', 'video/3gpp'],
            'audio' => ['audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/opus', 'audio/webm'],
            'document' => [
                'application/pdf',
                'application/zip',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/plain',
                'application/octet-stream',
            ],
        ],
    ],

];
