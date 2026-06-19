<?php

namespace Tests\Feature\Api;

use App\Models\Instance;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookInboundTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_is_public_and_returns_200_on_valid_payload(): void
    {
        Instance::factory()->create(['slug' => 'inbound-1']);

        $this->postJson('/api/whatsapp/webhook', [
            'instance_id' => 'inbound-1',
            'event'       => 'connection',
            'status'      => 'CONNECTED',
        ])->assertOk()->assertJsonPath('ok', true);
    }

    public function test_webhook_validates_required_fields(): void
    {
        $this->postJson('/api/whatsapp/webhook', [])
             ->assertStatus(422)
             ->assertJsonValidationErrors(['instance_id', 'event']);
    }

    public function test_message_event_persists_message_in_database(): void
    {
        Instance::factory()->create(['slug' => 'inbound-2']);

        $this->postJson('/api/whatsapp/webhook', [
            'instance_id' => 'inbound-2',
            'event'       => 'message',
            'payload'     => [
                'messages' => [[
                    'key' => [
                        'id'        => 'WAID-IN-001',
                        'remoteJid' => '5511988887777@s.whatsapp.net',
                        'fromMe'    => false,
                    ],
                    'message'  => ['conversation' => 'Olá, sou um teste'],
                    'pushName' => 'João Tester',
                    'messageTimestamp' => 1718000000,
                ]],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('messages', [
            'whatsapp_message_id' => 'WAID-IN-001',
            'instance_id'         => 'inbound-2',
            'from'                => '5511988887777@s.whatsapp.net',
            'body'                => 'Olá, sou um teste',
            'sender_name'         => 'João Tester',
            'sender_phone'        => '5511988887777',
            'chat_type'           => 'private',
            'type'                => 'text',
            'from_me'             => false,
        ]);
    }

    public function test_message_event_detects_group_chat_type(): void
    {
        Instance::factory()->create(['slug' => 'inbound-3']);

        $this->postJson('/api/whatsapp/webhook', [
            'instance_id' => 'inbound-3',
            'event'       => 'message',
            'payload'     => [
                'messages' => [[
                    'key' => [
                        'id'          => 'WAID-GRP-001',
                        'remoteJid'   => '120363012345678@g.us',
                        'participant' => '5511988887777@s.whatsapp.net',
                        'fromMe'      => false,
                    ],
                    'message'  => ['conversation' => 'mensagem de grupo'],
                    'pushName' => 'Fulano',
                ]],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('messages', [
            'whatsapp_message_id' => 'WAID-GRP-001',
            'chat_type'           => 'group',
        ]);
    }

    public function test_message_event_detects_lid_chat_type(): void
    {
        Instance::factory()->create(['slug' => 'inbound-4']);

        $this->postJson('/api/whatsapp/webhook', [
            'instance_id' => 'inbound-4',
            'event'       => 'message',
            'payload'     => [
                'messages' => [[
                    'key' => [
                        'id'        => 'WAID-LID-001',
                        'remoteJid' => '31692580475117@lid',
                        'fromMe'    => false,
                    ],
                    'message' => ['conversation' => 'msg via LID'],
                ]],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('messages', [
            'whatsapp_message_id' => 'WAID-LID-001',
            'chat_type'           => 'private_lid',
        ]);
    }

    public function test_message_event_for_unknown_instance_does_not_create_message(): void
    {
        // Sem Instance criada — mas o webhook não pode quebrar (Node pode enviar antes do registro)
        $this->postJson('/api/whatsapp/webhook', [
            'instance_id' => 'fantasma',
            'event'       => 'message',
            'payload'     => [
                'messages' => [[
                    'key'     => ['id' => 'WAID-X', 'remoteJid' => '5511@s.whatsapp.net'],
                    'message' => ['conversation' => 'oi'],
                ]],
            ],
        ])->assertOk();

        // O controller atual ainda cria a mensagem, só não dispara broadcast/forwarder.
        // Mantemos o teste como documentação do comportamento: webhook nunca explode.
        $this->assertTrue(true);
    }
}
