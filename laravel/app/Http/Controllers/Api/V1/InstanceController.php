<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InstanceResource;
use App\Models\Instance;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Projetos
 *
 * Endpoints para consultar projetos (instancias WhatsApp) ja provisionados.
 * Cada projeto corresponde a um numero conectado via Baileys e e identificado
 * por um `slug` legivel.
 */
class InstanceController extends Controller
{
    /**
     * Listar projetos
     *
     * Retorna todas as instancias WhatsApp provisionadas, ordenadas por id
     * crescente. Util para listar opcoes em painel ou descobrir o `slug`
     * exato a usar nos demais endpoints.
     *
     * @response 200 scenario="sucesso" {
     *   "data": [
     *     {
     *       "slug": "gorila-vendas",
     *       "name": "Gorila Vendas",
     *       "status": "CONNECTED",
     *       "last_event_at": "2026-06-18T13:42:11+00:00"
     *     },
     *     {
     *       "slug": "gorila-suporte",
     *       "name": "Gorila Suporte",
     *       "status": "DISCONNECTED",
     *       "last_event_at": "2026-06-17T22:10:55+00:00"
     *     }
     *   ]
     * }
     * @response 401 scenario="sem X-API-Key" {"error": "missing API key", "code": "AUTH_MISSING"}
     */
    public function index(): AnonymousResourceCollection
    {
        return InstanceResource::collection(
            Instance::query()->orderBy('id')->get()
        );
    }

    /**
     * Detalhar projeto
     *
     * Retorna o detalhe de uma instancia especifica pelo `slug`.
     *
     * @urlParam instance string required Slug do projeto. Example: gorila-vendas
     *
     * @response 200 scenario="sucesso" {
     *   "data": {
     *     "slug": "gorila-vendas",
     *     "name": "Gorila Vendas",
     *     "status": "CONNECTED",
     *     "last_event_at": "2026-06-18T13:42:11+00:00"
     *   }
     * }
     * @response 404 scenario="projeto inexistente" {"message": "No query results for model [App\\Models\\Instance] gorila-vendas"}
     */
    public function show(Instance $instance): InstanceResource
    {
        return new InstanceResource($instance);
    }
}
