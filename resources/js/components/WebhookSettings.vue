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
  font-family: var(--font, 'Plus Jakarta Sans'), system-ui, sans-serif;
  background: var(--panel);
  color: var(--ink);
  min-height: 100%;
}

.wh-header {
  margin-bottom: 16px;
  padding-bottom: 12px;
  border-bottom: 1px solid var(--line);
}
.wh-header__title {
  font-size: 15px;
  font-weight: 700;
  color: var(--ink);
}
.wh-header__sub {
  font-size: 12px;
  color: var(--muted);
  margin-top: 2px;
}

.wh-loading {
  text-align: center;
  padding: 32px 16px;
  color: var(--muted);
  font-size: 14px;
}

.wh-alert {
  padding: 10px 12px;
  border-radius: 8px;
  font-size: 13px;
  margin-bottom: 12px;
}
.wh-alert--error {
  background: rgba(248,113,113,0.16);
  color: #f08a7e;
  border: 1px solid rgba(248,113,113,0.3);
}

.wh-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.wh-row {
  border: 1px solid var(--line);
  border-radius: 12px;
  overflow: hidden;
  background: var(--bg);
}

.wh-row__head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  background: var(--hover);
  border-bottom: 1px solid var(--line);
}

.wh-row__labels {
  display: flex;
  flex-direction: column;
  gap: 1px;
}
.wh-row__name {
  font-size: 13px;
  font-weight: 700;
  color: var(--ink);
}
.wh-row__desc {
  font-size: 11px;
  color: var(--muted);
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
  background: var(--line);
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
  background: var(--brand);
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
  font-weight: 700;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
.wh-field__input {
  width: 100%;
  border: 1px solid var(--line);
  border-radius: 10px;
  padding: 9px 11px;
  font-size: 13px;
  color: var(--ink);
  background: #242424;
  outline: none;
  transition: border-color 0.15s;
  box-sizing: border-box;
  font-family: inherit;
}
.wh-field__input::placeholder { color: var(--muted); }
.wh-field__input:focus {
  border-color: var(--brand);
}
.wh-field__input:disabled {
  background: var(--hover);
  color: var(--muted);
  cursor: not-allowed;
}
.wh-field__input--error {
  border-color: #f08a7e;
}
.wh-field__error {
  font-size: 12px;
  color: #f08a7e;
}

/* Secret section */
.wh-secret__toggle {
  background: none;
  border: none;
  padding: 0;
  font-size: 12px;
  color: var(--brand);
  cursor: pointer;
  text-decoration: underline;
  text-underline-offset: 2px;
}
.wh-secret__toggle:hover {
  opacity: 0.8;
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
  padding: 8px 18px;
  border-radius: 11px;
  border: 1px solid var(--line);
  background: var(--hover);
  color: var(--ink-2);
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  transition: background 0.15s, color 0.15s, border-color 0.15s;
  min-width: 80px;
  text-align: center;
  font-family: inherit;
}
.wh-btn:hover:not(:disabled) {
  background: var(--brand);
  color: #fff;
  border-color: var(--brand);
}
.wh-btn:disabled {
  cursor: not-allowed;
  opacity: 0.65;
}
.wh-btn--saving {
  background: var(--hover);
  color: var(--muted);
  border-color: var(--line);
}
.wh-btn--saved {
  background: var(--brand-soft);
  color: #b794f6;
  border-color: transparent;
}
</style>
