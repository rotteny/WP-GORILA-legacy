# Envio e Recebimento de Mensagens

Todos os endpoints requerem autenticação via sessão web (cookie `laravel_session`).  
Base URL: `https://wp.local/api/whatsapp`

---

## Envio

### Destino

Todo envio precisa de um destino, que pode ser informado como:

| Campo | Formato | Exemplo |
|---|---|---|
| `number` | Número com DDI+DDD | `5511999887766` |
| `jid` | JID completo | `5511999887766@s.whatsapp.net` |

Para grupos, use `jid` com sufixo `@g.us`:
```
1234567890-1234567890@g.us
```

---

### Texto

```http
POST /instances/{slug}/send-message
Content-Type: application/json

{
  "number": "5511999887766",
  "message": "Olá! Mensagem da Gorila 🦍"
}
```

**Resposta (200):**
```json
{
  "ok": true,
  "id": "3EB0A1B2C3D4E5F6",
  "to": "5511999887766@s.whatsapp.net"
}
```

---

### Imagem

```http
POST /instances/{slug}/send-media
Content-Type: multipart/form-data

number=5511999887766
caption=Veja esta imagem!
file=@foto.jpg   (image/jpeg, image/png, image/webp...)
```

**Resposta (200):**
```json
{
  "ok": true,
  "id": "3EB0A1B2C3D4E5F6",
  "to": "5511999887766@s.whatsapp.net",
  "bytes": 204800,
  "mime": "image/jpeg"
}
```

---

### Vídeo

```http
POST /instances/{slug}/send-media
Content-Type: multipart/form-data

number=5511999887766
caption=Assista este vídeo
file=@video.mp4   (video/mp4, video/*)
```

---

### Áudio

```http
POST /instances/{slug}/send-media
Content-Type: multipart/form-data

number=5511999887766
file=@audio.ogg   (audio/ogg → enviado como PTT/mensagem de voz)
```

> Arquivos `.ogg` com codec Opus são enviados como **mensagem de voz** (PTT).  
> Outros formatos de áudio (mp3, m4a) são enviados como arquivo de áudio comum.

---

### Documento / PDF / Planilha

```http
POST /instances/{slug}/send-media
Content-Type: multipart/form-data

number=5511999887766
caption=Segue o relatório
file=@relatorio.pdf   (application/pdf, .docx, .xlsx, .zip, .txt...)
```

O nome do arquivo é preservado na mensagem.

---

### Limite de tamanho

Máximo de **25 MB** por arquivo (limitação do WhatsApp).

---

### Sem slug — fallback automático

Use estes endpoints quando não souber qual instância usar. O sistema tenta cada instância conectada em ordem, da mais recente para a mais antiga:

```http
POST /send-message
POST /send-media
```

Mesmos campos dos endpoints com slug. A resposta inclui qual instância foi usada:

```json
{
  "ok": true,
  "instance": "gorila-wp",
  "result": { "ok": true, "id": "3EB0A...", "to": "..." }
}
```

**Nenhuma instância conectada (409):**
```json
{
  "ok": false,
  "error": "Nenhuma instância conectada",
  "instances": [
    { "slug": "gorila-wp", "name": "Gorila WP", "status": "LOGGED_OUT" },
    { "slug": "gorila-2",  "name": "Gorila 2",  "status": "PENDING_QR" }
  ]
}
```

**Instância com slug desconectada (409):**
```json
{
  "ok": false,
  "error": "instância não conectada",
  "status": "LOGGED_OUT"
}
```

---

## Recebimento

O sistema recebe eventos do WhatsApp via webhook interno do `whatsapp-service`. Cada evento é salvo no banco e propagado via WebSocket (Reverb) para o frontend.

Para receber eventos na **sua aplicação**, configure um webhook de redirecionamento no painel (botão ⚙️ Webhooks em cada instância).

---

### Formato do payload enviado ao seu webhook

```http
POST {sua-url}
Content-Type: application/json
X-Hub-Signature-256: sha256={assinatura}   (se secret configurado)

{
  "event":       "message",
  "instance":    "gorila-wp",
  "received_at": "2026-06-19T10:30:00.000Z",
  "payload":     { ...dados do evento... }
}
```

---

### Evento: `message` — Nova mensagem

O campo `type` indica o tipo do conteúdo recebido.

#### Texto

```json
{
  "event": "message",
  "instance": "gorila-wp",
  "payload": {
    "from":                 "5511999887766@s.whatsapp.net",
    "chat_type":            "private",
    "participant":          null,
    "from_me":              false,
    "type":                 "text",
    "body":                 "Olá! Preciso de ajuda.",
    "whatsapp_message_id":  "3EB0A1B2C3D4E5F6",
    "received_at":          "2026-06-19T10:30:00.000Z",
    "sender_name":          "João Silva",
    "sender_phone":         "5511999887766"
  }
}
```

#### Imagem / Sticker

```json
{
  "type": "image",
  "body": "legenda opcional",
  "whatsapp_message_id": "3EB0..."
}
```

Para baixar a mídia:
```http
GET /instances/{slug}/media/{whatsapp_message_id}
```

#### Vídeo

```json
{
  "type": "video",
  "body": "legenda opcional",
  "whatsapp_message_id": "3EB0..."
}
```

#### Áudio / Mensagem de voz

```json
{
  "type": "audio",
  "body": null,
  "whatsapp_message_id": "3EB0..."
}
```

#### Documento

```json
{
  "type": "document",
  "body": "nome-do-arquivo.pdf",
  "whatsapp_message_id": "3EB0..."
}
```

#### Localização

```json
{
  "type": "location",
  "body": "{\"lat\":-23.5505,\"lng\":-46.6333}",
  "whatsapp_message_id": "3EB0..."
}
```

#### Contato

```json
{
  "type": "contact",
  "body": "Nome do Contato",
  "whatsapp_message_id": "3EB0..."
}
```

#### Mensagem de grupo

Em grupos, o campo `participant` identifica quem enviou dentro do grupo:

```json
{
  "from":        "1122334455-1234567890@g.us",
  "chat_type":   "group",
  "participant": "5511999887766@s.whatsapp.net",
  "from_me":     false,
  "type":        "text",
  "body":        "Bom dia pessoal!",
  "sender_name": "Maria",
  "sender_phone": "5511999887766"
}
```

---

### Evento: `message_deleted` — Mensagem apagada

```json
{
  "event": "message_deleted",
  "instance": "gorila-wp",
  "payload": {
    "keys": [
      {
        "remoteJid": "5511999887766@s.whatsapp.net",
        "id":        "3EB0A1B2C3D4E5F6",
        "fromMe":    false
      }
    ]
  }
}
```

O campo `keys[].id` corresponde ao `whatsapp_message_id` da mensagem removida.

---

### Evento: `message_reaction` — Reação

```json
{
  "event": "message_reaction",
  "instance": "gorila-wp",
  "payload": {
    "messageId":  "3EB0A1B2C3D4E5F6",
    "remoteJid":  "5511999887766@s.whatsapp.net",
    "emoji":      "❤️",
    "fromMe":     false,
    "reactorJid": "5511888776655@s.whatsapp.net",
    "ts":         1750334000000
  }
}
```

> `emoji` vazio (`""`) significa que a reação foi removida.  
> `messageId` é o `whatsapp_message_id` da mensagem que recebeu a reação.

---

### Evento: `connection` — Status da instância

```json
{
  "event": "connection",
  "instance": "gorila-wp",
  "payload": {
    "status":   "CONNECTED",
    "slug":     "gorila-wp",
    "name":     "Gorila WP",
    "qr_code":  null
  }
}
```

Status possíveis: `INITIALIZING`, `PENDING_QR`, `CONNECTED`, `RECONNECTING`, `LOGGED_OUT`.

---

## Tipos de conversa (`chat_type`)

| Valor | Sufixo do JID | Descrição |
|---|---|---|
| `private` | `@s.whatsapp.net` | Conversa individual |
| `group` | `@g.us` | Grupo |
| `broadcast` | `@broadcast` | Lista de transmissão |
| `newsletter` | `@newsletter` | Canal do WhatsApp |
| `private_lid` | `@lid` | Identificador de dispositivo vinculado |

---

## Download de mídia

```http
GET /instances/{slug}/media/{whatsapp_message_id}
```

Retorna o arquivo binário com o `Content-Type` correto. Disponível enquanto o Node.js mantiver o buffer em memória (reiniciar o serviço limpa o cache).

---

## Histórico de mensagens

```http
GET /instances/{slug}/chats/{jid}/messages
```

Retorna as mensagens salvas no banco para aquele JID, ordenadas cronologicamente.
