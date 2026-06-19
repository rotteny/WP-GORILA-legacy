<template>
  <div class="wh-wrap">
    <div class="wh-header">
      <div class="wh-header__title">Configuracao de Webhooks</div>
      <div class="wh-header__sub">Receba notificacoes em tempo real nos seus endpoints</div>
    </div>

    <div v-if="loadError" class="wh-alert wh-alert--error">
      {{ loadError }}
    </div>

    <div v-if="loading" class="wh-loading">Carregando configuracoes...</div>

    <div v-else class="wh-list">
      <div
        v-for="ev in EVENTS"
        :key="ev.key"
        class="wh-row"
      >
        <div class="wh-row__head">
          <label class="wh-toggle" :title="configs[ev.key].active ? 'Desativar' : 'Ativar'">
            <input
              type="checkbox"
              class="wh-toggle__input"
              v-model="configs[ev.key].active"
            />
            <span class="wh-toggle__track"></span>
          </label>
          <div class="wh-row__labels">
            <span class="wh-row__name">{{ ev.label }}</span>
            <span class="wh-row__desc">{{ ev.desc }}</span>
          </div>
        </div>

        <div class="wh-row__body">
          <div class="wh-field">
            <label class="wh-field__label">URL do endpoint</label>
            <input
              type="url"
              class="wh-field__input"
              :class="{ 'wh-field__input--error': configs[ev.key].error }"
              v-model="configs[ev.key].url"
              placeholder="https://seu-servidor.com/webhook"
              :disabled="configs[ev.key].saving"
            />
            <div v-if="configs[ev.key].error" class="wh-field__error">
              {{ configs[ev.key].error }}
            </div>
          </div>

          <div class="wh-secret">
            <button
              type="button"
              class="wh-secret__toggle"
              @click="configs[ev.key].showSecret = !configs[ev.key].showSecret"
            >
              {{ configs[ev.key].showSecret ? 'Ocultar segredo' : 'Mostrar segredo' }}
            </button>
            <div v-if="configs[ev.key].showSecret" class="wh-field wh-secret__field">
              <label class="wh-field__label">Chave secreta (opcional)</label>
              <input
                type="text"
                class="wh-field__input"
                v-model="configs[ev.key].secret"
                placeholder="Assina os payloads via HMAC"
                :disabled="configs[ev.key].saving"
              />
            </div>
          </div>

          <div class="wh-row__actions">
            <button
              type="button"
              class="wh-btn"
              :class="{
                'wh-btn--saving': configs[ev.key].saving,
                'wh-btn--saved': configs[ev.key].saved,
              }"
              :disabled="configs[ev.key].saving"
              @click="save(ev.key)"
            >
              <span v-if="configs[ev.key].saving">Salvando...</span>
              <span v-else-if="configs[ev.key].saved">Salvo!</span>
              <span v-else>Salvar</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import axios from 'axios';

const EVENTS = [
  { key: 'message',          label: 'Nova mensagem',    desc: 'Toda mensagem recebida' },
  { key: 'message_deleted',  label: 'Mensagem apagada', desc: 'Quando uma mensagem e deletada' },
  { key: 'message_reaction', label: 'Reacao',           desc: 'Curtidas e emojis em mensagens' },
  { key: 'connection',       label: 'Conexao',          desc: 'Status da conexao WhatsApp' },
];

function emptyConfig() {
  return { url: '', active: false, secret: '', showSecret: false, saving: false, saved: false, error: '' };
}

export default {
  name: 'WebhookSettings',

  props: {
    instanceSlug: {
      type: String,
      required: true,
    },
  },

  data() {
    return {
      EVENTS,
      loading: false,
      loadError: '',
      configs: {
        message:          emptyConfig(),
        message_deleted:  emptyConfig(),
        message_reaction: emptyConfig(),
        connection:       emptyConfig(),
      },
    };
  },

  computed: {
    apiBase() {
      return `/api/whatsapp/instances/${this.instanceSlug}/webhooks`;
    },
  },

  mounted() {
    this.fetchConfigs();
  },

  methods: {
    async fetchConfigs() {
      this.loading = true;
      this.loadError = '';
      try {
        const { data } = await axios.get(this.apiBase);
        (data || []).forEach((item) => {
          if (this.configs[item.event]) {
            this.configs[item.event].url    = item.url    || '';
            this.configs[item.event].active = !!item.active;
            this.configs[item.event].secret = item.secret || '';
          }
        });
      } catch (e) {
        this.loadError = 'Falha ao carregar configuracoes. Tente recarregar a pagina.';
        console.error('WebhookSettings.fetchConfigs:', e);
      } finally {
        this.loading = false;
      }
    },

    async save(eventKey) {
      const cfg = this.configs[eventKey];
      cfg.error = '';

      if (cfg.active && !cfg.url.trim()) {
        cfg.error = 'Informe a URL antes de ativar o webhook.';
        return;
      }

      cfg.saving = true;
      try {
        await axios.put(this.apiBase, {
          event:  eventKey,
          url:    cfg.url.trim(),
          active: cfg.active,
          secret: cfg.secret.trim() || null,
        });

        cfg.saved = true;
        setTimeout(() => { cfg.saved = false; }, 2000);
      } catch (e) {
        cfg.error =
          e?.response?.data?.message ||
          e?.response?.data?.error   ||
          'Erro ao salvar. Verifique a URL e tente novamente.';
        console.error('WebhookSettings.save:', e);
      } finally {
        cfg.saving = false;
      }
    },
  },
};
</script>

<style scoped>
.wh-wrap {
  padding: 16px;
  font-family: system-ui, sans-serif;
  background: #fff;
  min-height: 100%;
}

.wh-header {
  margin-bottom: 16px;
  padding-bottom: 12px;
  border-bottom: 1px solid #d1d7db;
}
.wh-header__title {
  font-size: 15px;
  font-weight: 600;
  color: #1e293b;
}
.wh-header__sub {
  font-size: 12px;
  color: #54656f;
  margin-top: 2px;
}

.wh-loading {
  text-align: center;
  padding: 32px 16px;
  color: #667781;
  font-size: 14px;
}

.wh-alert {
  padding: 10px 12px;
  border-radius: 6px;
  font-size: 13px;
  margin-bottom: 12px;
}
.wh-alert--error {
  background: #fad4d4;
  color: #842029;
  border: 1px solid #f5c2c7;
}

.wh-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.wh-row {
  border: 1px solid #d1d7db;
  border-radius: 8px;
  overflow: hidden;
  background: #fff;
}

.wh-row__head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  background: #f0f2f5;
  border-bottom: 1px solid #d1d7db;
}

.wh-row__labels {
  display: flex;
  flex-direction: column;
  gap: 1px;
}
.wh-row__name {
  font-size: 13px;
  font-weight: 600;
  color: #1e293b;
}
.wh-row__desc {
  font-size: 11px;
  color: #54656f;
}

.wh-row__body {
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

/* Toggle */
.wh-toggle {
  position: relative;
  display: inline-flex;
  align-items: center;
  cursor: pointer;
  flex-shrink: 0;
}
.wh-toggle__input {
  position: absolute;
  opacity: 0;
  width: 0;
  height: 0;
}
.wh-toggle__track {
  display: inline-block;
  width: 36px;
  height: 20px;
  border-radius: 10px;
  background: #d1d7db;
  transition: background 0.2s;
  position: relative;
}
.wh-toggle__track::after {
  content: '';
  position: absolute;
  top: 3px;
  left: 3px;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: #fff;
  transition: transform 0.2s;
  box-shadow: 0 1px 2px rgba(0,0,0,0.2);
}
.wh-toggle__input:checked + .wh-toggle__track {
  background: #008069;
}
.wh-toggle__input:checked + .wh-toggle__track::after {
  transform: translateX(16px);
}

/* Fields */
.wh-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.wh-field__label {
  font-size: 11px;
  font-weight: 600;
  color: #54656f;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
.wh-field__input {
  width: 100%;
  border: 1px solid #d1d7db;
  border-radius: 6px;
  padding: 8px 10px;
  font-size: 13px;
  color: #1e293b;
  background: #fff;
  outline: none;
  transition: border-color 0.15s;
  box-sizing: border-box;
}
.wh-field__input:focus {
  border-color: #008069;
}
.wh-field__input:disabled {
  background: #f0f2f5;
  color: #94a3b8;
  cursor: not-allowed;
}
.wh-field__input--error {
  border-color: #dc2626;
}
.wh-field__error {
  font-size: 12px;
  color: #dc2626;
}

/* Secret section */
.wh-secret__toggle {
  background: none;
  border: none;
  padding: 0;
  font-size: 12px;
  color: #008069;
  cursor: pointer;
  text-decoration: underline;
  text-underline-offset: 2px;
}
.wh-secret__toggle:hover {
  color: #006654;
}
.wh-secret__field {
  margin-top: 8px;
}

/* Actions */
.wh-row__actions {
  display: flex;
  justify-content: flex-end;
}

.wh-btn {
  padding: 7px 18px;
  border-radius: 6px;
  border: 1px solid #d1d7db;
  background: #fff;
  color: #54656f;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s, color 0.15s, border-color 0.15s;
  min-width: 80px;
  text-align: center;
}
.wh-btn:hover:not(:disabled) {
  background: #008069;
  color: #fff;
  border-color: #008069;
}
.wh-btn:disabled {
  cursor: not-allowed;
  opacity: 0.65;
}
.wh-btn--saving {
  background: #f0f2f5;
  color: #54656f;
  border-color: #d1d7db;
}
.wh-btn--saved {
  background: #d8f3dc;
  color: #095c2a;
  border-color: #95d5a0;
}
</style>
