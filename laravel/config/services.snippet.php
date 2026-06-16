<?php

// Snippet a ser ADICIONADO no array de retorno de config/services.php
// do Laravel (não substituir o arquivo inteiro).

return [
    // ...

    'whatsapp' => [
        'url' => env('WHATSAPP_SERVICE_URL', 'http://whatsapp-service:3000'),
    ],
];
