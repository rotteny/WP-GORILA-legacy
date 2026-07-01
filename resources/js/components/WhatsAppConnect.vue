<template>
  <div class="wa-connect">
    <header class="wa-connect__header">
      <a :href="backHref" class="wa-back">← Voltar</a>
      <h2 class="wa-connect__title">
        Conexão WhatsApp<span v-if="projectName"> — {{ projectName }}</span>
      </h2>
    </header>

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
        <a :href="`/p/${instanceSlug}/chat${backSuffix}`" class="wa-link">Abrir conversas →</a>
      </p>
    </div>

    <div v-else-if="hasQr" class="wa-state wa-state--pending">
      <p>Escaneie o QR Code abaixo com o WhatsApp do celular:</p>
      <div class="wa-qr-wrapper">
        <qrcode-vue :value="qrCode" :size="260" level="M" />
      </div>
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
      echoChannel: null,
    };
  },

  computed: {
    // Destino do "voltar": usa ?back=/caminho-interno se veio de um projeto; senão a home.
    backHref() {
      const back = new URLSearchParams(window.location.search).get('back');
      return back && back.startsWith('/') ? back : '/';
    },
    // Repassa o ?back ao navegar pro chat, pra preservar o contexto do projeto.
    backSuffix() {
      const back = new URLSearchParams(window.location.search).get('back');
      return back && back.startsWith('/') ? `?back=${encodeURIComponent(back)}` : '';
    },
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
    this.connectEcho();
  },

  beforeUnmount() {
    this.disconnectEcho();
    this.stopPolling();
  },

  // Compat Vue 2
  beforeDestroy() {
    this.disconnectEcho();
    this.stopPolling();
  },

  methods: {
    connectEcho() {
      if (!window.Echo) {
        // Fallback: polling se Echo não disponível
        this.startPolling();
        return;
      }
      this.echoChannel = window.Echo.channel(`instance.${this.instanceSlug}`)
        .listen('.InstanceUpdated', (data) => {
          this.status      = data.status;
          this.qrCode      = data.qr_code || null;
          this.lastEventAt = data.last_event_at || null;
          this.projectName = data.name || null;
          if (this.status === 'CONNECTED') {
            this.disconnectEcho();
          }
        })
        .error(() => {
          // Fallback para polling se WS falhar
          this.startPolling();
        });
    },

    disconnectEcho() {
      if (this.echoChannel) {
        window.Echo.leaveChannel(`instance.${this.instanceSlug}`);
        this.echoChannel = null;
      }
      this.stopPolling();
    },

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
        this.qrCode = data.qr_code || null;
        this.lastEventAt = data.last_event_at || null;
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
.wa-connect {
  max-width: 460px;
  margin: 3rem auto;
  padding: 1.75rem;
  border-radius: var(--radius);
  background: var(--panel);
  border: 1px solid var(--line);
  box-shadow: var(--shadow);
  font-family: var(--font, 'Plus Jakarta Sans'), system-ui, sans-serif;
  color: var(--ink);
  text-align: center;
}

.wa-connect__header {
  text-align: left;
  margin-bottom: 1rem;
}
.wa-connect__title {
  margin: .35rem 0 0;
  font-size: 1.2rem;
  font-weight: 800;
  letter-spacing: -0.02em;
}
.wa-back {
  display: inline-block;
  font-size: .82rem;
  color: var(--brand);
  font-weight: 700;
  text-decoration: none;
}
.wa-back:hover { opacity: .8; }

.wa-state {
  margin: 1rem 0;
  padding: 1rem;
  border-radius: 12px;
}

.wa-state--loading   { background: var(--hover); color: var(--ink-2); }
.wa-state--ok        { background: var(--brand-soft); color: #c4b5fd; }
.wa-state--pending   { background: var(--hover); color: var(--ink-2); }
.wa-state--waiting   { background: rgba(240,90,75,0.16); color: #f08a7e; }

.wa-qr-wrapper {
  display: flex;
  justify-content: center;
  margin: .75rem 0;
}
/* QR precisa de fundo claro para ser escaneável */
.wa-qr-wrapper :deep(svg),
.wa-qr-wrapper :deep(canvas) {
  background: #fff;
  padding: 12px;
  border-radius: 12px;
}

.wa-dot {
  display: inline-block;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  margin-right: 6px;
}
.wa-dot--ok   { background: var(--brand); }
.wa-dot--warn { background: #e8c96e; }

.wa-meta {
  margin-top: .5rem;
  font-size: .85rem;
  color: var(--muted);
}

.wa-actions {
  margin-top: 1rem;
  display: flex;
  gap: .5rem;
  justify-content: center;
  flex-wrap: wrap;
}
.wa-btn {
  padding: .6rem 1.1rem;
  border: none;
  border-radius: 11px;
  color: #fff;
  cursor: pointer;
  font-weight: 700;
  font-family: inherit;
}
.wa-btn--primary { background: var(--brand); }
.wa-btn--primary:hover:not(:disabled) { background: var(--brand-deep); }
.wa-btn--danger  { background: rgba(240,90,75,0.16); color: #f08a7e; }
.wa-btn--danger:hover:not(:disabled) { background: rgba(240,90,75,0.28); }
.wa-btn:disabled {
  opacity: .6;
  cursor: not-allowed;
}
.wa-link {
  display: inline-block;
  padding: .6rem 1.1rem;
  background: var(--brand);
  color: #fff;
  border-radius: 11px;
  text-decoration: none;
  font-weight: 700;
}
.wa-link:hover { background: var(--brand-deep); }
</style>
