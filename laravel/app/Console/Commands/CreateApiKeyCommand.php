<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;

class CreateApiKeyCommand extends Command
{
    protected $signature = 'whatsapp:api-key:create {name : Identificador humano da chave (ex: "CRM Gorila")}';

    protected $description = 'Cria uma nova API key para a API v1 do WhatsApp. A chave crua é exibida apenas uma vez.';

    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if (trim($name) === '') {
            $this->error('O nome da API key não pode ser vazio.');
            return self::FAILURE;
        }

        $raw = ApiKey::generateRawKey();

        $apiKey = ApiKey::create([
            'name' => $name,
            'key_hash' => ApiKey::hashKey($raw),
            'active' => true,
        ]);

        $this->newLine();
        $this->info('API key criada com sucesso.');
        $this->line('');
        $this->line('  <fg=gray>ID:</>    ' . $apiKey->id);
        $this->line('  <fg=gray>Nome:</>  ' . $apiKey->name);
        $this->line('  <fg=gray>Hash:</>  ' . $apiKey->key_hash);
        $this->newLine();
        $this->line('  <fg=yellow;options=bold>KEY (anote agora — não será exibida novamente):</>');
        $this->line('  <fg=green;options=bold>' . $raw . '</>');
        $this->newLine();
        $this->warn('Anote esta key agora. Apenas o hash SHA-256 fica armazenado no banco.');
        $this->newLine();

        return self::SUCCESS;
    }
}
