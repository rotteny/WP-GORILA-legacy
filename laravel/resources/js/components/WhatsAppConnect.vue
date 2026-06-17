<template>
  <div class="wa-connect">
    <h2>Conexão WhatsApp</h2>

    <div v-if="loading && !status" class="wa-state wa-state--loading">
      Carregando status...
    </div>

    <div v-else-if="status === 'CONNECTED'" class="wa-state wa-state--ok">
      <span class="wa-dot wa-dot--ok"></span>
      WhatsApp conectado com sucesso.
      <p v-if="lastEventAt" class="wa-meta">
        Última atualização: {{ formatDate(lastEventAt) }}
      </p>
      <p style="margin-top:1rem;">
        <a href="/chat" class="wa-link">Abrir conversas →</a>
      </p>
    </div>

    <div v-else-if="hasQr" class="wa-state wa-state--pending">
      <p>Escaneie o QR Code abaixo com o WhatsApp do celular:</p>
      <qrcode-vue :value="qrCode" :size="260" level="M" />
      <p class="wa-meta">Status atual: {{ status }}</p>
    </div>

    <div v-else class="wa-state wa-state--waiting">
      <span class="wa-dot wa-dot--warn"></span>
      Aguardando o serviço gerar o QR Code... (status: {{ status || 'desconhecido' }})
    </div>

    <div class="wa-actions">
      <button
        class="wa-btn wa-btn--primary"
        type="button"
        @click="fetchStatus"
        :disabled="loading || resetting"
      >
        Atualizar agora
      </button>
      <button
        class="wa-btn wa-btn--danger"
        type="button"
        @click="resetConnection"
        :disabled="resetting"
      >
        {{ resetting ? 'Resetando...' : 'Gerar novo QR' }}
      </button>
    </div>
  </div>
</template>

<script>
import axios from 'axios';
import QrcodeVue from 'qrcode.vue';

export default {
  name: 'WhatsAppConnect',

  components: { QrcodeVue },

  data() {
    return {
      status: null,
      qrCode: null,
      lastEventAt: null,
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
  },

  mounted() {
    this.fetchStatus();
    this.startPolling();
  },

  beforeUnmount() {
    this.stopPolling();
  },

  // Compat Vue 2
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
        const { data } = await axios.get('/api/whatsapp/status');
        this.status = data.status;
        this.qrCode = data.qr_code || null;
        this.lastEventAt = data.last_event_at || null;

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
        await axios.post('/api/whatsapp/reset');
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
.wa-connect {
  max-width: 420px;
  margin: 2rem auto;
  padding: 1.5rem;
  border-radius: 12px;
  background: #fff;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
  font-family: system-ui, sans-serif;
  text-align: center;
}

.wa-state {
  margin: 1rem 0;
  padding: 1rem;
  border-radius: 8px;
}

.wa-state--loading   { background: #f1f5f9; color: #475569; }
.wa-state--ok        { background: #ecfdf5; color: #065f46; }
.wa-state--pending   { background: #fffbeb; color: #92400e; }
.wa-state--waiting   { background: #fef2f2; color: #991b1b; }

.wa-dot {
  display: inline-block;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  margin-right: 6px;
}
.wa-dot--ok   { background: #10b981; }
.wa-dot--warn { background: #f59e0b; }

.wa-meta {
  margin-top: .5rem;
  font-size: .85rem;
  opacity: .75;
}

.wa-actions {
  margin-top: 1rem;
  display: flex;
  gap: .5rem;
  justify-content: center;
  flex-wrap: wrap;
}
.wa-btn {
  padding: .5rem 1rem;
  border: none;
  border-radius: 6px;
  color: #fff;
  cursor: pointer;
  font-weight: 500;
}
.wa-btn--primary { background: #2563eb; }
.wa-btn--danger  { background: #dc2626; }
.wa-btn:disabled {
  opacity: .6;
  cursor: not-allowed;
}
.wa-link {
  display: inline-block;
  padding: .5rem 1rem;
  background: #008069;
  color: #fff;
  border-radius: 6px;
  text-decoration: none;
  font-weight: 600;
}
.wa-link:hover { background: #006e57; }
</style>
