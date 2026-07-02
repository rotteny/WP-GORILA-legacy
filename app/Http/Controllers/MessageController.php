<?php

namespace App\Http\Controllers;

use App\Exceptions\TargetUnavailableException;
use App\Jobs\SendWhatsAppMedia;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Instance;
use App\Models\Message;
use App\Models\Project;
use App\Services\InstanceResolver;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Envio ASSÍNCRONO de mensagens (API v1). Ao contrário do WhatsAppController — que
 * envia síncrono e devolve o resultado do WhatsApp na hora — aqui a mensagem é
 * persistida como `queued`, enfileirada e o endpoint responde 202 imediatamente.
 * O worker ({@see SendWhatsAppMessage}) faz o envio e atualiza o status.
 *
 * @group Envio assíncrono
 *
 * Enfileira a mensagem e responde na hora com `202 { id, status: "queued" }`. O
 * envio de verdade acontece em background; acompanhe o ciclo de vida
 * (`message.sent`, `message.delivered`, `message.read`) pelos webhooks de saída.
 */
class MessageController extends Controller
{
    public function __construct(private InstanceResolver $resolver)
    {
    }

    /**
     * Enviar texto (pela chave)
     *
     * Destino resolvido pelo escopo da própria API key (projeto com failover, ou instância).
     *
     * @authenticated
     *
     * @bodyParam number string O número em formato internacional, só dígitos. Obrigatório sem `jid`. Example: 5511999998888
     * @bodyParam jid string O JID completo do WhatsApp. Obrigatório sem `number`. Example: 5511999998888@s.whatsapp.net
     * @bodyParam message string required O texto da mensagem. Example: Olá do Gorila!
     *
     * @response 202 {"id": "9f1c2e3a-...", "status": "queued"}
     * @response 409 {"ok": false, "error": "instância não conectada", "status": "LOGGED_OUT"}
     * @response 422 {"message": "The message field is required.", "errors": {"message": ["The message field is required."]}}
     */
    public function sendTextByKey(Request $request): JsonResponse
    {
        return $this->enqueue(
            $request,
            fn () => $this->resolver->fromApiKey($request->attributes->get('api_key')),
        );
    }

    /**
     * Enviar texto (por instância)
     *
     * Enfileira para uma instância específica (precisa estar conectada).
     *
     * @authenticated
     *
     * @urlParam instance string required Slug da instância. Example: tik1
     *
     * @bodyParam number string O número em formato internacional, só dígitos. Obrigatório sem `jid`. Example: 5511999998888
     * @bodyParam jid string O JID completo do WhatsApp. Obrigatório sem `number`. Example: 5511999998888@s.whatsapp.net
     * @bodyParam message string required O texto da mensagem. Example: Olá do Gorila!
     *
     * @response 202 {"id": "9f1c2e3a-...", "status": "queued"}
     */
    public function sendTextInstance(Request $request, Instance $instance): JsonResponse
    {
        return $this->enqueue($request, fn () => $this->resolver->requireConnected($instance));
    }

    /**
     * Enviar texto (por projeto)
     *
     * Resolve o telefone ativo do projeto e enfileira por ele; o failover é transparente.
     *
     * @authenticated
     *
     * @urlParam project string required Slug do projeto. Example: atendimento
     *
     * @bodyParam number string O número em formato internacional, só dígitos. Obrigatório sem `jid`. Example: 5511999998888
     * @bodyParam jid string O JID completo do WhatsApp. Obrigatório sem `number`. Example: 5511999998888@s.whatsapp.net
     * @bodyParam message string required O texto da mensagem. Example: Olá do Gorila!
     *
     * @response 202 {"id": "9f1c2e3a-...", "status": "queued"}
     */
    public function sendTextProject(Request $request, Project $project): JsonResponse
    {
        return $this->enqueue($request, fn () => $this->resolver->forProject($project));
    }

    /**
     * Enviar mídia (pela chave)
     *
     * Destino resolvido pelo escopo da API key. O arquivo é guardado e enviado em background.
     *
     * @authenticated
     *
     * @bodyParam number string O número em formato internacional, só dígitos. Obrigatório sem `jid`. Example: 5511999998888
     * @bodyParam jid string O JID completo do WhatsApp. Obrigatório sem `number`. Example: 5511999998888@s.whatsapp.net
     * @bodyParam caption string Legenda opcional (imagem/vídeo/documento). Example: Segue o comprovante
     * @bodyParam file file required O arquivo a enviar (até 25 MB). No-example
     *
     * @response 202 {"id": "9f1c2e3a-...", "status": "queued"}
     */
    public function sendMediaByKey(Request $request): JsonResponse
    {
        return $this->enqueueMedia(
            $request,
            fn () => $this->resolver->fromApiKey($request->attributes->get('api_key')),
        );
    }

    /**
     * Enviar mídia (por instância)
     *
     * @authenticated
     *
     * @urlParam instance string required Slug da instância. Example: tik1
     *
     * @bodyParam number string O número em formato internacional, só dígitos. Obrigatório sem `jid`. Example: 5511999998888
     * @bodyParam jid string O JID completo do WhatsApp. Obrigatório sem `number`. Example: 5511999998888@s.whatsapp.net
     * @bodyParam caption string Legenda opcional. Example: Segue o comprovante
     * @bodyParam file file required O arquivo a enviar (até 25 MB). No-example
     *
     * @response 202 {"id": "9f1c2e3a-...", "status": "queued"}
     */
    public function sendMediaInstance(Request $request, Instance $instance): JsonResponse
    {
        return $this->enqueueMedia($request, fn () => $this->resolver->requireConnected($instance));
    }

    /**
     * Enviar mídia (por projeto)
     *
     * @authenticated
     *
     * @urlParam project string required Slug do projeto. Example: atendimento
     *
     * @bodyParam number string O número em formato internacional, só dígitos. Obrigatório sem `jid`. Example: 5511999998888
     * @bodyParam jid string O JID completo do WhatsApp. Obrigatório sem `number`. Example: 5511999998888@s.whatsapp.net
     * @bodyParam caption string Legenda opcional. Example: Segue o comprovante
     * @bodyParam file file required O arquivo a enviar (até 25 MB). No-example
     *
     * @response 202 {"id": "9f1c2e3a-...", "status": "queued"}
     */
    public function sendMediaProject(Request $request, Project $project): JsonResponse
    {
        return $this->enqueueMedia($request, fn () => $this->resolver->forProject($project));
    }

    /**
     * Valida o corpo, resolve o destino, persiste como `queued` e enfileira.
     *
     * @param  Closure():Instance  $resolve
     */
    private function enqueue(Request $request, Closure $resolve): JsonResponse
    {
        $data = $request->validate([
            'number'  => 'required_without:jid|string',
            'jid'     => 'required_without:number|string',
            'message' => 'required|string',
        ]);

        try {
            $instance = $resolve();
        } catch (TargetUnavailableException $e) {
            return response()->json($e->payload, $e->status);
        }

        $message = Message::create([
            'instance_id' => $instance->slug,
            'uuid'        => (string) Str::uuid(),
            'to'          => $data['jid'] ?? $data['number'],
            'from_me'     => true,
            'type'        => 'text',
            'status'      => 'queued',
            'body'        => $data['message'],
        ]);

        SendWhatsAppMessage::dispatch($message->id);

        return response()->json(['id' => $message->uuid, 'status' => 'queued'], 202);
    }

    /**
     * Igual ao {@see enqueue()}, mas pra mídia: guarda o arquivo no disk 'local'
     * (o worker roda depois do 202, então os bytes do upload precisam sobreviver) e
     * enfileira o {@see SendWhatsAppMedia}, que envia e apaga o arquivo no fim.
     *
     * @param  Closure():Instance  $resolve
     */
    private function enqueueMedia(Request $request, Closure $resolve): JsonResponse
    {
        $data = $request->validate([
            'number'  => 'required_without:jid|string',
            'jid'     => 'required_without:number|string',
            'caption' => 'nullable|string|max:1024',
            'file'    => 'required|file|max:25600',
        ]);

        try {
            $instance = $resolve();
        } catch (TargetUnavailableException $e) {
            return response()->json($e->payload, $e->status);
        }

        $file = $request->file('file');
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $path = $file->store('outbound-media', 'local');

        $type = match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default                          => 'document',
        };

        $message = Message::create([
            'instance_id' => $instance->slug,
            'uuid'        => (string) Str::uuid(),
            'to'          => $data['jid'] ?? $data['number'],
            'from_me'     => true,
            'type'        => $type,
            'status'      => 'queued',
            'body'        => $data['caption'] ?? null,
            'media_path'  => $path,
            'media_mime'  => $mime,
            'media_name'  => $file->getClientOriginalName(),
        ]);

        SendWhatsAppMedia::dispatch($message->id);

        return response()->json(['id' => $message->uuid, 'status' => 'queued'], 202);
    }
}
