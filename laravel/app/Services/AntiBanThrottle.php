<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Redis;

/**
 * Throttle de envio de mensagens por instancia (anti-ban).
 *
 * O WhatsApp pode banir numeros que enviam rapido demais ou em rajadas
 * grandes. Este servico implementa uma janela deslizante simples por slug
 * de instancia: guarda os timestamps dos ultimos N (= burst) envios; se
 * o burst inteiro foi consumido em menos de `burst / limit` segundos,
 * pede que o caller espere o restante antes de tentar de novo.
 *
 * Uso esperado: no `handle()` do job de envio, antes de chamar o Node:
 *
 *     $wait = $throttle->attempt($instance->slug);
 *     if ($wait > 0) { $this->release($wait); return; }
 *
 * O Redis e usado como store distribuido — se o projeto evoluir para
 * multiplos workers, o throttle permanece consistente entre eles.
 */
class AntiBanThrottle
{
    private const REDIS_KEY_PREFIX = 'anti_ban:';
    private const REDIS_KEY_TTL = 60;

    public function __construct(
        private readonly int $limitPerSecond,
        private readonly int $burst,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            limitPerSecond: max(1, (int) config('whatsapp.anti_ban_limit_per_second', 1)),
            burst: max(1, (int) config('whatsapp.anti_ban_burst', 5)),
        );
    }

    /**
     * Tenta consumir um slot pra essa instancia. Retorna a quantidade de
     * segundos que o caller deve esperar (0 = pode enviar agora).
     */
    public function attempt(string $slug): int
    {
        $key = self::REDIS_KEY_PREFIX . $slug;
        $now = time();

        Redis::lpush($key, (string) $now);
        Redis::ltrim($key, 0, $this->burst - 1);
        Redis::expire($key, self::REDIS_KEY_TTL);

        $timestamps = array_map('intval', (array) Redis::lrange($key, 0, $this->burst - 1));
        if (count($timestamps) < $this->burst) {
            return 0;
        }

        $oldest = (int) end($timestamps);
        $elapsed = $now - $oldest;
        $minimumRequired = (int) ceil($this->burst / $this->limitPerSecond);

        if ($elapsed < $minimumRequired) {
            return $minimumRequired - $elapsed;
        }

        return 0;
    }
}
