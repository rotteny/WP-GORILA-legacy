<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Replica em PHP a lógica de parsing/filtragem de mensagens
 * implementada no whatsapp-service Node (Baileys).
 *
 * Espelha as funções `isRealMessage()`, `chatTypeFromJid()` e
 * `summarizeMessage()` do whatsapp-service/index.js.
 */
class BaileysMessageParser
{
    /**
     * Chaves de message que representam ruído de protocolo / sinalização
     * e devem ser ignoradas (não são mensagens reais de usuário).
     */
    private const NOISE_KEYS = [
        'senderKeyDistributionMessage',
        'messageContextInfo',
        'protocolMessage',
        'reactionMessage',
        'ephemeralMessage',
        'viewOnceMessage',
        'viewOnceMessageV2',
    ];

    private const STATUS_BROADCAST_JID = 'status@broadcast';

    /**
     * Retorna true se a mensagem bruta do Baileys for uma mensagem real
     * (não é status@broadcast e não é apenas ruído de protocolo).
     */
    public function isRealMessage(array $rawMsg): bool
    {
        $message = $rawMsg['message'] ?? null;
        if (!is_array($message) || $message === []) {
            return false;
        }

        $jid = $rawMsg['key']['remoteJid'] ?? '';
        if ($jid === self::STATUS_BROADCAST_JID) {
            return false;
        }

        $realKeys = array_diff(array_keys($message), self::NOISE_KEYS);
        return $realKeys !== [];
    }

    /**
     * Extrai um sumário tipado da mensagem bruta do Baileys.
     *
     * @return array{
     *     jid: string|null,
     *     from_me: bool,
     *     whatsapp_message_id: string|null,
     *     message_type: string,
     *     body: string|null,
     *     media_mime: string|null,
     *     timestamp: int|null,
     * }
     */
    public function summarize(array $rawMsg): array
    {
        $message = $rawMsg['message'] ?? [];
        $key     = $rawMsg['key']     ?? [];

        [$type, $body, $mime] = $this->extractTypeBodyMime(is_array($message) ? $message : []);

        return [
            'jid'                 => $key['remoteJid'] ?? null,
            'from_me'             => (bool) ($key['fromMe'] ?? false),
            'whatsapp_message_id' => $key['id'] ?? null,
            'message_type'        => $type,
            'body'                => $body,
            'media_mime'          => $mime,
            'timestamp'           => $this->extractTimestamp($rawMsg),
        ];
    }

    /**
     * @return array{0: string, 1: string|null, 2: string|null} [type, body, mime]
     */
    private function extractTypeBodyMime(array $message): array
    {
        if (isset($message['conversation']) && is_string($message['conversation'])) {
            return ['text', $message['conversation'], null];
        }

        if (isset($message['extendedTextMessage'])) {
            $text = $message['extendedTextMessage']['text'] ?? null;
            return ['text', is_string($text) ? $text : null, null];
        }

        if (isset($message['imageMessage'])) {
            return [
                'image',
                $message['imageMessage']['caption']  ?? null,
                $message['imageMessage']['mimetype'] ?? null,
            ];
        }

        if (isset($message['videoMessage'])) {
            return [
                'video',
                $message['videoMessage']['caption']  ?? null,
                $message['videoMessage']['mimetype'] ?? null,
            ];
        }

        if (isset($message['audioMessage'])) {
            return [
                'audio',
                null,
                $message['audioMessage']['mimetype'] ?? null,
            ];
        }

        if (isset($message['documentMessage'])) {
            return [
                'document',
                $message['documentMessage']['fileName'] ?? null,
                $message['documentMessage']['mimetype'] ?? null,
            ];
        }

        if (isset($message['stickerMessage'])) {
            return [
                'sticker',
                null,
                $message['stickerMessage']['mimetype'] ?? null,
            ];
        }

        if (isset($message['locationMessage'])) {
            $loc  = $message['locationMessage'];
            $body = json_encode([
                'lat' => $loc['degreesLatitude']  ?? null,
                'lng' => $loc['degreesLongitude'] ?? null,
            ], JSON_THROW_ON_ERROR);
            return ['location', $body, null];
        }

        if (isset($message['contactMessage'])) {
            return [
                'contact',
                $message['contactMessage']['displayName'] ?? null,
                null,
            ];
        }

        return ['unknown', null, null];
    }

    private function extractTimestamp(array $rawMsg): ?int
    {
        $ts = $rawMsg['messageTimestamp'] ?? null;

        if (is_int($ts)) {
            return $ts;
        }

        if (is_numeric($ts)) {
            return (int) $ts;
        }

        if (is_array($ts) && isset($ts['low']) && is_numeric($ts['low'])) {
            return (int) $ts['low'];
        }

        return null;
    }
}
