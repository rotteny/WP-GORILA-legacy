<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\WebhookEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:128',
            'url' => 'required|url|max:2048',
            'events' => 'required|array|min:1',
            'events.*' => ['required', 'string', Rule::in(WebhookEvent::values())],
        ];
    }

    protected function prepareForValidation(): void
    {
        $events = $this->input('events');
        if (is_array($events)) {
            $this->merge([
                'events' => array_values(array_unique(array_filter(
                    $events,
                    static fn ($v) => is_string($v) && $v !== '',
                ))),
            ]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $url = (string) $this->input('url', '');
            if ($url === '') {
                return;
            }

            $parsed = parse_url($url);
            if (!is_array($parsed) || empty($parsed['host'])) {
                $v->errors()->add('url', 'URL invalida.');
                return;
            }

            $this->assertSchemeAndHost($v, $parsed);

            if (app()->environment('production')) {
                $this->assertPublicHost($v, (string) $parsed['host']);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function assertSchemeAndHost(Validator $v, array $parsed): void
    {
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        $host = strtolower((string) $parsed['host']);

        if ($scheme === 'https') {
            return;
        }

        if ($scheme === 'http' && in_array($host, $this->localAllowlist(), true)) {
            return;
        }

        $v->errors()->add('url', 'url precisa ser https (exceto hosts permitidos).');
    }

    /**
     * Anti-SSRF basico em producao: bloqueia hosts que resolvem
     * para faixas privadas, loopback ou link-local.
     */
    private function assertPublicHost(Validator $v, string $host): void
    {
        $ips = @gethostbynamel($host) ?: [];

        foreach ($ips as $ip) {
            if (!filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            )) {
                $v->errors()->add('url', 'URL nao pode apontar para hosts internos.');
                return;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function localAllowlist(): array
    {
        $list = (array) config('whatsapp.webhooks.local_allowlist', ['localhost', '127.0.0.1']);

        return array_values(array_map('strtolower', array_filter(array_map(
            static fn ($v) => is_string($v) ? trim($v) : null,
            $list,
        ))));
    }
}
