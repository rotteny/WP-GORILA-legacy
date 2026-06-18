<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Webhooks — WhatsApp Piloto Gorila</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        // API key da UI para chamar /api/v1/* (header X-API-Key).
        // Definida via env WHATSAPP_UI_API_KEY — o LIGHT/NEZUKO precisa decidir
        // se isso é aceitável no piloto ou se devemos duplicar as rotas internas.
        window.__WP_API_KEY__ = @json(env('WHATSAPP_UI_API_KEY', ''));
    </script>
</head>
<body style="margin:0; background:#212121; min-height:100vh">
    <div id="wa-webhooks-app" data-instance-slug="{{ $slug }}"></div>
</body>
</html>
