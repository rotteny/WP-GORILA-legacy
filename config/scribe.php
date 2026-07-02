<?php

/**
 * Config do Scribe — gera a doc interativa da API pública em /api/docs e o
 * OpenAPI 3 em /api/docs/openapi.yaml. Só documentamos o prefixo `api/v1/*`
 * (a API versionada, autenticada por chave); as rotas de painel (auth:web) e o
 * webhook interno ficam de fora de propósito.
 *
 * Mantido com VALORES PLANOS (sem `use` de classes do Scribe) de propósito: este
 * arquivo é carregado em todo boot do Laravel, então não pode depender do pacote
 * estar instalado. As chaves omitidas (ex.: `strategies`) usam os defaults do Scribe.
 *
 * Depois de editar annotations rode: `php artisan scribe:generate`.
 */
return [
    'title' => 'WhatsApp Gorila — API',

    'description' => 'API pública de envio de mensagens e webhooks de saída. Autenticação por chave (Authorization: Bearer wpg_...).',

    // URL base exibida nos exemplos. Null usa a APP_URL.
    'base_url' => env('APP_URL'),

    // Serve a doc via rotas Laravel (respeita middleware) em vez de HTML estático.
    'type' => 'laravel',

    'theme' => 'default',

    'static' => [
        'output_path' => 'public/docs',
    ],

    'laravel' => [
        'add_routes' => true,
        'docs_url' => '/api/docs',
        'assets_directory' => null,
        'middleware' => [],
    ],

    'try_it_out' => [
        'enabled' => true,
        'base_url' => null,
        'use_csrf' => false,
    ],

    'openapi' => [
        'enabled' => true,
        'overrides' => [],
    ],

    'postman' => [
        'enabled' => true,
        'overrides' => [],
    ],

    // Quais rotas entram na doc: só a API pública versionada.
    'routes' => [
        [
            'match' => [
                'prefixes' => ['api/v1/*'],
                'domains' => ['*'],
            ],
            'include' => [],
            'exclude' => [],
        ],
    ],

    // Autenticação: todo o prefixo api/v1/* está atrás do middleware `api-key`,
    // então marcamos tudo como autenticado por padrão.
    'auth' => [
        'enabled' => true,
        'default' => true,
        'in' => 'bearer',
        'name' => 'Authorization',
        'use_value' => env('SCRIBE_AUTH_KEY'),
        'placeholder' => 'wpg_sua_chave_aqui',
        'extra_info' => 'Gere uma chave no painel (Chaves de API). Envie em `Authorization: Bearer wpg_...` ou no header `X-API-Key`.',
    ],

    'intro_text' => <<<'INTRO'
        Esta documentação cobre a **API pública** do WhatsApp Gorila.

        <aside>Envie sempre a chave de API no header `Authorization: Bearer wpg_...`. O limite é de 60 requisições por minuto por chave.</aside>
        INTRO,

    'example_languages' => ['bash', 'javascript', 'php'],

    'logo' => false,

    'last_updated' => 'Last updated: {date:F j, Y}',

    'groups' => [
        'default' => 'Endpoints',
        'order' => [],
    ],

    'examples' => [
        'faker_seed' => 1234,
    ],

    'fractal' => [
        'serializer' => null,
    ],
];
