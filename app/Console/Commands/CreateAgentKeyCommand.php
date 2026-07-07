<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;

class CreateAgentKeyCommand extends Command
{
    protected $signature = 'agent:create-key {--hostname= : Identificador do cockpit (ex: cockpit-minipc)} {--name=cockpit : Nome descritivo da chave}';
    protected $description = 'Gera uma API key + HMAC secret pra um cockpit do repair-agent. Imprime UMA vez.';

    public function handle(): int
    {
        $generated  = ApiKey::generate();
        $hmacSecret = bin2hex(random_bytes(32)); // 64 chars hex

        ApiKey::create([
            'name'          => $this->option('name'),
            'key_prefix'    => $generated['prefix'],
            'key_hash'      => $generated['hash'],
            'hmac_secret'   => $hmacSecret,
            'hostname_hint' => $this->option('hostname') ?: null,
        ]);

        $this->info('Chave de cockpit criada. Copie AGORA — não é exibida de novo:');
        $this->newLine();
        $this->line('WP_GORILA_AGENT_API_KEY=' . $generated['plaintext']);
        $this->line('WP_GORILA_AGENT_HMAC_SECRET=' . $hmacSecret);

        return self::SUCCESS;
    }
}
