<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Lançada quando não é possível resolver um telefone de destino conectado
 * (projeto sem telefone ativo, instância desconectada, chave sem escopo, etc).
 * Carrega o corpo JSON e o status HTTP que o controller deve devolver.
 */
class TargetUnavailableException extends RuntimeException
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function __construct(public array $payload, public int $status = 409)
    {
        parent::__construct($payload['error'] ?? 'destino indisponível');
    }
}
