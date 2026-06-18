<template>
  <div class="screen">
    <Topbar />

    <div class="connect-body">
      <div class="connect-card">
        <a href="/" class="back">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
            <path d="M15 18l-6-6 6-6" />
          </svg>
          Projetos
        </a>

        <h1 class="connect-title">
          Conexão WhatsApp<span v-if="projectName"> — {{ projectName }}</span>
        </h1>

        <div v-if="loading && !status" class="state state--loading">
          <div class="spinner" aria-hidden="true"></div>
          <p>Carregando status...</p>
        </div>

        <div v-else-if="status === 'CONNECTED'" class="state state--ok">
          <span class="pill on"><span class="dot"></span>Conectado</span>
          <p class="state-msg">WhatsApp conectado com sucesso.</p>
          <p v-if="lastEventAt" class="state-meta">Última atualização: {{ formatDate(lastEventAt) }}</p>
          <a :href="`/p/${instanceSlug}/chat`" class="btn-primary">Abrir conversas →</a>
        </div>

        <div v-else-if="hasQr" class="state state--qr">
          <span class="pill warn"><span class="dot"></span>Aguardando QR</span>
          <p class="state-msg">Escaneie o QR Code abaixo com o WhatsApp do celular:</p>
          <div class="qr-wrap">
            <qrcode-vue
              :value="qrCode"
              :size="260"
              level="M"
              background="#ffffff"
              foreground="#212121"
            />
          </div>
          <p class="state-meta">Status atual: {{ status }}</p>
        </div>

        <div v-else-if="status === 'RECONNECTING'" class="state state--warn">
          <span class="pill warn-orange"><span class="dot"></span>Reconectando</span>
          <p class="state-msg">Tentando restabelecer a conexão...</p>
        </div>

        <div v-else-if="status === 'LOGGED_OUT'" class="state state--err">
          <span class="pill off"><span class="dot"></span>Desconectado</span>
          <p class="state-msg">Sessão encerrada. Gere um novo QR para reconectar.</p>
        </div>

        <div v-else class="state state--waiting">
          <div class="spinner" aria-hidden="true"></div>
          <p class="state-msg">Aguardando o serviço gerar o QR Code...</p>
          <p class="state-meta">Status: {{ status || 'desconhecido' }}</p>
        </div>

        <div class="connect-actions">
          <button
            class="btn-ghost"
            type="button"
            :disabled="loading || resetting"
            @click="fetchStatus"
          >
            Atualizar agora
          </button>
          <button
            class="btn-danger"
            type="button"
            :disabled="resetting"
            @click="resetConnection"
          >
            {{ resetting ? 'Resetando...' : 'Gerar novo QR' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import axios from 'axios';
import QrcodeVue from 'qrcode.vue';
import Topbar from './Topbar.vue';

export default {
  name: 'WhatsAppConnect',

  components: { QrcodeVue, Topbar },

  props: {
    instanceSlug: {
      type: String,
      required: true,
    },
  },

  data() {
    return {
      status: null,
      qrCode: null,
      lastEventAt: null,
      projectName: null,
      loading: false,
      resetting: false,
      pollHandle: null,
      pollIntervalMs: 3000,
    };
  },

  computed: {
    hasQr() {
      return Boolean(this.qrCode) && this.status !== 'CONNECTED';
    },
    statusEndpoint() {
      return `/api/whatsapp/instances/${this.instanceSlug}/status`;
    },
    resetEndpoint() {
      return `/api/whatsapp/instances/${this.instanceSlug}/reset`;
    },
  },

  mounted() {
    this.fetchStatus();
    this.startPolling();
  },

  beforeUnmount() {
    this.stopPolling();
  },

  beforeDestroy() {
    this.stopPolling();
  },

  methods: {
    startPolling() {
      if (this.pollHandle) return;
      this.pollHandle = setInterval(this.fetchStatus, this.pollIntervalMs);
    },

    stopPolling() {
      if (this.pollHandle) {
        clearInterval(this.pollHandle);
        this.pollHandle = null;
      }
    },

    async fetchStatus() {
      this.loading = true;
      try {
        const { data } = await axios.get(this.statusEndpoint);
        this.status = data.status;
        this.qrCode = data.qr || data.qr_code || null;
        this.lastEventAt = data.last_event_at || data.last_update || null;
        this.projectName = data.name || null;

        if (this.status === 'CONNECTED') {
          this.stopPolling();
        }
      } catch (err) {
        console.error('Erro ao buscar status do WhatsApp:', err);
      } finally {
        this.loading = false;
      }
    },

    async resetConnection() {
      if (!confirm('Isto vai apagar a sessão atual e gerar um novo QR Code. Continuar?')) return;
      this.resetting = true;
      try {
        await axios.post(this.resetEndpoint);
        this.status = 'INITIALIZING';
        this.qrCode = null;
        this.startPolling();
        await this.fetchStatus();
      } catch (err) {
        console.error('Erro ao resetar conexão:', err);
        alert('Não foi possível resetar agora. Tente novamente em alguns segundos.');
      } finally {
        this.resetting = false;
      }
    },

    formatDate(value) {
      try {
        return new Date(value).toLocaleString('pt-BR');
      } catch (_e) {
        return value;
      }
    },
  },
};
</script>

<style scoped>
.screen {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

.connect-body {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 32px 24px 48px;
  min-height: calc(100vh - 64px);
}

.connect-card {
  width: 100%;
  max-width: 480px;
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: var(--radius);
  padding: 28px 32px 32px;
  box-shadow: var(--shadow-lg);
  text-align: center;
}

.back {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: var(--brand);
  font-weight: 700;
  font-size: 13.5px;
  margin-bottom: 20px;
  text-decoration: none;
  transition: opacity 0.12s;
  float: left;
}

.back:hover {
  opacity: 0.7;
}

.connect-title {
  clear: both;
  margin: 0 0 24px;
  font-size: 20px;
  font-weight: 800;
  letter-spacing: -0.02em;
  text-align: left;
}

.state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  padding: 8px 0 20px;
}

.state-msg {
  margin: 0;
  font-size: 14.5px;
  color: var(--ink-2);
  line-height: 1.5;
}

.state-meta {
  margin: 0;
  font-size: 12.5px;
  color: var(--muted);
}

.spinner {
  width: 32px;
  height: 32px;
  border: 3px solid var(--line);
  border-top-color: var(--brand);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12.5px;
  font-weight: 700;
  padding: 5px 11px;
  border-radius: 999px;
}

.pill.on {
  background: var(--brand-soft);
  color: #b794f6;
}

.pill.warn {
  background: rgba(234, 179, 8, 0.2);
  color: #e8c96e;
}

.pill.warn-orange {
  background: rgba(251, 146, 60, 0.2);
  color: #fbbf7a;
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

.qr-wrap {
  background: #fff;
  border-radius: 16px;
  padding: 16px;
  display: inline-block;
  box-shadow: var(--shadow);
}

.connect-actions {
  display: flex;
  gap: 10px;
  justify-content: center;
  flex-wrap: wrap;
  margin-top: 8px;
  padding-top: 20px;
  border-top: 1px solid var(--line);
}

.btn-primary {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: var(--brand);
  color: #fff;
  font-weight: 700;
  font-size: 14.5px;
  padding: 12px 20px;
  border-radius: 13px;
  box-shadow: 0 6px 18px rgba(139, 92, 246, 0.35);
  transition: transform 0.12s, box-shadow 0.12s, background 0.12s;
  text-decoration: none;
  border: none;
  cursor: pointer;
  font-family: inherit;
  margin-top: 8px;
}

.btn-primary:hover {
  background: var(--brand-deep);
  transform: translateY(-1px);
  box-shadow: 0 10px 24px rgba(139, 92, 246, 0.45);
}

.btn-primary:active {
  transform: translateY(0);
}

.btn-ghost {
  font-weight: 700;
  font-size: 14.5px;
  padding: 12px 20px;
  border-radius: 13px;
  background: transparent;
  color: var(--ink-2);
  border: 1px solid var(--line);
  transition: background 0.12s, color 0.12s, border-color 0.12s;
  cursor: pointer;
  font-family: inherit;
}

.btn-ghost:hover:not(:disabled) {
  background: var(--hover);
  color: var(--ink);
  border-color: #4a4a4a;
}

.btn-ghost:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-danger {
  font-weight: 700;
  font-size: 14.5px;
  padding: 12px 20px;
  border-radius: 13px;
  background: rgba(240, 90, 75, 0.16);
  color: #f08a7e;
  border: 1px solid rgba(240, 90, 75, 0.3);
  transition: background 0.12s, transform 0.12s;
  cursor: pointer;
  font-family: inherit;
}

.btn-danger:hover:not(:disabled) {
  background: rgba(240, 90, 75, 0.28);
  transform: translateY(-1px);
}

.btn-danger:active:not(:disabled) {
  transform: scale(0.97);
}

.btn-danger:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
