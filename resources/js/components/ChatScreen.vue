<template>
  <div class="wa-wrap">
    <!-- COLUNA ESQUERDA: lista de conversas -->
    <aside class="wa-side">
      <header class="wa-side__header">
        <div class="wa-side__header-top">
          <div class="wa-side__header-left">
            <a :href="backHref" class="wa-back">← Voltar</a>
            <h2>Conversas</h2>
          </div>
          <div class="wa-side__header-pills">
            <span class="wa-side__status" :class="statusClass">{{ statusLabel }}</span>
            <button class="wa-tab-btn" :class="{ 'wa-tab-btn--active': showWebhooks }" @click="showWebhooks = !showWebhooks" title="Configurar webhooks">
              Webhooks
            </button>
          </div>
        </div>
        <div class="wa-side__header-sub">
          <span v-if="projectName" class="wa-side__project">{{ projectName }}</span>
          <button v-if="audioBlocked" class="wa-audio-btn" @click="requestAudio" title="Ativar notificações sonoras">
            🔇 Ativar som
          </button>
        </div>
      </header>

      <div v-if="showWebhooks" class="wa-side__webhooks">
        <WebhookSettings :instance-slug="instanceSlug" />
      </div>

      <div v-else class="wa-side__list">
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
                  v-for="(count, emoji) in reactionCounts(reactions[m.whatsapp_message_id])"
                  :key="emoji"
                  class="wa-reaction"
                >{{ emoji }}{{ count > 1 ? ' ' + count : '' }}</span>
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
import WebhookSettings from './WebhookSettings.vue';

export default {
  name: 'ChatScreen',

  components: {
    WebhookSettings,
  },

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
      showWebhooks: false,
      contactNames: {},  // { [jid]: sender_name } — cache persistente do nome do contato
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
    backHref() {
      const back = new URLSearchParams(window.location.search).get('back');
      return back && back.startsWith('/') ? back : '/';
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

          // Atualiza o cache de nome do contato em tempo real
          this.rememberContactNames([payload]);

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

          // { [reactorJid]: emoji } — cada pessoa só tem 1 reação por mensagem
          const byReactor = { ...(this.reactions[messageId] ?? {}) };
          if (!emoji) {
            delete byReactor[reactorJid];
          } else {
            byReactor[reactorJid] = emoji;
          }
          this.reactions = { ...this.reactions, [messageId]: byReactor };
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
        // Popula cache de nomes a partir do last_message de cada conversa
        this.rememberContactNames(this.chats.map(c => c.last_message).filter(Boolean));
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
        // Popula cache de nomes a partir do histórico recém-carregado
        this.rememberContactNames(this.messages);
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
      // 1) cache de nome do contato (sobrevive a respostas suas)
      if (this.contactNames[c.jid]) return this.contactNames[c.jid];
      // 2) última mensagem recebida (caso o cache ainda não esteja populado)
      if (c.last_message?.sender_name && !c.last_message?.from_me) {
        return c.last_message.sender_name;
      }
      const num = c.jid.split('@')[0].split('-')[0];
      return num;
    },

    rememberContactNames(messages) {
      if (!Array.isArray(messages) || !messages.length) return;
      const next = { ...this.contactNames };
      let changed = false;
      for (const m of messages) {
        const jid = m.from || m.chat_jid;
        if (!jid) continue;
        if (m.from_me) continue;
        if (!m.sender_name) continue;
        if (next[jid] === m.sender_name) continue;
        next[jid] = m.sender_name;
        changed = true;
      }
      if (changed) this.contactNames = next;
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

    reactionCounts(byReactor) {
      // { jid: emoji } → { emoji: count }
      const counts = {};
      Object.values(byReactor).forEach(e => { counts[e] = (counts[e] ?? 0) + 1; });
      return counts;
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
  font-family: var(--font, 'Plus Jakarta Sans'), system-ui, sans-serif;
  background: var(--bg);
  color: var(--ink);
}

.wa-side {
  width: 380px;
  background: var(--panel);
  border-right: 1px solid var(--line);
  display: flex;
  flex-direction: column;
}
.wa-side__header {
  padding: 14px 16px;
  background: var(--panel);
  border-bottom: 1px solid var(--line);
}
.wa-side__header-top {
  display: flex;
  align-items: center;
  gap: 10px;
  justify-content: space-between;
}
.wa-side__header-left {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}
.wa-side__header h2 { margin: 0; font-size: 18px; font-weight: 800; letter-spacing: -0.02em; color: var(--ink); }
.wa-side__header-pills {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}
.wa-side__header-sub {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-top: 10px;
  min-height: 20px;
}
.wa-side__project {
  font-size: 12px;
  color: var(--muted);
}
.wa-back {
  font-size: 13px;
  color: var(--brand);
  text-decoration: none;
  white-space: nowrap;
  font-weight: 700;
}
.wa-back:hover {
  opacity: 0.8;
}
.wa-side__status {
  font-size: 12px;
  font-weight: 700;
  padding: 3px 9px;
  border-radius: 999px;
  background: var(--hover);
  color: var(--ink-2);
}
.wa-side__status--ok { background: var(--brand-soft); color: #b794f6; }
.wa-side__status--warn { background: rgba(234,179,8,0.2); color: #e8c96e; }
.wa-side__status--err { background: rgba(240,90,75,0.16); color: #f08a7e; }

.wa-side__list { flex: 1; overflow-y: auto; }
.wa-empty {
  padding: 24px;
  color: var(--muted);
  text-align: center;
  font-size: 14px;
}
.wa-empty--main {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #1c1c1c;
  color: var(--muted);
}
.wa-empty--main h3 { margin: 0 0 8px; }

.wa-chat-item {
  position: relative;
  overflow: hidden;
  width: 100%;
  background: none;
  border: none;
  border-bottom: 1px solid var(--line);
  padding: 10px 14px;
  text-align: left;
  display: flex;
  gap: 10px;
  align-items: center;
  cursor: pointer;
}
.wa-chat-item:hover { background: var(--hover); }
.wa-chat-item--active { background: var(--brand-soft); }
.wa-chat-item__body { flex: 1; min-width: 0; }
.wa-chat-item__title {
  font-weight: 700;
  font-size: 14px;
  color: var(--ink);
  display: flex;
  align-items: center;
  gap: 6px;
}
.wa-chat-item__preview {
  font-size: 13px;
  color: var(--muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.wa-chat-item__time {
  font-size: 11px;
  color: var(--muted);
}
.wa-chat-item__badge {
  font-size: 10px;
  padding: 2px 7px;
  border-radius: 6px;
  font-weight: 700;
  background: rgba(255,255,255,0.09);
  color: #9aa6a1;
}
.wa-badge--group { background: rgba(59,130,246,0.2); color: #92bef8; }
.wa-badge--newsletter { background: rgba(168,85,247,0.2); color: #c8a4f7; }
.wa-badge--private_lid { background: rgba(234,179,8,0.2); color: #e8c96e; }

.wa-avatar {
  width: 46px;
  height: 46px;
  border-radius: 50%;
  color: #fff;
  display: grid;
  place-items: center;
  font-weight: 700;
  font-size: 15px;
  flex-shrink: 0;
}

.wa-main {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.wa-main__header {
  padding: 14px 16px;
  background: var(--panel);
  border-bottom: 1px solid var(--line);
  display: flex;
  gap: 12px;
  align-items: center;
}
.wa-main__title { font-weight: 700; color: var(--ink); }
.wa-main__subtitle { font-size: 12px; color: var(--muted); }

.wa-messages {
  flex: 1;
  overflow-y: auto;
  padding: 24px 26px;
  background: #1c1c1c;
  background-image: radial-gradient(circle at 1px 1px, rgba(255,255,255,0.03) 1px, transparent 0);
  background-size: 22px 22px;
}
.wa-msg {
  display: flex;
  margin-bottom: 8px;
}
.wa-msg--out { justify-content: flex-end; }
.wa-msg__bubble {
  max-width: 62%;
  padding: 9px 13px;
  border-radius: 14px;
  border-top-left-radius: 5px;
  background: #333333;
  color: var(--ink);
  font-size: 14px;
  box-shadow: 0 1px 2px rgba(0,0,0,0.25);
  word-wrap: break-word;
}
.wa-msg--out .wa-msg__bubble {
  background: #3a2b66;
  color: #e8ddfb;
  border-top-left-radius: 14px;
  border-top-right-radius: 5px;
}
.wa-msg__type-icon {
  font-size: 12px;
  color: var(--muted);
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
  background: rgba(255,255,255,0.08);
  border-radius: 8px;
  text-decoration: none;
  color: #b794f6;
  font-size: 13px;
}
.wa-msg__loc {
  font-size: 13px;
  background: rgba(255,255,255,0.08);
  padding: 8px;
  border-radius: 8px;
}
.wa-msg__body { white-space: pre-wrap; }
.wa-msg__time {
  font-size: 10px;
  color: var(--muted);
  margin-top: 4px;
  text-align: right;
}
.wa-msg--out .wa-msg__time { color: #a78bf0; }
.wa-msg__sender {
  display: flex;
  align-items: baseline;
  gap: 6px;
  margin-bottom: 3px;
}
.wa-msg__sender-name {
  font-size: .75rem;
  font-weight: 700;
  color: #b794f6;
}
.wa-msg__sender-phone {
  font-size: .7rem;
  color: var(--muted);
}

.wa-composer {
  display: flex;
  gap: 11px;
  padding: 14px 20px;
  background: var(--panel);
  border-top: 1px solid var(--line);
  align-items: center;
}
.wa-composer__input {
  flex: 1;
  border: 1px solid var(--line);
  background: #242424;
  border-radius: 14px;
  padding: 11px 14px;
  font-size: 14.5px;
  color: var(--ink);
  outline: none;
  font-family: inherit;
}
.wa-composer__input::placeholder { color: var(--muted); }
.wa-composer__input:focus { border-color: var(--brand); background: #2b2b2b; }
.wa-composer__send {
  background: var(--brand);
  color: #fff;
  border: none;
  border-radius: 13px;
  padding: 0 18px;
  height: 44px;
  font-weight: 700;
  cursor: pointer;
}
.wa-composer__send:hover:not(:disabled) { background: var(--brand-deep); }
.wa-composer__send:disabled { background: #3a3a3a; color: var(--muted); cursor: not-allowed; }

.wa-composer__attach {
  background: none;
  border: none;
  font-size: 22px;
  cursor: pointer;
  padding: 0 6px;
  color: var(--muted);
}
.wa-composer__attach:disabled { opacity: .4; cursor: not-allowed; }
.wa-composer__attach:hover:not(:disabled) { color: var(--brand); }

.wa-attach {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 14px;
  background: var(--panel);
  border-top: 1px solid var(--line);
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
  background: rgba(255,255,255,0.08);
  border-radius: 8px;
}
.wa-attach__meta { flex: 1; min-width: 0; }
.wa-attach__name {
  font-size: 13px;
  font-weight: 600;
  color: var(--ink);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.wa-attach__size {
  font-size: 11px;
  color: var(--muted);
}
.wa-attach__cancel {
  background: none;
  border: none;
  font-size: 16px;
  color: var(--muted);
  cursor: pointer;
  padding: 4px 8px;
}
.wa-attach__cancel:hover { color: #f08a7e; }

.wa-warn {
  margin: 0;
  padding: 6px 14px;
  background: rgba(234,179,8,0.16);
  color: #e8c96e;
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
  border-radius: 999px;
  background: var(--brand);
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  line-height: 1;
  margin-left: auto;
}

/* Degradê roxo passando horizontalmente ao receber mensagem */
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
  background: linear-gradient(90deg, transparent 0%, rgba(139,92,246,0.35) 40%, rgba(139,92,246,0.5) 50%, rgba(139,92,246,0.35) 60%, transparent 100%);
  animation: msg-sweep 0.4s ease-out forwards;
  pointer-events: none;
}

.wa-audio-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 12px;
  background: var(--hover);
  border: 1px solid var(--line);
  border-radius: 999px;
  color: var(--ink-2);
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  line-height: 1;
  opacity: .85;
  transition: opacity .2s, background .2s;
}
.wa-audio-btn:hover { opacity: 1; background: rgba(255,255,255,0.1); }

.wa-tab-btn {
  font-size: 12.5px;
  font-weight: 600;
  padding: 6px 13px;
  border: none;
  border-radius: 999px;
  background: var(--hover);
  color: var(--ink-2);
  cursor: pointer;
  white-space: nowrap;
}
.wa-tab-btn:hover { background: rgba(255,255,255,0.1); }
.wa-tab-btn--active {
  background: var(--brand);
  color: #fff;
}
.wa-side__webhooks {
  flex: 1;
  overflow-y: auto;
  background: var(--panel);
}

.wa-msg__reactions {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 4px;
}
.wa-reaction {
  background: rgba(255,255,255,0.1);
  border-radius: 999px;
  padding: 1px 6px;
  font-size: .8rem;
  cursor: default;
  user-select: none;
}
</style>
