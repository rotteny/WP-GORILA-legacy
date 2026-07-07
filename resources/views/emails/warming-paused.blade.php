@component('mail::message')
# Aquecimento pausado — {{ $project->name }}

⚠️ O aquecimento do projeto **{{ $project->name }}** (`{{ $project->slug }}`) foi **pausado automaticamente**: o telefone `{{ $instanceSlug }}` caiu/foi bloqueado durante o warming.

Isso pode ser um sinal de que o padrão de aquecimento foi detectado. Por segurança, o sistema parou o warming de **todos** os telefones do projeto e **não vai retomar sozinho**.

**O que fazer:**

1. Verifique o número que caiu (`{{ $instanceSlug }}`) — reconecte ou substitua.
2. Revise a configuração de aquecimento (intensidade/janela) se necessário.
3. Reative o aquecimento manualmente pelo painel quando estiver tudo certo.

@component('mail::button', ['url' => rtrim(config('app.url'), '/').'/projetos/'.$project->slug])
Abrir projeto no painel
@endcomponent

{{ config('app.name') }}
@endcomponent
