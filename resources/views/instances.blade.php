<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projetos — WhatsApp Piloto Gorila</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>html, body { margin: 0; min-height: 100%; }</style>
</head>
<body>
    <div id="wa-instances-app"></div>
</body>
</html>
