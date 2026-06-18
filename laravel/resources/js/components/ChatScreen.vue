<template>
  <div class="screen">
    <Topbar />

    <div class="chat-shell">
      <!-- SIDEBAR -->
      <aside class="sidebar">
        <div class="sb-head">
          <a href="/" class="back">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
              <path d="M15 18l-6-6 6-6" />
            </svg>
            Projetos
          </a>
          <div class="sb-title">
            <div>
              <h2>Conversas</h2>
              <div class="proj">{{ projectName || instanceSlug }}</div>
            </div>
            <span class="pill" :class="status === 'CONNECTED' ? 'on' : 'off'">
              <span class="dot"></span>{{ statusLabel }}
            </span>
          </div>
          <div class="search">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <circle cx="11" cy="11" r="7" /><path d="M21 21l-4-4" />
            </svg>
            <input v-model="q" placeholder="Buscar conversa..." />
          </div>
          <div class="filters">
            <button
              v-for="f in filters"
              :key="f"
              class="chip"
              :class="{ active: filter === f }"
              type="button"
              @click="filter = f"
            >
              {{ f }}
            </button>
          </div>
        </div>

        <div class="conv-list">
          <div v-if="loadingChats && chats.length === 0" class="conv-empty">
            Carregando...
          </div>
          <div v-else-if="filteredChats.length === 0" class="conv-empty">
            Nenhuma conversa encontrada.
          </div>
          <button
            v-for="c in filteredChats"
            :key="c.jid"
            class="conv"
            :class="{ active: c.jid === activeJid }"
            type="button"
            @click="openChat(c.jid)"
          >
            <div class="av-c" :style="avatarStyle(c.jid)">{{ chatInitial(c) }}</div>
            <div class="conv-body">
              <div class="conv-row1">
                <span class="conv-name">{{ chatLabel(c) }}</span>
                <span class="tag" :class="tagClass(c.chat_type)">{{ chatTypeLabel(c.chat_type) }}</span>
                <span class="conv-time">{{ formatTime(c.last_message?.received_at) }}</span>
              </div>
              <div class="conv-prev">
                <span v-if="c.last_message?.from_me" class="you">Você: </span>{{ messagePreview(c.last_message) }}
              </div>
            </div>
          </button>
        </div>
      </aside>

      <!-- PANE -->
      <section class="pane">
        <div v-if="!activeJid" class="pane-empty">
          <div class="ill">
            <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z" />
            </svg>
          </div>
          <h3>Selecione uma conversa</h3>
          <p>Escolha uma conversa à esquerda para visualizar e responder mensagens.</p>
        </div>

        <template v-else>
          <div class="pane-head">
            <div class="av-c" :style="avatarStyle(activeJid)">{{ activeInitial }}</div>
            <div>
              <div class="nm">{{ activeLabel }}</div>
              <div class="sub">{{ chatTypeLabel(activeChatType) }} · {{ activeJid }}</div>
            </div>
          </div>

          <div ref="msgBox" class="thread">
            <div v-if="loadingMessages && messages.length === 0" class="conv-empty">Carregando mensagens...</div>
            <div
              v-for="m in messages"
              :key="m.whatsapp_message_id"
              class="msg"
              :class="m.from_me ? 'out' : 'in'"
            >
              <img
                v-if="m.type === 'image' || m.type === 'sticker'"
                :src="mediaUrl(m)"
                class="msg-media msg-image"
                loading="lazy"
                @error="onMediaError($event, m)"
              />
              <video
                v-else-if="m.type === 'video'"
                :src="mediaUrl(m)"
                controls
                preload="metadata"
                class="msg-media msg-video"
              />
              <audio
                v-else-if="m.type === 'audio'"
                :src="mediaUrl(m)"
                controls
                preload="metadata"
                class="msg-media msg-audio"
              />
              <a
                v-else-if="m.type === 'document'"
                :href="mediaUrl(m)"
                target="_blank"
                rel="noopener"
                class="msg-doc"
              >
                📄 {{ m.body || 'documento' }}
              </a>
              <div v-else-if="m.type === 'location'" class="msg-loc">
                📍 Localização
                <code v-if="m.body">{{ m.body }}</code>
              </div>
              <div v-else-if="m.type !== 'text'" class="msg-type-icon">
                {{ typeIcon(m.type) }} <em>{{ m.type }}</em>
              </div>
              <div
                v-if="m.body && m.type !== 'document' && m.type !== 'location'"
                class="msg-text"
              >{{ m.body }}</div>
              <span class="t">{{ formatTime(m.received_at) }}</span>
            </div>
          </div>

          <div v-if="pendingFile" class="attach-preview">
            <img v-if="filePreviewUrl" :src="filePreviewUrl" class="attach-thumb" alt="" />
            <span v-else class="attach-icon">{{ fileIcon(pendingFile) }}</span>
            <div class="attach-meta">
              <div class="attach-name">{{ pendingFile.name }}</div>
              <div class="attach-size">{{ formatBytes(pendingFile.size) }} · {{ pendingFile.type || 'desconhecido' }}</div>
            </div>
            <button class="attach-cancel" type="button" :disabled="sending" @click="clearFile">✕</button>
          </div>

          <div class="composer">
            <input
              ref="fileInput"
              type="file"
              hidden
              accept="image/*,video/*,audio/*,application/pdf,application/zip,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/plain"
              @change="onFileChosen"
            />
            <button
              type="button"
              class="icon-btn"
              :disabled="sending || !canSendToActive"
              title="Anexar arquivo"
              @click="$refs.fileInput.click()"
            >
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48" />
              </svg>
            </button>
            <div class="field">
              <input
                v-model="draft"
                type="text"
                :placeholder="pendingFile ? 'Legenda (opcional)...' : 'Escreva uma mensagem...'"
                :disabled="sending || !canSendToActive"
                @keydown.enter="send"
              />
            </div>
            <button
              class="send"
              type="button"
              :disabled="sending || !canSendToActive || (!draft.trim() && !pendingFile)"
              @click="send"
            >
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                <path d="M3.4 20.4l17.4-7.5c.8-.4.8-1.5 0-1.9L3.4 3.6c-.7-.3-1.4.2-1.4 1l1 6 9 1.4-9 1.4-1 6c0 .8.7 1.3 1.4 1z" />
              </svg>
            </button>
          </div>
          <p v-if="!canSendToActive" class="composer-warn">
            Envio não suportado neste tipo de conversa ({{ chatTypeLabel(activeChatType) }}).
          </p>
        </template>
      </section>
    </div>
  </div>
</template>

<script>
import axios from 'axios';
import Topbar from './Topbar.vue';

const POLL_MS = 3000;

export default {
  name: 'ChatScreen',

  components: { Topbar },

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
      q: '',
      filter: 'Todos',
      filters: ['Todos', '1:1', 'Grupo', 'Canal'],
      loadingChats: false,
      loadingMessages: false,
      sending: false,
      pollHandle: null,
      pendingFile: null,
      filePreviewUrl: null,
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
    filteredChats() {
      let list = this.chats;
      if (this.filter === '1:1') {
        list = list.filter((c) => ['private', 'private_lid'].includes(c.chat_type));
      } else if (this.filter === 'Grupo') {
        list = list.filter((c) => c.chat_type === 'group');
      } else if (this.filter === 'Canal') {
        list = list.filter((c) => c.chat_type === 'newsletter');
      }
      const query = this.q.trim().toLowerCase();
      if (query) {
        list = list.filter(
          (c) =>
            this.chatLabel(c).toLowerCase().includes(query) ||
            this.messagePreview(c.last_message).toLowerCase().includes(query),
        );
      }
      return list;
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
      return ['private', 'private_lid', 'group'].includes(this.activeChatType);
    },
    apiBase() {
      return `/api/whatsapp/instances/${this.instanceSlug}`;
    },
  },

  mounted() {
    this.refreshAll();
    this.pollHandle = setInterval(this.refreshAll, POLL_MS);
  },

  beforeUnmount() {
    this.stopPolling();
    this.clearFile();
  },

  beforeDestroy() {
    this.stopPolling();
    this.clearFile();
  },

  methods: {
    stopPolling() {
      if (this.pollHandle) {
        clearInterval(this.pollHandle);
        this.pollHandle = null;
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
      this.fetchMessages(jid);
    },

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
      event.target.value = '';
      if (!file) return;

      const MAX = 20 * 1024 * 1024;
      if (file.size > MAX) {
        alert(`Arquivo muito grande (${this.formatBytes(file.size)}). Limite: 20 MB.`);
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
      if (mime.includes('pdf')) return '📕';
      if (mime.includes('zip')) return '🗜️';
      if (mime.includes('word')) return '📘';
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
      return c.jid.split('@')[0].split('-')[0];
    },

    chatInitial(c) {
      return this.chatLabel(c).slice(-2).toUpperCase();
    },

    avatarStyle(jid) {
      let h = 0;
      for (let i = 0; i < (jid || '').length; i++) h = (h * 31 + jid.charCodeAt(i)) >>> 0;
      const hue = h % 360;
      return { background: `hsl(${hue}, 55%, 50%)` };
    },

    tagClass(t) {
      return ({
        private: 'um',
        private_lid: 'lid',
        group: 'grupo',
        newsletter: 'canal',
      })[t] || 'um';
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
      } catch {
        return iso;
      }
    },

    mediaUrl(m) {
      return `${this.apiBase}/media/${encodeURIComponent(m.whatsapp_message_id)}`;
    },

    onMediaError(event, m) {
      console.warn('falha ao carregar mídia', m.whatsapp_message_id);
      event.target.replaceWith(
        Object.assign(document.createElement('div'), {
          textContent: '⚠️ Não foi possível carregar a mídia',
          style: 'color:#f08a7e; font-size:12px;',
        }),
      );
    },
  },
};
</script>

<style scoped>
.screen {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.chat-shell {
  display: flex;
  height: calc(100vh - 64px);
}

.sidebar {
  width: 380px;
  flex: none;
  background: var(--panel);
  border-right: 1px solid var(--line);
  display: flex;
  flex-direction: column;
}

.sb-head {
  padding: 18px 20px 14px;
  border-bottom: 1px solid var(--line);
}

.back {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: var(--brand);
  font-weight: 700;
  font-size: 13.5px;
  margin-bottom: 12px;
  text-decoration: none;
  transition: opacity 0.12s;
}

.back:hover {
  opacity: 0.7;
}

.sb-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.sb-title h2 {
  margin: 0;
  font-size: 21px;
  font-weight: 800;
  letter-spacing: -0.02em;
}

.proj {
  font-size: 12.5px;
  color: var(--muted);
  margin-top: 2px;
}

.pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12.5px;
  font-weight: 700;
  padding: 5px 11px;
  border-radius: 999px;
  flex-shrink: 0;
}

.pill.on {
  background: var(--brand-soft);
  color: #b794f6;
}

.pill.off {
  background: rgba(240, 90, 75, 0.16);
  color: #f08a7e;
}

.pill .dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: currentColor;
}

.search {
  margin-top: 14px;
  position: relative;
}

.search input {
  width: 100%;
  border: 1px solid var(--line);
  background: #242424;
  border-radius: 12px;
  padding: 11px 12px 11px 38px;
  font-size: 14px;
  font-family: inherit;
  color: var(--ink);
  transition: border-color 0.12s, background 0.12s;
  box-sizing: border-box;
}

.search input::placeholder {
  color: var(--muted);
}

.search input:focus {
  outline: none;
  border-color: var(--brand);
  background: #2b2b2b;
}

.search svg {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--muted);
  pointer-events: none;
}

.filters {
  display: flex;
  gap: 7px;
  margin-top: 13px;
  flex-wrap: wrap;
}

.chip {
  font-size: 12.5px;
  font-weight: 600;
  padding: 6px 13px;
  border-radius: 999px;
  background: var(--hover);
  color: var(--ink-2);
  transition: background 0.12s, color 0.12s;
  cursor: pointer;
  font-family: inherit;
  border: none;
}

.chip.active {
  background: var(--brand);
  color: #fff;
}

.chip:hover:not(.active) {
  background: rgba(255, 255, 255, 0.1);
}

.conv-list {
  flex: 1;
  overflow-y: auto;
  padding: 8px;
}

.conv-empty {
  padding: 24px;
  color: var(--muted);
  text-align: center;
  font-size: 14px;
}

.conv {
  display: flex;
  align-items: center;
  gap: 13px;
  padding: 11px 13px;
  border-radius: 14px;
  cursor: pointer;
  transition: background 0.1s;
  position: relative;
  width: 100%;
  text-align: left;
  border: none;
  background: none;
  color: inherit;
  font-family: inherit;
}

.conv:hover {
  background: var(--hover);
}

.conv.active {
  background: var(--brand-soft);
}

.conv.active::before {
  content: '';
  position: absolute;
  left: 0;
  top: 11px;
  bottom: 11px;
  width: 3.5px;
  border-radius: 99px;
  background: var(--brand);
}

.av-c {
  width: 46px;
  height: 46px;
  border-radius: 50%;
  flex: none;
  display: grid;
  place-items: center;
  color: #fff;
  font-weight: 700;
  font-size: 15px;
}

.conv-body {
  flex: 1;
  min-width: 0;
}

.conv-row1 {
  display: flex;
  align-items: center;
  gap: 7px;
}

.conv-name {
  font-weight: 700;
  font-size: 14px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.tag {
  font-size: 10.5px;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 6px;
  flex: none;
  letter-spacing: 0.01em;
}

.tag.canal {
  background: rgba(168, 85, 247, 0.2);
  color: #c8a4f7;
}

.tag.grupo {
  background: rgba(59, 130, 246, 0.2);
  color: #92bef8;
}

.tag.um {
  background: rgba(255, 255, 255, 0.09);
  color: #9aa6a1;
}

.tag.lid {
  background: rgba(234, 179, 8, 0.2);
  color: #e8c96e;
}

.conv-time {
  font-size: 11.5px;
  color: var(--muted);
  flex: none;
  margin-left: auto;
}

.conv-prev {
  font-size: 13px;
  color: var(--muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-top: 3px;
}

.conv-prev .you {
  color: var(--ink-2);
  font-weight: 600;
}

.pane {
  flex: 1;
  display: flex;
  flex-direction: column;
  background: #1c1c1c;
  min-width: 0;
}

.pane-empty {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: var(--muted);
  text-align: center;
  padding: 40px;
}

.pane-empty .ill {
  width: 96px;
  height: 96px;
  border-radius: 30px;
  background: var(--panel);
  box-shadow: var(--shadow);
  display: grid;
  place-items: center;
  margin-bottom: 14px;
  color: var(--brand);
}

.pane-empty h3 {
  margin: 0;
  font-size: 19px;
  font-weight: 700;
  color: var(--ink-2);
}

.pane-empty p {
  margin: 0;
  font-size: 14px;
  max-width: 280px;
}

.pane-head {
  height: 70px;
  flex: none;
  display: flex;
  align-items: center;
  gap: 13px;
  padding: 0 22px;
  background: var(--panel);
  border-bottom: 1px solid var(--line);
}

.nm {
  font-weight: 700;
  font-size: 15px;
}

.sub {
  font-size: 12px;
  color: var(--muted);
  margin-top: 1px;
}

.thread {
  flex: 1;
  overflow-y: auto;
  padding: 24px 26px;
  display: flex;
  flex-direction: column;
  gap: 9px;
  background-image: radial-gradient(circle at 1px 1px, rgba(255, 255, 255, 0.03) 1px, transparent 0);
  background-size: 22px 22px;
}

.msg {
  max-width: 62%;
  padding: 9px 13px;
  border-radius: 14px;
  font-size: 14px;
  line-height: 1.4;
  position: relative;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.25);
  word-wrap: break-word;
}

.msg.in {
  align-self: flex-start;
  background: #333;
  border-top-left-radius: 5px;
}

.msg.out {
  align-self: flex-end;
  background: #3a2b66;
  color: #e8ddfb;
  border-top-right-radius: 5px;
}

.msg .t {
  font-size: 10px;
  color: var(--muted);
  float: right;
  margin: 6px 0 -2px 10px;
  font-weight: 500;
}

.msg.out .t {
  color: #a78bf0;
}

.msg-text {
  white-space: pre-wrap;
}

.msg-media {
  display: block;
  border-radius: 8px;
  margin-bottom: 4px;
}

.msg-image {
  max-width: 260px;
  max-height: 260px;
  cursor: zoom-in;
}

.msg-video {
  max-width: 280px;
}

.msg-audio {
  width: 240px;
}

.msg-doc {
  display: inline-block;
  padding: 8px 10px;
  background: rgba(255, 255, 255, 0.08);
  border-radius: 8px;
  text-decoration: none;
  color: #b794f6;
  font-size: 13px;
  margin-bottom: 4px;
}

.msg-loc {
  font-size: 13px;
  background: rgba(255, 255, 255, 0.08);
  padding: 8px;
  border-radius: 8px;
  margin-bottom: 4px;
}

.msg-loc code {
  font-size: 11px;
  display: block;
  margin-top: 4px;
  color: var(--muted);
}

.msg-type-icon {
  font-size: 12px;
  color: var(--muted);
  margin-bottom: 4px;
}

.attach-preview {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 20px;
  background: var(--panel);
  border-top: 1px solid var(--line);
}

.attach-thumb {
  width: 44px;
  height: 44px;
  border-radius: 8px;
  object-fit: cover;
}

.attach-icon {
  width: 44px;
  height: 44px;
  display: grid;
  place-items: center;
  font-size: 24px;
  background: #242424;
  border-radius: 8px;
}

.attach-meta {
  flex: 1;
  min-width: 0;
}

.attach-name {
  font-size: 13px;
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.attach-size {
  font-size: 11px;
  color: var(--muted);
}

.attach-cancel {
  background: none;
  border: none;
  font-size: 16px;
  color: var(--muted);
  cursor: pointer;
  padding: 4px 8px;
}

.attach-cancel:hover {
  color: #f08a7e;
}

.composer {
  flex: none;
  padding: 14px 20px;
  background: var(--panel);
  border-top: 1px solid var(--line);
  display: flex;
  align-items: center;
  gap: 11px;
}

.composer .field {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 8px;
  background: #242424;
  border: 1px solid var(--line);
  border-radius: 14px;
  padding: 4px 6px 4px 14px;
  transition: border-color 0.12s;
}

.composer .field:focus-within {
  border-color: var(--brand);
  background: #2b2b2b;
}

.composer input {
  flex: 1;
  border: none;
  background: none;
  outline: none;
  font-size: 14.5px;
  font-family: inherit;
  padding: 8px 0;
  color: var(--ink);
}

.composer input::placeholder {
  color: var(--muted);
}

.icon-btn {
  width: 38px;
  height: 38px;
  border-radius: 11px;
  display: grid;
  place-items: center;
  color: var(--muted);
  transition: background 0.12s, color 0.12s;
  cursor: pointer;
  border: none;
  background: none;
  flex-shrink: 0;
}

.icon-btn:hover:not(:disabled) {
  background: var(--hover);
  color: var(--ink-2);
}

.icon-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.send {
  width: 44px;
  height: 44px;
  border-radius: 13px;
  background: var(--brand);
  color: #fff;
  display: grid;
  place-items: center;
  flex: none;
  transition: background 0.12s, transform 0.1s;
  border: none;
  cursor: pointer;
}

.send:hover:not(:disabled) {
  background: var(--brand-deep);
}

.send:active:not(:disabled) {
  transform: scale(0.94);
}

.send:disabled {
  background: #3a3a3a;
  color: var(--muted);
  cursor: default;
}

.composer-warn {
  margin: 0;
  padding: 6px 20px;
  background: rgba(234, 179, 8, 0.15);
  color: #e8c96e;
  font-size: 12px;
  text-align: center;
}
</style>
