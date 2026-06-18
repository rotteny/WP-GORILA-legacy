<template>
  <div class="screen">
    <Topbar />

    <div class="scroll">
      <div class="wrap">
        <div class="page-head">
          <div>
            <a href="/" class="crumb" aria-label="Voltar para Projetos">← Projetos</a>
            <h1>Webhooks <span class="crumb-sep">·</span> <span class="instance-name">{{ instanceName || instanceSlug }}</span></h1>
            <p>Receba eventos em tempo real desta instância em sua URL.</p>
          </div>
          <div class="head-actions">
            <button
              v-if="!loading || webhooks.length > 0"
              class="btn-primary"
              type="button"
              @click="openCreateModal"
            >
              <span aria-hidden="true">+</span> Novo webhook
            </button>
          </div>
        </div>

        <div v-if="!apiKey" class="banner banner--warn" role="alert">
          <strong>API key da UI não configurada.</strong>
          Configure <code>WHATSAPP_UI_API_KEY</code> no <code>.env</code> e refaça o build para que esta tela consiga consultar a API v1.
        </div>

        <div v-if="loadError" class="banner banner--error" role="alert">
          {{ loadError }}
        </div>

        <!-- LOADING -->
        <div v-if="loading && webhooks.length === 0 && !loadError" class="state-empty">
          <p>Carregando webhooks...</p>
        </div>

        <!-- ESTADO VAZIO -->
        <div v-else-if="!loading && webhooks.length === 0 && !loadError" class="state-empty">
          <div class="state-empty__ill" aria-hidden="true">
            <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
              <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
              <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
            </svg>
          </div>
          <h2>Nenhum webhook cadastrado</h2>
          <p>Cadastre um endpoint para receber eventos de mensagens em tempo real.</p>
          <button class="btn-primary" type="button" @click="openCreateModal">Cadastrar primeiro webhook</button>
        </div>

        <!-- LISTA DE WEBHOOKS -->
        <div v-else-if="webhooks.length > 0" class="wh-list">
          <article
            v-for="(wh, idx) in webhooks"
            :key="wh.id"
            class="wh-card"
            :style="{ '--i': idx }"
          >
            <header class="wh-card__head">
              <div class="wh-card__title">
                <h3>{{ wh.name }}</h3>
                <span class="pill" :class="wh.active ? 'pill--ok' : 'pill--off'">
                  <span class="dot"></span>
                  {{ wh.active ? 'Ativo' : 'Inativo' }}
                </span>
              </div>
              <div class="wh-card__url" :title="wh.url">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
                  <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
                </svg>
                <span class="wh-url-text">{{ wh.url }}</span>
              </div>
            </header>

            <div class="chips" aria-label="Eventos inscritos">
              <span v-for="ev in wh.events" :key="ev" class="chip">{{ eventLabel(ev) }}</span>
            </div>

            <div class="wh-stats">
              <div class="wh-stat">
                <span class="wh-stat__label">Último sucesso</span>
                <span class="wh-stat__value">{{ wh.last_success_at ? formatRelative(wh.last_success_at) : '—' }}</span>
              </div>
              <div class="wh-stat">
                <span class="wh-stat__label">Última falha</span>
                <span class="wh-stat__value">{{ wh.last_failure_at ? formatRelative(wh.last_failure_at) : '—' }}</span>
              </div>
              <div class="wh-stat">
                <span class="wh-stat__label">Falhas seguidas</span>
                <span
                  class="wh-stat__value"
                  :class="wh.consecutive_failures > 0 ? 'wh-stat__value--danger' : ''"
                >{{ wh.consecutive_failures || 0 }}</span>
              </div>
            </div>

            <footer class="wh-card__foot">
              <button
                type="button"
                class="btn-ghost btn-ghost--sm"
                @click="openDeliveries(wh)"
                :aria-label="`Ver histórico de deliveries do webhook ${wh.name}`"
              >
                Ver deliveries
              </button>
              <button
                type="button"
                class="btn-danger btn-danger--sm"
                @click="confirmDelete(wh)"
                :aria-label="`Excluir webhook ${wh.name}`"
              >
                Excluir
              </button>
            </footer>
          </article>
        </div>
      </div>
    </div>

    <!-- MODAL DE CRIAÇÃO -->
    <Transition name="pop">
      <div v-if="showCreateModal" class="overlay" @click.self="closeCreateModal">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="wh-create-title">
          <h2 id="wh-create-title">Novo webhook</h2>

          <div v-if="formError" class="modal-error" role="alert">{{ formError }}</div>

          <form @submit.prevent="submitCreate">
            <div class="fgroup">
              <label for="wh-name">Nome</label>
              <input
                id="wh-name"
                v-model="form.name"
                type="text"
                placeholder="Meu CRM"
                maxlength="128"
                :disabled="creating"
                autofocus
              />
            </div>

            <div class="fgroup">
              <label for="wh-url">URL do endpoint</label>
              <input
                id="wh-url"
                v-model="form.url"
                type="text"
                placeholder="https://exemplo.com/webhook"
                :disabled="creating"
                spellcheck="false"
                autocapitalize="off"
                autocorrect="off"
              />
              <p class="help">Deve usar <code>https://</code>. Apenas <code>localhost</code> aceita <code>http://</code>.</p>
            </div>

            <div class="fgroup">
              <label>Eventos</label>
              <div class="event-grid">
                <label
                  v-for="ev in availableEvents"
                  :key="ev.value"
                  class="event-opt"
                  :class="{ 'event-opt--checked': form.events.includes(ev.value) }"
                >
                  <input
                    type="checkbox"
                    :value="ev.value"
                    v-model="form.events"
                    :disabled="creating"
                  />
                  <div class="event-opt__body">
                    <span class="event-opt__name">{{ ev.label }}</span>
                    <span class="event-opt__hint">{{ ev.hint }}</span>
                  </div>
                </label>
              </div>
            </div>

            <div class="modal-actions">
              <button type="button" class="btn-ghost" :disabled="creating" @click="closeCreateModal">
                Cancelar
              </button>
              <button type="submit" class="btn-primary" :disabled="creating">
                {{ creating ? 'Criando...' : 'Criar webhook' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </Transition>

    <!-- MODAL DE SECRET (reveal único) -->
    <Transition name="pop">
      <div v-if="secretReveal" class="overlay" @click.self="dismissSecret">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="wh-secret-title">
          <h2 id="wh-secret-title">Webhook criado com sucesso</h2>
          <p class="secret-lead">
            Guarde o <strong>secret</strong> agora — ele será exibido apenas <strong>uma única vez</strong>.
            Use-o para validar a assinatura HMAC enviada no header <code>X-Webhook-Signature</code>.
          </p>

          <div class="secret-box">
            <code class="secret-value" aria-label="Secret do webhook">{{ secretReveal.secret }}</code>
            <button
              type="button"
              class="btn-copy"
              @click="copySecret"
              aria-label="Copiar secret para a área de transferência"
            >
              {{ secretCopied ? 'Copiado!' : 'Copiar' }}
            </button>
          </div>

          <p class="secret-live" aria-live="polite" role="status">
            <span v-if="secretCopied">Secret copiado para a área de transferência.</span>
          </p>

          <div class="modal-actions">
            <button type="button" class="btn-primary" @click="dismissSecret">Já guardei</button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- MODAL DE CONFIRMAÇÃO DE DELETE -->
    <Transition name="pop">
      <div v-if="deleteTarget" class="overlay" @click.self="cancelDelete">
        <div class="modal modal--sm" role="dialog" aria-modal="true" aria-labelledby="wh-del-title">
          <h2 id="wh-del-title">Excluir webhook</h2>
          <p class="modal-text">
            Tem certeza que deseja excluir <strong>{{ deleteTarget.name }}</strong>?
            Esta ação não pode ser desfeita.
          </p>
          <div class="modal-actions">
            <button type="button" class="btn-ghost" :disabled="deleting" @click="cancelDelete">Cancelar</button>
            <button type="button" class="btn-danger" :disabled="deleting" @click="performDelete">
              {{ deleting ? 'Excluindo...' : 'Excluir' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- DRAWER DE DELIVERIES -->
    <Transition name="pop">
      <div v-if="deliveriesFor" class="overlay" @click.self="closeDeliveries">
        <div class="modal modal--wide" role="dialog" aria-modal="true" aria-labelledby="wh-deliv-title">
          <header class="modal-head">
            <div>
              <h2 id="wh-deliv-title">Deliveries</h2>
              <p class="modal-sub">{{ deliveriesFor.name }} — {{ deliveriesFor.url }}</p>
            </div>
            <button type="button" class="icon-btn" @click="closeDeliveries" aria-label="Fechar">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18" />
                <line x1="6" y1="6" x2="18" y2="18" />
              </svg>
            </button>
          </header>

          <div v-if="deliveriesError" class="modal-error" role="alert">{{ deliveriesError }}</div>

          <div v-if="deliveriesLoading && deliveries.length === 0" class="deliveries-empty">
            Carregando deliveries...
          </div>

          <div v-else-if="!deliveriesLoading && deliveries.length === 0" class="deliveries-empty">
            Nenhuma entrega registrada ainda para este webhook.
          </div>

          <div v-else class="deliveries-wrap">
            <table class="deliveries-table">
              <thead>
                <tr>
                  <th>Data</th>
                  <th>Evento</th>
                  <th>Status</th>
                  <th>Tentativa</th>
                  <th>HTTP</th>
                  <th>Erro</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="d in deliveries" :key="d.id">
                  <td class="dt-col">{{ formatRelative(d.delivered_at || d.failed_at || d.created_at) }}</td>
                  <td class="evt-col"><code>{{ d.event }}</code></td>
                  <td>
                    <span class="pill" :class="statusPillClass(d.status)">
                      <span class="dot"></span>{{ statusLabel(d.status) }}
                    </span>
                  </td>
                  <td class="num-col">{{ d.attempt }} / {{ d.max_attempts }}</td>
                  <td class="num-col">{{ d.response_status ?? '—' }}</td>
                  <td class="err-col" :title="d.error_message || ''">{{ d.error_message || '—' }}</td>
                </tr>
              </tbody>
            </table>

            <div class="deliveries-foot">
              <button
                v-if="deliveriesHasMore"
                type="button"
                class="btn-ghost btn-ghost--sm"
                :disabled="deliveriesLoading"
                @click="loadMoreDeliveries"
              >
                {{ deliveriesLoading ? 'Carregando...' : 'Carregar mais' }}
              </button>
              <span v-else class="deliveries-end">— fim do histórico —</span>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- TOASTS -->
    <div class="toast-stack" aria-live="polite" aria-atomic="false">
      <div
        v-for="t in toasts"
        :key="t.id"
        class="toast"
        :class="`toast--${t.type}`"
        role="status"
      >
        {{ t.text }}
      </div>
    </div>
  </div>
</template>

<script>
import axios from 'axios';
import Topbar from './Topbar.vue';

const AVAILABLE_EVENTS = [
  { value: 'message.received',  label: 'Mensagem recebida',  hint: 'Inbound' },
  { value: 'message.sent',      label: 'Mensagem enviada',   hint: 'Outbound' },
  { value: 'message.delivered', label: 'Mensagem entregue',  hint: '✓✓ cinza' },
  { value: 'message.read',      label: 'Mensagem lida',      hint: '✓✓ azul' },
];

const EVENT_LABELS = AVAILABLE_EVENTS.reduce((acc, ev) => {
  acc[ev.value] = ev.label;
  return acc;
}, {});

const PAGE_LIMIT = 25;

export default {
  name: 'WebhooksScreen',

  components: { Topbar },

  props: {
    instanceSlug: { type: String, required: true },
  },

  data() {
    return {
      instanceName: '',
      webhooks: [],
      loading: false,
      loadError: '',

      showCreateModal: false,
      creating: false,
      formError: '',
      form: { name: '', url: '', events: [] },

      secretReveal: null,
      secretCopied: false,

      deleteTarget: null,
      deleting: false,

      deliveriesFor: null,
      deliveries: [],
      deliveriesLoading: false,
      deliveriesError: '',
      deliveriesCursor: null,
      deliveriesHasMore: false,

      toasts: [],
      toastSeq: 0,

      availableEvents: AVAILABLE_EVENTS,
      apiKey: window.__WP_API_KEY__ || '',
    };
  },

  mounted() {
    this.fetchInstanceMeta();
    this.fetchWebhooks();
    document.addEventListener('keydown', this.handleEscape);
  },

  beforeUnmount() {
    document.removeEventListener('keydown', this.handleEscape);
  },

  beforeDestroy() {
    document.removeEventListener('keydown', this.handleEscape);
  },

  methods: {
    // ---------- HTTP helpers ----------

    v1Client() {
      return axios.create({
        baseURL: `/api/v1/instances/${encodeURIComponent(this.instanceSlug)}`,
        headers: this.apiKey ? { 'X-API-Key': this.apiKey } : {},
      });
    },

    extractApiError(err, fallback) {
      const d = err?.response?.data;
      if (!d) return fallback;
      if (d.errors && typeof d.errors === 'object') {
        return Object.values(d.errors).flat().join(' ');
      }
      return d.message || d.error || fallback;
    },

    // ---------- Fetch ----------

    async fetchInstanceMeta() {
      try {
        const { data } = await axios.get('/api/whatsapp/instances');
        const list = Array.isArray(data) ? data : (data.instances || []);
        const me = list.find((i) => i.slug === this.instanceSlug);
        if (me) this.instanceName = me.name;
      } catch (e) {
        // não bloqueante: fica o slug como título.
      }
    },

    async fetchWebhooks() {
      this.loading = true;
      this.loadError = '';
      try {
        const { data } = await this.v1Client().get('/webhooks');
        this.webhooks = Array.isArray(data?.data) ? data.data : [];
      } catch (err) {
        this.loadError = this.extractApiError(
          err,
          'Não foi possível carregar os webhooks. Verifique sua conexão e a API key.',
        );
      } finally {
        this.loading = false;
      }
    },

    // ---------- Create ----------

    openCreateModal() {
      this.showCreateModal = true;
      this.formError = '';
      this.form = { name: '', url: '', events: [] };
    },

    closeCreateModal() {
      if (this.creating) return;
      this.showCreateModal = false;
    },

    validateUrl(raw) {
      try {
        const u = new URL(raw);
        if (u.protocol === 'https:') return '';
        const localHosts = ['localhost', '127.0.0.1', '::1'];
        if (u.protocol === 'http:' && (localHosts.includes(u.hostname) || u.hostname.endsWith('.localhost'))) {
          return '';
        }
        return 'A URL deve usar https:// (apenas localhost pode usar http://).';
      } catch {
        return 'URL inválida. Inclua o esquema (https://).';
      }
    },

    validateCreate() {
      const name = (this.form.name || '').trim();
      if (!name) return 'Informe um nome para o webhook.';
      if (name.length > 128) return 'O nome deve ter no máximo 128 caracteres.';

      const url = (this.form.url || '').trim();
      if (!url) return 'Informe a URL do endpoint.';
      const urlErr = this.validateUrl(url);
      if (urlErr) return urlErr;

      if (!Array.isArray(this.form.events) || this.form.events.length === 0) {
        return 'Selecione pelo menos um evento.';
      }
      return '';
    },

    async submitCreate() {
      this.formError = '';
      const err = this.validateCreate();
      if (err) { this.formError = err; return; }

      this.creating = true;
      try {
        const { data } = await this.v1Client().post('/webhooks', {
          name: this.form.name.trim(),
          url: this.form.url.trim(),
          events: this.form.events,
        });
        const created = data?.data || data;
        this.showCreateModal = false;
        this.secretCopied = false;
        this.secretReveal = created;
        await this.fetchWebhooks();
        this.pushToast('Webhook criado.', 'success');
      } catch (e) {
        this.formError = this.extractApiError(e, 'Não foi possível criar o webhook agora.');
      } finally {
        this.creating = false;
      }
    },

    async copySecret() {
      const secret = this.secretReveal?.secret;
      if (!secret) return;
      try {
        await navigator.clipboard.writeText(secret);
        this.secretCopied = true;
        setTimeout(() => { this.secretCopied = false; }, 2500);
      } catch {
        this.pushToast('Não foi possível copiar. Selecione manualmente.', 'error');
      }
    },

    dismissSecret() {
      this.secretReveal = null;
      this.secretCopied = false;
    },

    // ---------- Delete ----------

    confirmDelete(webhook) {
      this.deleteTarget = webhook;
    },

    cancelDelete() {
      if (this.deleting) return;
      this.deleteTarget = null;
    },

    async performDelete() {
      if (!this.deleteTarget) return;
      this.deleting = true;
      try {
        await this.v1Client().delete(`/webhooks/${this.deleteTarget.id}`);
        this.pushToast('Webhook excluído.', 'success');
        this.deleteTarget = null;
        await this.fetchWebhooks();
      } catch (e) {
        this.pushToast(
          this.extractApiError(e, 'Falha ao excluir o webhook.'),
          'error',
        );
      } finally {
        this.deleting = false;
      }
    },

    // ---------- Deliveries ----------

    async openDeliveries(webhook) {
      this.deliveriesFor = webhook;
      this.deliveries = [];
      this.deliveriesCursor = null;
      this.deliveriesHasMore = false;
      this.deliveriesError = '';
      await this.loadDeliveries(true);
    },

    async loadDeliveries(reset = false) {
      if (!this.deliveriesFor) return;
      this.deliveriesLoading = true;
      try {
        const params = { limit: PAGE_LIMIT };
        if (!reset && this.deliveriesCursor) params.cursor = this.deliveriesCursor;
        const { data } = await this.v1Client().get(
          `/webhooks/${this.deliveriesFor.id}/deliveries`,
          { params },
        );
        const items = Array.isArray(data?.data) ? data.data : [];
        this.deliveries = reset ? items : [...this.deliveries, ...items];
        const meta = data?.meta || {};
        this.deliveriesCursor = meta.next_cursor || null;
        this.deliveriesHasMore = !!meta.has_more;
      } catch (e) {
        this.deliveriesError = this.extractApiError(e, 'Falha ao carregar deliveries.');
      } finally {
        this.deliveriesLoading = false;
      }
    },

    loadMoreDeliveries() {
      this.loadDeliveries(false);
    },

    closeDeliveries() {
      this.deliveriesFor = null;
      this.deliveries = [];
      this.deliveriesCursor = null;
      this.deliveriesHasMore = false;
      this.deliveriesError = '';
    },

    // ---------- UI helpers ----------

    handleEscape(ev) {
      if (ev.key !== 'Escape') return;
      if (this.secretReveal) return; // não fechar acidentalmente — botão exige confirmação
      if (this.deliveriesFor) this.closeDeliveries();
      else if (this.deleteTarget) this.cancelDelete();
      else if (this.showCreateModal) this.closeCreateModal();
    },

    pushToast(text, type = 'success') {
      const id = ++this.toastSeq;
      this.toasts.push({ id, text, type });
      setTimeout(() => {
        this.toasts = this.toasts.filter((t) => t.id !== id);
      }, 3500);
    },

    eventLabel(value) {
      return EVENT_LABELS[value] || value;
    },

    statusLabel(s) {
      if (s === 'succeeded') return 'Sucesso';
      if (s === 'pending') return 'Pendente';
      if (s === 'failed') return 'Falhou';
      return s || '—';
    },

    statusPillClass(s) {
      if (s === 'succeeded') return 'pill--ok';
      if (s === 'pending') return 'pill--warn';
      if (s === 'failed') return 'pill--err';
      return 'pill--off';
    },

    formatRelative(iso) {
      if (!iso) return '';
      const then = new Date(iso).getTime();
      if (Number.isNaN(then)) return '';
      const diffSec = Math.max(0, Math.floor((Date.now() - then) / 1000));
      if (diffSec < 30) return 'agora';
      if (diffSec < 60) return `há ${diffSec}s`;
      const diffMin = Math.floor(diffSec / 60);
      if (diffMin < 60) return `há ${diffMin} min`;
      const diffH = Math.floor(diffMin / 60);
      if (diffH < 24) return `há ${diffH}h`;
      const diffD = Math.floor(diffH / 24);
      if (diffD === 1) return 'há 1 dia';
      if (diffD < 30) return `há ${diffD} dias`;
      const diffMo = Math.floor(diffD / 30);
      if (diffMo < 12) return `há ${diffMo} ${diffMo === 1 ? 'mês' : 'meses'}`;
      const diffY = Math.floor(diffMo / 12);
      return `há ${diffY} ${diffY === 1 ? 'ano' : 'anos'}`;
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

.scroll {
  height: calc(100vh - 64px);
  overflow-y: auto;
}

.wrap {
  max-width: 1180px;
  margin: 0 auto;
  padding: 40px 36px 80px;
}

.page-head {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 24px;
  margin-bottom: 28px;
}

.crumb {
  display: inline-block;
  color: var(--muted);
  font-size: 13px;
  font-weight: 600;
  text-decoration: none;
  margin-bottom: 10px;
  transition: color 0.12s;
}
.crumb:hover { color: var(--ink-2); }
.crumb:focus-visible {
  outline: 2px solid var(--brand);
  outline-offset: 3px;
  border-radius: 4px;
}

.page-head h1 {
  font-size: 32px;
  font-weight: 800;
  margin: 0 0 6px;
  letter-spacing: -0.03em;
  display: flex;
  align-items: baseline;
  gap: 8px;
  flex-wrap: wrap;
}

.crumb-sep { color: var(--muted); font-weight: 500; }
.instance-name { color: var(--brand); }

.page-head p {
  margin: 0;
  color: var(--muted);
  font-size: 14.5px;
}

.head-actions { flex-shrink: 0; }

/* Banners */
.banner {
  padding: 14px 16px;
  border-radius: 14px;
  font-size: 13.5px;
  line-height: 1.55;
  margin-bottom: 20px;
  border: 1px solid transparent;
}
.banner code {
  background: rgba(255, 255, 255, 0.08);
  padding: 1px 6px;
  border-radius: 5px;
  font-size: 12.5px;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}
.banner strong { font-weight: 700; margin-right: 4px; }
.banner--warn {
  background: rgba(245, 158, 11, 0.10);
  color: #fbbf24;
  border-color: rgba(245, 158, 11, 0.32);
}
.banner--error {
  background: rgba(239, 68, 68, 0.12);
  color: #f08a7e;
  border-color: rgba(239, 68, 68, 0.32);
}

/* Empty state (reuso de InstancesScreen) */
.state-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 64px 24px;
  text-align: center;
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
}
.state-empty__ill {
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
.state-empty h2 {
  margin: 0 0 8px;
  font-size: 19px;
  font-weight: 700;
  color: var(--ink-2);
}
.state-empty p {
  margin: 0 0 24px;
  font-size: 14px;
  color: var(--muted);
  max-width: 360px;
}

/* Lista de webhooks */
.wh-list {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
}

.wh-card {
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: var(--radius);
  padding: 22px;
  box-shadow: var(--shadow);
  display: flex;
  flex-direction: column;
  gap: 16px;
  animation: cardIn 320ms cubic-bezier(0.0, 0.0, 0.2, 1) both;
  animation-delay: calc(var(--i, 0) * 30ms);
  transition: border-color 0.14s, transform 0.14s;
}
.wh-card:hover { border-color: #4a4a4a; }

@keyframes cardIn {
  from { opacity: 0; transform: translateY(6px); }
  to   { opacity: 1; transform: translateY(0); }
}

.wh-card__head {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.wh-card__title {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.wh-card__title h3 {
  margin: 0;
  font-size: 17px;
  font-weight: 700;
  letter-spacing: -0.01em;
}

.wh-card__url {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: var(--muted);
  font-size: 13px;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  max-width: 100%;
}
.wh-url-text {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 100%;
}

/* Chips de eventos */
.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.chip {
  display: inline-flex;
  align-items: center;
  padding: 4px 10px;
  font-size: 12px;
  font-weight: 600;
  color: var(--ink-2);
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid var(--line);
  border-radius: 999px;
}

/* Stats do card */
.wh-stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
  padding-top: 14px;
  border-top: 1px solid var(--line);
}
.wh-stat {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}
.wh-stat__label {
  font-size: 11.5px;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  font-weight: 600;
}
.wh-stat__value {
  font-size: 14px;
  color: var(--ink);
  font-weight: 600;
}
.wh-stat__value--danger { color: #ef4444; }

.wh-card__foot {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  padding-top: 8px;
}

/* Pills */
.pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 700;
  padding: 4px 10px;
  border-radius: 999px;
}
.pill .dot {
  width: 6px; height: 6px;
  border-radius: 50%;
  background: currentColor;
}
.pill--ok   { background: rgba(34, 197, 94, 0.14);  color: #22c55e; }
.pill--warn { background: rgba(245, 158, 11, 0.16); color: #f59e0b; }
.pill--err  { background: rgba(239, 68, 68, 0.16);  color: #ef4444; }
.pill--off  { background: rgba(255, 255, 255, 0.06); color: var(--muted); }

/* Botões */
.btn-primary {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: var(--brand);
  color: #fff;
  font-weight: 700;
  font-size: 14px;
  padding: 11px 18px;
  border-radius: 13px;
  box-shadow: 0 6px 18px rgba(139, 92, 246, 0.35);
  transition: transform 0.12s, box-shadow 0.12s, background 0.12s;
  border: none;
  cursor: pointer;
  font-family: inherit;
}
.btn-primary:hover:not(:disabled) {
  background: var(--brand-deep);
  transform: translateY(-1px);
  box-shadow: 0 10px 24px rgba(139, 92, 246, 0.45);
}
.btn-primary:active:not(:disabled) { transform: translateY(0); }
.btn-primary:disabled {
  background: #3a3a3a;
  color: var(--muted);
  box-shadow: none;
  cursor: default;
}
.btn-primary:focus-visible {
  outline: 2px solid #c4b5fd;
  outline-offset: 2px;
}

.btn-ghost {
  font-weight: 700;
  font-size: 14px;
  padding: 11px 18px;
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
.btn-ghost:focus-visible {
  outline: 2px solid var(--brand);
  outline-offset: 2px;
}
.btn-ghost--sm { padding: 8px 14px; font-size: 13px; border-radius: 10px; }

.btn-danger {
  font-weight: 700;
  font-size: 14px;
  padding: 11px 18px;
  border-radius: 13px;
  background: rgba(239, 68, 68, 0.14);
  color: #f08a7e;
  border: 1px solid rgba(239, 68, 68, 0.32);
  transition: background 0.12s, color 0.12s, border-color 0.12s;
  cursor: pointer;
  font-family: inherit;
}
.btn-danger:hover:not(:disabled) {
  background: rgba(239, 68, 68, 0.22);
  color: #ff9d92;
  border-color: rgba(239, 68, 68, 0.5);
}
.btn-danger:focus-visible {
  outline: 2px solid #ef4444;
  outline-offset: 2px;
}
.btn-danger:disabled { opacity: 0.5; cursor: default; }
.btn-danger--sm { padding: 8px 14px; font-size: 13px; border-radius: 10px; }

.icon-btn {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: grid;
  place-items: center;
  color: var(--muted);
  background: transparent;
  border: 1px solid transparent;
  transition: background 0.12s, color 0.12s, border-color 0.12s;
}
.icon-btn:hover { background: var(--hover); color: var(--ink); }
.icon-btn:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }

/* Overlay + modal */
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  backdrop-filter: blur(6px);
  display: grid;
  place-items: center;
  z-index: 50;
  padding: 24px;
}

.modal {
  width: 100%;
  max-width: 520px;
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 22px;
  box-shadow: var(--shadow-lg);
  padding: 28px;
}
.modal--sm { max-width: 420px; }
.modal--wide { max-width: 920px; padding: 24px 24px 20px; }

.modal-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 16px;
}

.modal h2 {
  margin: 0 0 14px;
  font-size: 21px;
  font-weight: 800;
  letter-spacing: -0.02em;
}
.modal--wide .modal-head h2 { margin: 0; }

.modal-sub {
  margin: 4px 0 0;
  font-size: 13px;
  color: var(--muted);
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 600px;
}

.modal-text {
  margin: 0 0 22px;
  font-size: 14.5px;
  color: var(--ink-2);
  line-height: 1.55;
}

.modal-error {
  background: rgba(239, 68, 68, 0.14);
  color: #f08a7e;
  padding: 10px 12px;
  border-radius: 10px;
  font-size: 13px;
  margin-bottom: 16px;
  border: 1px solid rgba(239, 68, 68, 0.3);
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 22px;
}

/* Form */
.fgroup { margin-bottom: 18px; }
.fgroup label {
  display: block;
  font-size: 13px;
  font-weight: 700;
  color: var(--ink-2);
  margin-bottom: 8px;
}
.fgroup input[type="text"] {
  width: 100%;
  border: 1px solid var(--line);
  background: #242424;
  border-radius: 13px;
  padding: 13px 15px;
  font-size: 14.5px;
  font-family: inherit;
  color: var(--ink);
  transition: border-color 0.12s, background 0.12s, box-shadow 0.12s;
  box-sizing: border-box;
}
.fgroup input[type="text"]::placeholder { color: var(--muted); }
.fgroup input[type="text"]:focus {
  outline: none;
  border-color: var(--brand);
  background: #2b2b2b;
  box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.14);
}
.fgroup input[type="text"]:disabled { opacity: 0.6; cursor: not-allowed; }

.help {
  font-size: 12.5px;
  color: var(--muted);
  margin: 6px 0 0;
  line-height: 1.5;
}
.help code {
  background: rgba(255, 255, 255, 0.08);
  padding: 1px 5px;
  border-radius: 4px;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 12px;
}

/* Event checkboxes */
.event-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
}
.event-opt {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px 14px;
  border: 1px solid var(--line);
  border-radius: 12px;
  background: #242424;
  cursor: pointer;
  transition: background 0.12s, border-color 0.12s;
}
.event-opt:hover { background: #2a2a2a; border-color: #4a4a4a; }
.event-opt--checked {
  background: rgba(139, 92, 246, 0.10);
  border-color: var(--brand);
}
.event-opt input[type="checkbox"] {
  margin-top: 3px;
  width: 16px; height: 16px;
  accent-color: var(--brand);
  cursor: pointer;
}
.event-opt:focus-within {
  outline: 2px solid var(--brand);
  outline-offset: 2px;
}
.event-opt__body { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.event-opt__name { font-size: 13.5px; font-weight: 700; color: var(--ink); }
.event-opt__hint { font-size: 12px; color: var(--muted); }

/* Secret reveal */
.secret-lead {
  margin: 0 0 16px;
  font-size: 14px;
  color: var(--ink-2);
  line-height: 1.55;
}
.secret-lead code {
  background: rgba(255, 255, 255, 0.08);
  padding: 1px 6px;
  border-radius: 5px;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 12.5px;
}
.secret-box {
  display: flex;
  align-items: stretch;
  gap: 0;
  border: 1px solid rgba(245, 158, 11, 0.5);
  background: rgba(245, 158, 11, 0.08);
  border-radius: 12px;
  overflow: hidden;
}
.secret-value {
  flex: 1;
  padding: 12px 14px;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 12.5px;
  color: #fbbf24;
  word-break: break-all;
  user-select: all;
}
.btn-copy {
  flex-shrink: 0;
  padding: 0 16px;
  background: rgba(245, 158, 11, 0.18);
  color: #fbbf24;
  font-weight: 700;
  font-size: 13px;
  border: none;
  border-left: 1px solid rgba(245, 158, 11, 0.5);
  cursor: pointer;
  transition: background 0.12s;
  font-family: inherit;
}
.btn-copy:hover { background: rgba(245, 158, 11, 0.3); }
.btn-copy:focus-visible { outline: 2px solid #fbbf24; outline-offset: -2px; }

.secret-live {
  min-height: 18px;
  margin: 8px 0 0;
  font-size: 12.5px;
  color: #22c55e;
}

/* Deliveries */
.deliveries-empty {
  padding: 40px 24px;
  text-align: center;
  color: var(--muted);
  font-size: 14px;
}
.deliveries-wrap {
  max-height: 60vh;
  overflow: auto;
  border: 1px solid var(--line);
  border-radius: 12px;
}
.deliveries-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.deliveries-table th {
  text-align: left;
  font-weight: 700;
  color: var(--ink-2);
  background: #242424;
  padding: 10px 12px;
  border-bottom: 1px solid var(--line);
  position: sticky;
  top: 0;
  font-size: 11.5px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.deliveries-table td {
  padding: 10px 12px;
  border-bottom: 1px solid var(--line);
  color: var(--ink);
  vertical-align: middle;
}
.deliveries-table tr:last-child td { border-bottom: none; }
.deliveries-table code {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 12px;
  color: var(--ink-2);
}
.dt-col  { white-space: nowrap; color: var(--muted); }
.evt-col { white-space: nowrap; }
.num-col { white-space: nowrap; font-variant-numeric: tabular-nums; }
.err-col {
  max-width: 280px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: #f08a7e;
  font-size: 12.5px;
}
.deliveries-foot {
  display: flex;
  justify-content: center;
  align-items: center;
  padding: 14px;
}
.deliveries-end { font-size: 12px; color: var(--muted); }

/* Transição modal */
.pop-enter-active {
  transition: opacity 180ms cubic-bezier(0.0, 0.0, 0.2, 1),
              transform 180ms cubic-bezier(0.0, 0.0, 0.2, 1);
}
.pop-leave-active {
  transition: opacity 140ms cubic-bezier(0.4, 0.0, 1, 1),
              transform 140ms cubic-bezier(0.4, 0.0, 1, 1);
}
.pop-enter-from, .pop-leave-to { opacity: 0; }
.pop-enter-from .modal, .pop-leave-to .modal {
  transform: translateY(8px) scale(0.98);
}

/* Toasts */
.toast-stack {
  position: fixed;
  bottom: 24px;
  right: 24px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  z-index: 100;
  pointer-events: none;
}
.toast {
  padding: 12px 16px;
  border-radius: 12px;
  font-size: 13.5px;
  font-weight: 600;
  background: var(--panel);
  border: 1px solid var(--line);
  box-shadow: var(--shadow-lg);
  color: var(--ink);
  animation: toastIn 220ms cubic-bezier(0.0, 0.0, 0.2, 1);
  pointer-events: auto;
  max-width: 360px;
}
.toast--success { border-color: rgba(34, 197, 94, 0.4); }
.toast--error   { border-color: rgba(239, 68, 68, 0.4); color: #f08a7e; }

@keyframes toastIn {
  from { opacity: 0; transform: translateY(6px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* Responsivo */
@media (max-width: 720px) {
  .wrap { padding: 28px 18px 60px; }
  .page-head { flex-direction: column; align-items: flex-start; gap: 14px; }
  .wh-stats { grid-template-columns: 1fr 1fr; }
  .event-grid { grid-template-columns: 1fr; }
  .modal--wide { padding: 18px; }
  .err-col { max-width: 160px; }
}

/* prefers-reduced-motion */
@media (prefers-reduced-motion: reduce) {
  .wh-card,
  .toast,
  .pop-enter-active,
  .pop-leave-active {
    animation: none !important;
    transition: none !important;
  }
}
</style>
