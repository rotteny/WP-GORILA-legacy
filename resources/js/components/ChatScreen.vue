<template>
  <div class="wa-wrap">
    <!-- COLUNA ESQUERDA: lista de conversas -->
    <aside class="wa-side">
      <header class="wa-side__header">
        <div class="wa-side__header-top">
          <a href="/" class="wa-back">← Projetos</a>
          <h2>Conversas</h2>
          <span class="wa-side__status" :class="statusClass">{{ statusLabel }}</span>
          <button v-if="audioBlocked" class="wa-audio-btn" @click="requestAudio" title="Ativar notificações sonoras">
            🔇
          </button>
        </div>
        <div v-if="projectName" class="wa-side__project">{{ projectName }}</div>
      </header>

      <div class="wa-side__list">
        <div v-if="loadingChats && chats.length === 0" class="wa-empty">
          Carregando...
        </div>
        <div v-else-if="chats.length === 0" class="wa-empty">
          Nenhuma conversa ainda.<br/>
          <small>Aguarde alguém mandar uma mensagem ou envie uma.</small>
        </div>
        <button
          v-for="c in chats"
          :key="c.jid"
          class="wa-chat-item"
          :class="{
            'wa-chat-item--active': c.jid === activeJid,
            'wa-chat-item--flash': flashingJids[c.jid],
          }"
          @click="openChat(c.jid)"
        >
          <div class="wa-avatar" :style="avatarStyle(c.jid)">
            {{ chatInitial(c) }}
          </div>
          <div class="wa-chat-item__body">
            <div class="wa-chat-item__title">
              {{ chatLabel(c) }}
              <span class="wa-chat-item__badge" :class="'wa-badge--' + c.chat_type">
                {{ chatTypeLabel(c.chat_type) }}
              </span>
              <span v-if="unreadCounts[c.jid]" class="wa-badge">{{ unreadCounts[c.jid] }}</span>
            </div>
            <div class="wa-chat-item__preview">
              {{ messagePreview(c.last_message) }}
            </div>
          </div>
          <div class="wa-chat-item__time">
            {{ formatTime(c.last_message.received_at) }}
          </div>
        </button>
      </div>
    </aside>

    <!-- COLUNA DIREITA: conversa aberta -->
    <main class="wa-main">
      <div v-if="!activeJid" class="wa-empty wa-empty--main">
        <div>
          <h3>Selecione uma conversa</h3>
          <p>Clique numa conversa à esquerda para abrir.</p>
        </div>
      </div>

      <template v-else>
        <header class="wa-main__header">
          <div class="wa-avatar" :style="avatarStyle(activeJid)">
            {{ activeInitial }}
          </div>
          <div>
            <div class="wa-main__title">{{ activeLabel }}</div>
            <div class="wa-main__subtitle">{{ chatTypeLabel(activeChatType) }} · {{ activeJid }}</div>
          </div>
        </header>

        <div ref="msgBox" class="wa-messages">
          <div v-if="loadingMessages && messages.length === 0" class="wa-empty">
            Carregando mensagens...
          </div>
          <div
            v-for="m in messages"
            :key="m.whatsapp_message_id"
            class="wa-msg"
            :class="m.from_me ? 'wa-msg--out' : 'wa-msg--in'"
          >
            <div class="wa-msg__bubble">
              <div v-if="!m.from_me && (m.sender_name || m.sender_phone)" class="wa-msg__sender">
                <span v-if="m.sender_name" class="wa-msg__sender-name">{{ m.sender_name }}</span>
                <span v-if="m.sender_phone" class="wa-msg__sender-phone">{{ formatPhone(m.sender_phone) }}</span>
              </div>
              <!-- Imagem -->
              <img
                v-if="m.type === 'image' || m.type === 'sticker'"
                :src="mediaUrl(m)"
                class="wa-msg__image"
                loading="lazy"
                @error="onMediaError($event, m)"
              />

              <!-- Vídeo -->
              <video
                v-else-if="m.type === 'video'"
                :src="mediaUrl(m)"
                controls
                preload="metadata"
                class="wa-msg__video"
              />

              <!-- Áudio -->
              <audio
                v-else-if="m.type === 'audio'"
                :src="mediaUrl(m)"
                controls
                preload="metadata"
                class="wa-msg__audio"
              />

              <!-- Documento -->
              <a
                v-else-if="m.type === 'document'"
                :href="mediaUrl(m)"
                target="_blank"
                rel="noopener"
                class="wa-msg__doc"
              >
                📄 {{ m.body || 'documento' }}
              </a>

              <!-- Localização -->
              <div v-else-if="m.type === 'location'" class="wa-msg__loc">
                📍 Localização
                <code v-if="m.body" style="font-size:11px; display:block; margin-top:4px;">{{ m.body }}</code>
              </div>

              <!-- Tipos não tratados (unknown, contact, etc.) -->
              <div v-else-if="m.type !== 'text'" class="wa-msg__type-icon">
                {{ typeIcon(m.type) }} <em>{{ m.type }}</em>
              </div>

              <!-- Texto / legenda -->
              <div
                v-if="m.body && m.type !== 'document' && m.type !== 'location'"
                class="wa-msg__body"
              >{{ m.body }}</div>

              <div class="wa-msg__time">{{ formatTime(m.received_at) }}</div>
              <div
                v-if="reactions[m.whatsapp_message_id] && Object.keys(reactions[m.whatsapp_message_id]).length"
                class="wa-msg__reactions"
              >
                <span
                  v-for="(count, emoji) in reactions[m.whatsapp_message_id]"
                  :key="emoji"
                  class="wa-reaction"
                >{{ emoji }} {{ count > 1 ? count : '' }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Preview do arquivo escolhido (acima do composer) -->
        <div v-if="pendingFile" class="wa-attach">
          <img v-if="filePreviewUrl" :src="filePreviewUrl" class="wa-attach__thumb" />
          <span v-else class="wa-attach__icon">{{ fileIcon(pendingFile) }}</span>
          <div class="wa-attach__meta">
            <div class="wa-attach__name">{{ pendingFile.name }}</div>
            <div class="wa-attach__size">{{ formatBytes(pendingFile.size) }} · {{ pendingFile.type || 'desconhecido' }}</div>
          </div>
          <button class="wa-attach__cancel" type="button" @click="clearFile" :disabled="sending">✕</button>
        </div>

        <footer class="wa-composer">
          <input
            ref="fileInput"
            type="file"
            hidden
            @change="onFileChosen"
            accept="image/*,video/*,audio/*,application/pdf,application/zip,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/plain"
          />
          <button
            type="button"
            class="wa-composer__attach"
            @click="$refs.fileInput.click()"
            :disabled="sending || !canSendToActive"
            title="Anexar arquivo"
          >📎</button>

          <input
            v-model="draft"
            class="wa-composer__input"
            type="text"
            :placeholder="pendingFile ? 'Legenda (opcional)...' : 'Digite uma mensagem...'"
            @keydown.enter="send"
            :disabled="sending || !canSendToActive"
          />
          <button
            class="wa-composer__send"
            @click="send"
            :disabled="sending || !canSendToActive || (!draft.trim() && !pendingFile)"
          >
            {{ sending ? '...' : 'Enviar' }}
          </button>
        </footer>
        <p v-if="!canSendToActive" class="wa-warn">
          Envio não suportado neste tipo de conversa ({{ chatTypeLabel(activeChatType) }}).
        </p>
      </template>
    </main>
  </div>
</template>

<script>
import axios from 'axios';


export default {
  name: 'ChatScreen',

  props: {
    instanceSlug: {
      type: String,
      required: true,
    },
  },

  data() {
    return {
      status: null,
      projectName: null,
      chats: [],
      activeJid: null,
      messages: [],
      draft: '',
      loadingChats: false,
      loadingMessages: false,
      sending: false,
      pollHandle: null,
      echoChannel: null,
      pendingFile: null,        // File API: arquivo escolhido pra enviar
      filePreviewUrl: null,     // URL.createObjectURL — só pra imagens
      unreadCounts: {},
      flashingJids: {},         // { [jid]: true } — itens pulsando (reativo via spread)
      audioBlocked: true,
      audioCtx: null,
      audioBuffer: null,
      reactions: {},  // { [messageId]: { [emoji]: count } }
    };
  },

  computed: {
    statusLabel() {
      const map = {
        CONNECTED: 'Conectado',
        PENDING_QR: 'Aguardando QR',
        INITIALIZING: 'Iniciando',
        RECONNECTING: 'Reconectando',
        LOGGED_OUT: 'Desconectado',
      };
      return map[this.status] || this.status || '...';
    },
    statusClass() {
      return {
        'wa-side__status--ok': this.status === 'CONNECTED',
        'wa-side__status--warn': ['PENDING_QR', 'RECONNECTING'].includes(this.status),
        'wa-side__status--err': this.status === 'LOGGED_OUT',
      };
    },
    activeChatType() {
      return this.chats.find((c) => c.jid === this.activeJid)?.chat_type || 'unknown';
    },
    activeLabel() {
      const chat = this.chats.find((c) => c.jid === this.activeJid);
      return chat ? this.chatLabel(chat) : this.activeJid;
    },
    activeInitial() {
      const chat = this.chats.find((c) => c.jid === this.activeJid);
      return chat ? this.chatInitial(chat) : '?';
    },
    canSendToActive() {
      // Permite envio para conversas privadas (número visível ou LID) e grupos.
      // Bloqueia newsletter/broadcast (não dá pra responder) e tipos desconhecidos.
      return ['private', 'private_lid', 'group'].includes(this.activeChatType);
    },
    apiBase() {
      return `/api/whatsapp/instances/${this.instanceSlug}`;
    },
  },

  mounted() {
    this.audioCtx = new (window.AudioContext || window.webkitAudioContext)();

    // Pré-carrega o buffer uma vez
    fetch('/sounds/alarme.mp3')
      .then(r => r.arrayBuffer())
      .then(buf => this.audioCtx.decodeAudioData(buf))
      .then(decoded => { this.audioBuffer = decoded; })
      .catch(() => {});

    // Tenta resumir imediatamente (funciona se o browser já autorizou na sessão)
    this.audioCtx.resume().then(() => {
      if (this.audioCtx.state === 'running') this.audioBlocked = false;
    });

    this.refreshAll();
    this.connectEcho();
    this.pollHandle = setInterval(this.fetchChats, 30_000);
  },

  beforeUnmount() { this.disconnectEcho(); this.stopPolling(); this.clearFile(); },
  beforeDestroy()  { this.disconnectEcho(); this.stopPolling(); this.clearFile(); },

  methods: {
    requestAudio() {
      this.audioCtx.resume().then(() => {
        this.audioBlocked = false;
        this._playAlarm();
      });
    },

    _playAlarm() {
      if (!this.audioBuffer || !this.audioCtx) return;
      if (this.audioCtx.state === 'suspended') { this.audioBlocked = true; return; }
      const src = this.audioCtx.createBufferSource();
      src.buffer = this.audioBuffer;
      src.connect(this.audioCtx.destination);
      src.start(0);
    },

    stopPolling() {
      if (this.pollHandle) {
        clearInterval(this.pollHandle);
        this.pollHandle = null;
      }
    },

    connectEcho() {
      if (!window.Echo || !this.instanceSlug) {
        // Fallback: polling normal se Echo não disponível
        return;
      }
      this.echoChannel = window.Echo.channel(`instance.${this.instanceSlug}`)
        .listen('.MessageReceived', async (data) => {
          const payload = data.payload;
          if (!payload) return;

          this._playAlarm();

          // Captura timestamps antes de recarregar para detectar qual chat mudou
          const prevTimestamps = Object.fromEntries(
            this.chats.map(c => [c.jid, c.last_message?.received_at])
          );

          await this.fetchChats();

          // Flashar e incrementar badge nos chats que receberam mensagem nova
          this.chats.forEach(c => {
            if (c.last_message?.received_at === prevTimestamps[c.jid]) return;

            if (c.jid !== this.activeJid) {
              this.unreadCounts = {
                ...this.unreadCounts,
                [c.jid]: (this.unreadCounts[c.jid] || 0) + 1,
              };
            }

            this.flashingJids = { ...this.flashingJids, [c.jid]: true };
            setTimeout(() => {
              const copy = { ...this.flashingJids };
              delete copy[c.jid];
              this.flashingJids = copy;
            }, 2000);
          });

          // Recarrega mensagens se a conversa ativa estiver aberta
          if (this.activeJid) {
            this.fetchMessages(this.activeJid).then(() => this.scrollToBottom());
          }
        })
        .listen('.MessageDeleted', (data) => {
          const keys = data.payload?.keys ?? [];
          if (!keys.length) return;

          // Remove mensagens apagadas da lista visível
          const deletedIds = new Set(keys.map(k => k.id).filter(Boolean));
          if (deletedIds.size && this.messages.length) {
            this.messages = this.messages.filter(
              m => !deletedIds.has(m.whatsapp_message_id)
            );
          }

          // Atualiza lista de chats (preview pode ter mudado)
          this.fetchChats();
        })
        .listen('.MessageReaction', (data) => {
          const { messageId, emoji, reactorJid } = data.payload ?? {};
          if (!messageId) return;

          const current = { ...(this.reactions[messageId] ?? {}) };

          if (!emoji) {
            Object.keys(current).forEach(e => {
              if (current[e] > 0) current[e]--;
              if (current[e] <= 0) delete current[e];
            });
          } else {
            current[emoji] = (current[emoji] ?? 0) + 1;
          }

          this.reactions = { ...this.reactions, [messageId]: current };
        })
        .listen('.InstanceUpdated', (data) => {
          // Status do WhatsApp mudou (ex: desconectou)
          if (data.status) this.status = data.status;
        })
        .error(() => {
          // Fallback silencioso — polling continua
          console.warn('Echo error no ChatScreen, usando polling');
        });
    },

    disconnectEcho() {
      if (this.echoChannel) {
        window.Echo.leaveChannel(`instance.${this.instanceSlug}`);
        this.echoChannel = null;
      }
    },

    async refreshAll() {
      await Promise.all([this.fetchStatus(), this.fetchChats()]);
      if (this.activeJid) await this.fetchMessages(this.activeJid);
    },

    async fetchStatus() {
      try {
        const { data } = await axios.get(`${this.apiBase}/status`);
        this.status = data.status;
        this.projectName = data.name || null;
      } catch (_) { /* silencioso */ }
    },

    async fetchChats() {
      this.loadingChats = true;
      try {
        const { data } = await axios.get(`${this.apiBase}/chats`);
        this.chats = data.chats || [];
      } catch (e) {
        console.error('Erro ao carregar conversas:', e);
      } finally {
        this.loadingChats = false;
      }
    },

    async fetchMessages(jid) {
      this.loadingMessages = true;
      try {
        const { data } = await axios.get(`${this.apiBase}/chats/${encodeURIComponent(jid)}/messages`);
        this.messages = data.messages || [];
        this.$nextTick(() => this.scrollToBottom());
      } catch (e) {
        console.error('Erro ao carregar mensagens:', e);
      } finally {
        this.loadingMessages = false;
      }
    },

    openChat(jid) {
      this.activeJid = jid;
      this.messages = [];
      // Zera o badge de não lidos ao abrir a conversa
      if (this.unreadCounts[jid]) {
        this.unreadCounts = { ...this.unreadCounts, [jid]: 0 };
      }
      this.fetchMessages(jid);
    },

    // Resolve número/jid pra mandar pro backend conforme o tipo de chat.
    targetPayloadFields() {
      if (this.activeChatType === 'private') {
        return { number: this.activeJid.split('@')[0].split('-')[0] };
      }
      return { jid: this.activeJid };
    },

    async send() {
      if (!this.activeJid || !this.canSendToActive) return;
      const text = this.draft.trim();
      if (!text && !this.pendingFile) return;

      this.sending = true;
      try {
        if (this.pendingFile) {
          // ENVIO DE MÍDIA — multipart
          const form = new FormData();
          const fields = this.targetPayloadFields();
          Object.entries(fields).forEach(([k, v]) => form.append(k, v));
          if (text) form.append('caption', text);
          form.append('file', this.pendingFile, this.pendingFile.name);

          await axios.post(`${this.apiBase}/send-media`, form, {
            headers: { 'Content-Type': 'multipart/form-data' },
          });
          this.clearFile();
        } else {
          // ENVIO DE TEXTO — json
          await axios.post(`${this.apiBase}/send-message`, {
            ...this.targetPayloadFields(),
            message: text,
          });
        }

        this.draft = '';
        setTimeout(() => this.fetchMessages(this.activeJid), 700);
      } catch (e) {
        console.error('Erro ao enviar:', e);
        alert('Falha ao enviar: ' + (e?.response?.data?.error || e.message));
      } finally {
        this.sending = false;
      }
    },

    onFileChosen(event) {
      const file = event.target.files?.[0];
      // limpa o input pra permitir reselecionar o mesmo arquivo depois
      event.target.value = '';
      if (!file) return;

      // limite client-side (espelha o do Node = 25 MB)
      const MAX = 25 * 1024 * 1024;
      if (file.size > MAX) {
        alert(`Arquivo muito grande (${this.formatBytes(file.size)}). Limite: 25 MB.`);
        return;
      }

      this.pendingFile = file;
      this.filePreviewUrl = file.type.startsWith('image/')
        ? URL.createObjectURL(file)
        : null;
    },

    clearFile() {
      if (this.filePreviewUrl) URL.revokeObjectURL(this.filePreviewUrl);
      this.pendingFile = null;
      this.filePreviewUrl = null;
    },

    fileIcon(file) {
      const mime = file.type || '';
      if (mime.startsWith('image/')) return '🖼️';
      if (mime.startsWith('video/')) return '🎥';
      if (mime.startsWith('audio/')) return '🎤';
      if (mime.includes('pdf'))      return '📕';
      if (mime.includes('zip'))      return '🗜️';
      if (mime.includes('word'))     return '📘';
      if (mime.includes('sheet') || mime.includes('excel')) return '📗';
      return '📄';
    },

    formatBytes(bytes) {
      if (bytes < 1024) return bytes + ' B';
      if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
      return (bytes / 1024 / 1024).toFixed(1) + ' MB';
    },

    scrollToBottom() {
      const el = this.$refs.msgBox;
      if (el) el.scrollTop = el.scrollHeight;
    },

    chatLabel(c) {
      if (!c?.jid) return '(sem id)';
      // Usa sender_name da última mensagem recebida se disponível
      if (c.last_message?.sender_name && !c.last_message?.from_me) {
        return c.last_message.sender_name;
      }
      const num = c.jid.split('@')[0].split('-')[0];
      return num;
    },

    chatInitial(c) {
      const label = this.chatLabel(c);
      return label.slice(0, 2).toUpperCase();
    },

    avatarStyle(jid) {
      // cor estável a partir do JID
      let h = 0;
      for (let i = 0; i < (jid || '').length; i++) h = (h * 31 + jid.charCodeAt(i)) >>> 0;
      const hue = h % 360;
      return { background: `hsl(${hue}, 55%, 50%)` };
    },

    chatTypeLabel(t) {
      return ({
        private: '1:1',
        private_lid: '1:1 (LID)',
        group: 'Grupo',
        newsletter: 'Canal',
        broadcast: 'Status',
        unknown: '?',
      })[t] || t;
    },

    typeIcon(t) {
      return ({
        image: '📷',
        video: '🎥',
        audio: '🎤',
        document: '📄',
        sticker: '😀',
        location: '📍',
        contact: '👤',
        text: '',
        unknown: '📨',
      })[t] || '📨';
    },

    messagePreview(m) {
      if (!m) return '';
      const prefix = m.from_me ? 'Você: ' : '';
      if (m.body) return prefix + m.body.slice(0, 60);
      return prefix + this.typeIcon(m.type) + ' ' + m.type;
    },

    formatTime(iso) {
      try {
        return new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
      } catch { return iso; }
    },

    mediaUrl(m) {
      return `${this.apiBase}/media/${encodeURIComponent(m.whatsapp_message_id)}`;
    },

    onMediaError(event, m) {
      console.warn('falha ao carregar mídia', m.whatsapp_message_id);
      event.target.replaceWith(
        Object.assign(document.createElement('div'), {
          textContent: '⚠️ Não foi possível carregar a mídia',
          style: 'color:#a00; font-size:12px;',
        }),
      );
    },

    formatPhone(phone) {
      if (!phone) return '';
      const digits = String(phone).replace(/\D/g, '');
      if ((digits.length === 13 || digits.length === 12) && digits.startsWith('55')) {
        const ddd = digits.slice(2, 4);
        const n = digits.slice(4);
        const part1 = n.length === 9 ? n.slice(0, 5) : n.slice(0, 4);
        const part2 = n.length === 9 ? n.slice(5) : n.slice(4);
        return `+55 (${ddd}) ${part1}-${part2}`;
      }
      return `+${digits}`;
    },
  },
};
</script>

<style scoped>
.wa-wrap {
  display: flex;
  height: 100vh;
  font-family: system-ui, sans-serif;
  background: #e9edef;
}

.wa-side {
  width: 360px;
  background: #fff;
  border-right: 1px solid #d1d7db;
  display: flex;
  flex-direction: column;
}
.wa-side__header {
  padding: 12px 16px;
  background: #f0f2f5;
  border-bottom: 1px solid #d1d7db;
}
.wa-side__header-top {
  display: flex;
  align-items: center;
  gap: 10px;
  justify-content: space-between;
  flex-wrap: wrap;
}
.wa-side__header h2 { margin: 0; font-size: 18px; flex: 1; }
.wa-side__project {
  margin-top: 4px;
  font-size: 12px;
  color: #54656f;
}
.wa-back {
  font-size: 12px;
  color: #54656f;
  text-decoration: none;
  white-space: nowrap;
}
.wa-back:hover {
  color: #1e293b;
  text-decoration: underline;
}
.wa-side__status {
  font-size: 12px;
  padding: 2px 8px;
  border-radius: 10px;
  background: #d1d7db;
  color: #54656f;
}
.wa-side__status--ok { background: #d8f3dc; color: #095c2a; }
.wa-side__status--warn { background: #fff3bf; color: #856404; }
.wa-side__status--err { background: #fad4d4; color: #842029; }

.wa-side__list { flex: 1; overflow-y: auto; }
.wa-empty {
  padding: 24px;
  color: #667781;
  text-align: center;
  font-size: 14px;
}
.wa-empty--main {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f0f2f5;
  color: #41525d;
}
.wa-empty--main h3 { margin: 0 0 8px; }

.wa-chat-item {
  position: relative;
  overflow: hidden;
  width: 100%;
  background: none;
  border: none;
  border-bottom: 1px solid #f0f2f5;
  padding: 10px 14px;
  text-align: left;
  display: flex;
  gap: 10px;
  align-items: center;
  cursor: pointer;
}
.wa-chat-item:hover { background: #f5f6f6; }
.wa-chat-item--active { background: #f0f2f5; }
.wa-chat-item__body { flex: 1; min-width: 0; }
.wa-chat-item__title {
  font-weight: 600;
  font-size: 14px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.wa-chat-item__preview {
  font-size: 13px;
  color: #667781;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.wa-chat-item__time {
  font-size: 11px;
  color: #667781;
}
.wa-chat-item__badge {
  font-size: 10px;
  padding: 1px 6px;
  border-radius: 8px;
  font-weight: 500;
  background: #e9edef;
  color: #54656f;
}
.wa-badge--group { background: #cce5ff; color: #003d80; }
.wa-badge--newsletter { background: #f0e0ff; color: #5a2d82; }
.wa-badge--private_lid { background: #fff3bf; color: #856404; }

.wa-avatar {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  color: #fff;
  display: grid;
  place-items: center;
  font-weight: 600;
  font-size: 13px;
  flex-shrink: 0;
}

.wa-main {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.wa-main__header {
  padding: 12px 16px;
  background: #f0f2f5;
  border-bottom: 1px solid #d1d7db;
  display: flex;
  gap: 12px;
  align-items: center;
}
.wa-main__title { font-weight: 600; }
.wa-main__subtitle { font-size: 12px; color: #667781; }

.wa-messages {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
  background: #efeae2;
  background-image:
    radial-gradient(circle at 10% 10%, #d9d3c5 1px, transparent 1px),
    radial-gradient(circle at 80% 60%, #d9d3c5 1px, transparent 1px);
  background-size: 30px 30px;
}
.wa-msg {
  display: flex;
  margin-bottom: 6px;
}
.wa-msg--out { justify-content: flex-end; }
.wa-msg__bubble {
  max-width: 65%;
  padding: 8px 12px;
  border-radius: 8px;
  background: #fff;
  font-size: 14px;
  box-shadow: 0 1px 0.5px rgba(0,0,0,0.13);
  word-wrap: break-word;
}
.wa-msg--out .wa-msg__bubble {
  background: #d9fdd3;
}
.wa-msg__type-icon {
  font-size: 12px;
  color: #667781;
  margin-bottom: 4px;
}
.wa-msg__image {
  display: block;
  max-width: 260px;
  max-height: 260px;
  border-radius: 6px;
  margin-bottom: 4px;
  cursor: zoom-in;
}
.wa-msg__video {
  display: block;
  max-width: 280px;
  border-radius: 6px;
  margin-bottom: 4px;
}
.wa-msg__audio {
  display: block;
  width: 240px;
  margin-bottom: 4px;
}
.wa-msg__doc {
  display: inline-block;
  padding: 8px 10px;
  background: #f0f2f5;
  border-radius: 6px;
  text-decoration: none;
  color: #054d3a;
  font-size: 13px;
}
.wa-msg__loc {
  font-size: 13px;
  background: #f0f2f5;
  padding: 8px;
  border-radius: 6px;
}
.wa-msg__body { white-space: pre-wrap; }
.wa-msg__time {
  font-size: 10px;
  color: #667781;
  margin-top: 4px;
  text-align: right;
}
.wa-msg__sender {
  display: flex;
  align-items: baseline;
  gap: 6px;
  margin-bottom: 3px;
}
.wa-msg__sender-name {
  font-size: .75rem;
  font-weight: 600;
  color: #065f46;
}
.wa-msg__sender-phone {
  font-size: .7rem;
  color: #6b7280;
}

.wa-composer {
  display: flex;
  gap: 8px;
  padding: 10px 14px;
  background: #f0f2f5;
  border-top: 1px solid #d1d7db;
}
.wa-composer__input {
  flex: 1;
  border: none;
  background: #fff;
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 14px;
  outline: none;
}
.wa-composer__send {
  background: #008069;
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 0 18px;
  font-weight: 600;
  cursor: pointer;
}
.wa-composer__send:disabled { opacity: .5; cursor: not-allowed; }

.wa-composer__attach {
  background: none;
  border: none;
  font-size: 22px;
  cursor: pointer;
  padding: 0 6px;
  color: #54656f;
}
.wa-composer__attach:disabled { opacity: .4; cursor: not-allowed; }
.wa-composer__attach:hover:not(:disabled) { color: #008069; }

.wa-attach {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 14px;
  background: #fff;
  border-top: 1px solid #d1d7db;
}
.wa-attach__thumb {
  width: 44px;
  height: 44px;
  border-radius: 6px;
  object-fit: cover;
}
.wa-attach__icon {
  width: 44px;
  height: 44px;
  display: grid;
  place-items: center;
  font-size: 24px;
  background: #f0f2f5;
  border-radius: 6px;
}
.wa-attach__meta { flex: 1; min-width: 0; }
.wa-attach__name {
  font-size: 13px;
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.wa-attach__size {
  font-size: 11px;
  color: #667781;
}
.wa-attach__cancel {
  background: none;
  border: none;
  font-size: 16px;
  color: #667781;
  cursor: pointer;
  padding: 4px 8px;
}
.wa-attach__cancel:hover { color: #d33; }

.wa-warn {
  margin: 0;
  padding: 6px 14px;
  background: #fff3bf;
  color: #856404;
  font-size: 12px;
  text-align: center;
}

/* Badge de mensagens não lidas */
.wa-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: 9px;
  background: #dc2626;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  line-height: 1;
  margin-left: auto;
}

/* Degradê verde passando horizontalmente ao receber mensagem */
@keyframes msg-sweep {
  0%   { transform: translateX(-100%); }
  100% { transform: translateX(100%); }
}
.wa-chat-item--flash::after {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, transparent 0%, #86efac 40%, #bbf7d0 50%, #86efac 60%, transparent 100%);
  animation: msg-sweep 0.4s ease-out forwards;
  pointer-events: none;
}

.wa-audio-btn {
  background: none;
  border: none;
  cursor: pointer;
  font-size: 1.1rem;
  padding: 2px 4px;
  border-radius: 4px;
  opacity: .7;
  transition: opacity .2s;
  title: "Ativar notificações sonoras";
}
.wa-audio-btn:hover { opacity: 1; background: rgba(0,0,0,.06); }

.wa-msg__reactions {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 4px;
}
.wa-reaction {
  background: rgba(0,0,0,.06);
  border-radius: 999px;
  padding: 1px 6px;
  font-size: .8rem;
  cursor: default;
  user-select: none;
}
</style>
