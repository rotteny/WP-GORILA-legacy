<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp Piloto — Gorila</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body style="background:#f8fafc; min-height:100vh; margin:0;">
    <div id="wa-app" data-instance-slug="{{ $slug }}"></div>
</body>
</html>
