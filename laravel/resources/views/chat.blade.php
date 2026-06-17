<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat — WhatsApp Piloto Gorila</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>html, body { margin: 0; height: 100%; }</style>
</head>
<body>
    <div id="wa-chat-app" data-instance-slug="{{ $slug }}"></div>
</body>
</html>
