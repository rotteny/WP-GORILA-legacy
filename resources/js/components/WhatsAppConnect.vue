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

    <!-- Alternativa ao QR: parear com código de 8 dígitos (Baileys pairing code) -->
    <div v-if="status !== 'CONNECTED'" class="wa-pair">
      <button
        v-if="!showPairPanel"
        class="wa-btn wa-btn--secondary"
        type="button"
        @click="openPairPanel"
      >
        Conectar usando número
      </button>

      <div v-else class="wa-pair__panel">
        <template v-if="!pairCode">
          <label class="wa-pair__label" for="wa-pair-phone">
            Número do WhatsApp (DDD + número)
          </label>
          <div class="wa-pair__input-group">
            <span class="wa-pair__prefix">+55</span>
            <input
              id="wa-pair-phone"
              v-model="pairPhone"
              class="wa-pair__input"
              type="tel"
              inputmode="numeric"
              placeholder="11999999999"
              :disabled="pairLoading"
              @keyup.enter="requestPairCode"
            />
          </div>
          <p v-if="pairError" class="wa-pair__error">{{ pairError }}</p>
          <div class="wa-pair__actions">
            <button
              class="wa-btn wa-btn--primary"
              type="button"
              :disabled="pairLoading"
              @click="requestPairCode"
            >
              {{ pairLoading ? 'Gerando...' : 'Gerar código' }}
            </button>
            <button class="wa-btn wa-btn--ghost" type="button" @click="closePairPanel">
              Cancelar
            </button>
          </div>
        </template>

        <template v-else>
          <p class="wa-pair__hint">
            No WhatsApp do celular do chip, vá em <strong>Aparelhos conectados →
            Conectar com número de telefone</strong> e digite o código:
          </p>
          <div class="wa-pair__code">{{ pairCode }}</div>
          <p class="wa-meta">
            <template v-if="pairExpiresIn > 0">
              Expira em {{ pairExpiresIn }}s
            </template>
            <template v-else>
              Código expirado — gere um novo.
            </template>
          </p>
          <button class="wa-btn wa-btn--ghost" type="button" @click="resetPairPanel">
            Gerar outro código
          </button>
        </template>
      </div>
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
      // Pareamento por código de 8 dígitos
      showPairPanel: false,
      pairPhone: '',
      pairCode: null,
      pairLoading: false,
      pairError: null,
      pairExpiresIn: 0,
      pairTimer: null,
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
    pairCodeEndpoint() {
      return `/api/whatsapp/instances/${this.instanceSlug}/pair-code`;
    },
  },

  mounted() {
    this.fetchStatus();
    this.connectEcho();
  },

  beforeUnmount() {
    this.disconnectEcho();
    this.stopPolling();
    this.clearPairTimer();
  },

  // Compat Vue 2
  beforeDestroy() {
    this.disconnectEcho();
    this.stopPolling();
    this.clearPairTimer();
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

    openPairPanel() {
      this.showPairPanel = true;
      this.pairError = null;
    },

    closePairPanel() {
      this.showPairPanel = false;
      this.resetPairPanel();
    },

    // Volta o painel pro estado de entrada (número), descartando o código atual.
    resetPairPanel() {
      this.clearPairTimer();
      this.pairCode = null;
      this.pairError = null;
      this.pairExpiresIn = 0;
    },

    async requestPairCode() {
      let national = this.pairPhone.replace(/\D/g, '');
      // O DDI 55 é prefixo fixo. Se o usuário digitou o 55 mesmo assim, remove
      // pra não duplicar (número nacional tem 10-11 dígitos: DDD + número).
      if (national.startsWith('55') && national.length > 11) {
        national = national.slice(2);
      }
      if (national.length < 10 || national.length > 11) {
        this.pairError = 'Informe DDD + número (ex.: 11999999999).';
        return;
      }
      const phone = `55${national}`;

      this.pairLoading = true;
      this.pairError = null;
      try {
        const { data } = await axios.post(this.pairCodeEndpoint, { phone });
        this.pairCode = data.code;
        this.startPairCountdown(data.expires_in_seconds || 60);
      } catch (err) {
        this.pairError =
          err.response?.data?.error ||
          'Não foi possível gerar o código agora. Tente novamente.';
      } finally {
        this.pairLoading = false;
      }
    },

    startPairCountdown(seconds) {
      this.clearPairTimer();
      this.pairExpiresIn = seconds;
      this.pairTimer = setInterval(() => {
        this.pairExpiresIn -= 1;
        if (this.pairExpiresIn <= 0) {
          this.clearPairTimer();
        }
      }, 1000);
    },

    clearPairTimer() {
      if (this.pairTimer) {
        clearInterval(this.pairTimer);
        this.pairTimer = null;
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
.wa-btn--secondary {
  background: var(--brand-soft);
  color: var(--brand);
}
.wa-btn--secondary:hover:not(:disabled) { background: var(--hover); }
.wa-btn--ghost {
  background: transparent;
  color: var(--muted);
  border: 1px solid var(--line);
}
.wa-btn--ghost:hover:not(:disabled) { background: var(--hover); }
.wa-btn:disabled {
  opacity: .6;
  cursor: not-allowed;
}

/* ---- Pareamento por código de 8 dígitos ---- */
.wa-pair {
  margin-top: 1rem;
}
.wa-pair__panel {
  margin-top: .5rem;
  padding: 1rem;
  border-radius: 12px;
  background: var(--hover);
  text-align: center;
}
.wa-pair__label {
  display: block;
  font-size: .82rem;
  font-weight: 700;
  color: var(--ink-2);
  margin-bottom: .4rem;
  text-align: left;
}
.wa-pair__input-group {
  display: flex;
  align-items: stretch;
  border: 1px solid var(--line);
  border-radius: 10px;
  background: var(--panel);
  overflow: hidden;
}
.wa-pair__input-group:focus-within {
  border-color: var(--brand);
}
.wa-pair__prefix {
  display: flex;
  align-items: center;
  padding: 0 .7rem;
  background: var(--hover);
  color: var(--ink-2);
  font-weight: 700;
  font-size: 1rem;
  border-right: 1px solid var(--line);
}
.wa-pair__input {
  flex: 1;
  min-width: 0;
  box-sizing: border-box;
  padding: .6rem .75rem;
  border: none;
  background: transparent;
  color: var(--ink);
  font-family: inherit;
  font-size: 1rem;
  letter-spacing: .04em;
}
.wa-pair__input:focus {
  outline: none;
}
.wa-pair__error {
  margin-top: .5rem;
  font-size: .82rem;
  color: #f08a7e;
  text-align: left;
}
.wa-pair__actions {
  margin-top: .75rem;
  display: flex;
  gap: .5rem;
  justify-content: center;
  flex-wrap: wrap;
}
.wa-pair__hint {
  font-size: .85rem;
  color: var(--ink-2);
  margin-bottom: .75rem;
}
.wa-pair__code {
  font-size: 2.2rem;
  font-weight: 800;
  letter-spacing: .18em;
  color: var(--brand);
  padding: .75rem 0;
  font-variant-numeric: tabular-nums;
  user-select: all;
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
