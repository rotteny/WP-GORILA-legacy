@component('mail::message')
# Aviso de failover — {{ $project->name }}

@if($toSlug)
O telefone ativo do projeto **{{ $project->name }}** (`{{ $project->slug }}`) caiu/foi bloqueado e o sistema trocou automaticamente para o backup.

- **Caiu:** `{{ $fromSlug ?? '—' }}`
- **Assumiu (novo ativo):** `{{ $toSlug }}`
- **Motivo:** {{ $reason }}

As mensagens continuam saindo normalmente pelo novo telefone. Recomendamos reconectar o número que caiu.
@else
⚠️ **Atenção:** o telefone ativo do projeto **{{ $project->name }}** (`{{ $project->slug }}`) caiu/foi bloqueado e **não há nenhum backup conectado**. O projeto está **sem telefone** para enviar mensagens até alguém reconectar um número.

- **Caiu:** `{{ $fromSlug ?? '—' }}`
- **Motivo:** {{ $reason }}
@endif

@component('mail::button', ['url' => rtrim(config('app.url'), '/').'/projetos/'.$project->slug])
Abrir projeto no painel
@endcomponent

{{ config('app.name') }}
@endcomponent
