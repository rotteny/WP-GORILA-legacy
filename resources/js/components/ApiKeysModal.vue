<template>
  <div v-if="show" class="ak-modal" @click.self="$emit('close')">
    <div class="ak-card" role="dialog" aria-modal="true" aria-labelledby="ak-title">

      <!-- HEADER -->
      <header class="ak-header">
        <div>
          <h2 id="ak-title" class="ak-title">Chaves de API</h2>
          <p v-if="projectMode" class="ak-subtitle">
            Chaves do projeto <strong>{{ project.name }}</strong>. Quem usar a chave envia
            pelo telefone <strong>ativo</strong> do projeto — o failover é transparente.
          </p>
          <p v-else class="ak-subtitle">
            Use estas chaves em integrações externas. Cada chave fica amarrada a uma instância.
          </p>
        </div>
        <button
          class="ak-btn ak-btn--primary"
          type="button"
          @click="openCreate"
          :disabled="creating || revealedKey"
        >+ Criar chave de API</button>
      </header>

      <!-- ESTADO: lista de chaves -->
      <template v-if="!showCreateForm && !revealedKey">
        <div v-if="loading" class="ak-loading">Carregando...</div>

        <div v-else-if="keys.length === 0" class="ak-empty">
          Nenhuma chave criada ainda. Clique em <strong>“+ Criar chave de API”</strong> pra começar.
        </div>

        <table v-else class="ak-table">
          <thead>
            <tr>
              <th>Chave</th>
              <th>{{ projectMode ? 'Escopo' : 'Instância' }}</th>
              <th>Criada em</th>
              <th>Último uso</th>
              <th>Status</th>
              <th aria-label="Ações"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="k in keys" :key="k.id" :class="{ 'ak-row--revoked': k.revoked_at }">
              <td><code>{{ k.key_prefix }}…</code><div class="ak-name">{{ k.name }}</div></td>
              <td>{{ projectMode ? ('projeto: ' + project.slug) : k.instance_slug }}</td>
              <td>{{ formatDate(k.created_at) }}</td>
              <td>{{ k.last_used_at ? formatDate(k.last_used_at) : '—' }}</td>
              <td>
                <span class="ak-pill" :class="k.revoked_at ? 'ak-pill--off' : 'ak-pill--on'">
                  {{ k.revoked_at ? 'Revogada' : 'Ativa' }}
                </span>
              </td>
              <td class="ak-actions">
                <button
                  v-if="!k.revoked_at"
                  class="ak-btn ak-btn--danger-sm"
                  @click="revokeKey(k)"
                  :disabled="revoking === k.id"
                >{{ revoking === k.id ? '...' : 'Revogar' }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </template>

      <!-- ESTADO: form de criação -->
      <template v-if="showCreateForm">
        <div class="ak-form">
          <h3 class="ak-form__title">Criar nova chave de API</h3>
          <p v-if="createError" class="ak-error">{{ createError }}</p>

          <label v-if="!projectMode" class="ak-field">
            <span>Instância</span>
            <select v-model="form.instance_slug" :disabled="creating">
              <option value="" disabled>Selecione…</option>
              <option v-for="i in instances" :key="i.slug" :value="i.slug">
                {{ i.name }} ({{ i.slug }})
              </option>
            </select>
          </label>

          <label class="ak-field">
            <span>Nome / descrição</span>
            <input
              type="text"
              v-model="form.name"
              :disabled="creating"
              placeholder="ex.: acca-homolog, n8n-marketing"
              maxlength="100"
            />
          </label>

          <div class="ak-form__actions">
            <button class="ak-btn ak-btn--ghost" @click="cancelCreate" :disabled="creating">
              Cancelar
            </button>
            <button class="ak-btn ak-btn--primary" @click="submitCreate" :disabled="creating || !canSubmit">
              {{ creating ? 'Criando...' : 'Criar chave' }}
            </button>
          </div>
        </div>
      </template>

      <!-- ESTADO: chave recém-criada (plaintext one-time) -->
      <template v-if="revealedKey">
        <div class="ak-reveal">
          <h3 class="ak-reveal__title">⚠️ Copie a chave agora</h3>
          <p class="ak-reveal__hint">
            Esta é a única vez que a chave aparece em texto puro.
            Depois disso só o prefixo fica visível.
          </p>
          <div class="ak-reveal__key">
            <code>{{ revealedKey.plaintext }}</code>
            <button class="ak-btn ak-btn--ghost" @click="copyKey">
              {{ copied ? 'Copiado ✓' : 'Copiar' }}
            </button>
          </div>
          <details class="ak-reveal__example">
            <summary>Como usar essa chave em outro sistema</summary>
            <pre v-if="projectMode">curl -X POST {{ baseUrl }}/api/v1/whatsapp/projects/{{ project.slug }}/send-message \
  -H "Authorization: Bearer {{ revealedKey.plaintext }}" \
  -H "Content-Type: application/json" \
  -d '{"number":"5511999999999","message":"olá"}'</pre>
            <pre v-else>curl -X POST {{ baseUrl }}/api/v1/whatsapp/instances/{{ revealedKey.instance_slug }}/send-message \
  -H "Authorization: Bearer {{ revealedKey.plaintext }}" \
  -H "Content-Type: application/json" \
  -d '{"number":"5511999999999","message":"olá"}'</pre>
          </details>
          <div class="ak-form__actions">
            <button class="ak-btn ak-btn--primary" @click="dismissReveal">Já copiei, fechar</button>
          </div>
        </div>
      </template>

      <!-- FOOTER -->
      <footer class="ak-footer" v-if="!revealedKey">
        <button class="ak-btn ak-btn--ghost" @click="$emit('close')">Fechar</button>
      </footer>
    </div>
  </div>
</template>

<script>
import axios from 'axios';

export default {
  name: 'ApiKeysModal',

  props: {
    show: { type: Boolean, required: true },
    instances: { type: Array, default: () => [] },
    // Quando presente, o modal opera em "modo projeto": chaves escopadas ao projeto.
    project: { type: Object, default: null },
  },

  emits: ['close'],

  data() {
    return {
      keys: [],
      loading: false,
      creating: false,
      revoking: null,
      showCreateForm: false,
      revealedKey: null,
      copied: false,
      createError: '',
      form: { instance_slug: '', name: '' },
      baseUrl: window.location.origin,
    };
  },

  computed: {
    projectMode() {
      return !!this.project;
    },
    canSubmit() {
      const hasScope = this.projectMode ? true : !!this.form.instance_slug;
      return hasScope && this.form.name.trim().length > 0;
    },
  },

  watch: {
    show(val) {
      if (val) this.fetchKeys();
    },
  },

  methods: {
    async fetchKeys() {
      this.loading = true;
      try {
        const { data } = await axios.get('/api/whatsapp/api-keys');
        // Em modo projeto, mostra só as chaves daquele projeto.
        this.keys = this.projectMode
          ? data.filter((k) => k.project_id === this.project.id)
          : data;
      } catch (e) {
        console.error('Erro ao carregar chaves:', e);
      } finally {
        this.loading = false;
      }
    },

    openCreate() {
      this.form = { instance_slug: '', name: '' };
      this.createError = '';
      this.showCreateForm = true;
    },

    cancelCreate() {
      this.showCreateForm = false;
    },

    async submitCreate() {
      if (!this.canSubmit) return;
      this.creating = true;
      this.createError = '';
      try {
        const payload = this.projectMode
          ? { project_id: this.project.id, name: this.form.name.trim() }
          : { instance_slug: this.form.instance_slug, name: this.form.name.trim() };
        const { data } = await axios.post('/api/whatsapp/api-keys', payload);
        this.revealedKey = data;
        this.showCreateForm = false;
      } catch (e) {
        this.createError = e?.response?.data?.error
          || e?.response?.data?.message
          || 'Falha ao criar a chave. Tente novamente.';
      } finally {
        this.creating = false;
      }
    },

    async copyKey() {
      if (!this.revealedKey?.plaintext) return;
      try {
        await navigator.clipboard.writeText(this.revealedKey.plaintext);
        this.copied = true;
        setTimeout(() => (this.copied = false), 2000);
      } catch (_e) {
        // Fallback: seleciona texto
      }
    },

    dismissReveal() {
      this.revealedKey = null;
      this.copied = false;
      this.fetchKeys();
    },

    async revokeKey(k) {
      if (!confirm(`Revogar a chave "${k.name}" (${k.key_prefix}…)? Esta ação não pode ser desfeita.`)) return;
      this.revoking = k.id;
      try {
        await axios.delete(`/api/whatsapp/api-keys/${k.id}`);
        await this.fetchKeys();
      } catch (e) {
        alert('Falha ao revogar: ' + (e?.response?.data?.message || e.message));
      } finally {
        this.revoking = null;
      }
    },

    formatDate(iso) {
      if (!iso) return '—';
      try {
        return new Date(iso).toLocaleString('pt-BR', {
          day: '2-digit', month: '2-digit', year: 'numeric',
          hour: '2-digit', minute: '2-digit',
        });
      } catch (_e) { return iso; }
    },
  },
};
</script>

<style scoped>
.ak-modal {
  position: fixed; inset: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex; align-items: center; justify-content: center;
  z-index: 1000; padding: 1rem;
}
.ak-card {
  background: var(--panel); border: 1px solid var(--line); border-radius: 22px;
  width: 100%; max-width: 880px;
  max-height: 90vh; overflow-y: auto;
  padding: 1.75rem;
  box-shadow: var(--shadow-lg);
  font-family: var(--font, 'Plus Jakarta Sans'), system-ui, -apple-system, sans-serif;
  color: var(--ink);
}

.ak-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1.5rem; }
.ak-title { margin: 0 0 .25rem; font-size: 1.5rem; font-weight: 800; letter-spacing: -0.02em; color: var(--ink); }
.ak-subtitle { margin: 0; font-size: .875rem; color: var(--muted); max-width: 480px; }

.ak-loading, .ak-empty { padding: 2rem; text-align: center; color: var(--muted); }

.ak-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
.ak-table th { text-align: left; padding: .75rem .5rem; border-bottom: 1px solid var(--line); color: var(--muted); font-weight: 600; font-size: .8rem; }
.ak-table td { padding: .75rem .5rem; border-bottom: 1px solid var(--line); color: var(--ink); }
.ak-table code { background: var(--hover); padding: .15rem .4rem; border-radius: 4px; font-size: .85rem; color: var(--ink-2); }
.ak-name { font-size: .8rem; color: var(--muted); margin-top: .15rem; }
.ak-actions { text-align: right; }
.ak-row--revoked td { color: var(--muted); }
.ak-row--revoked code { background: rgba(248,113,113,0.16); color: #f08a7e; }

.ak-pill { display: inline-block; padding: .15rem .55rem; border-radius: 999px; font-size: .75rem; font-weight: 700; }
.ak-pill--on  { background: var(--brand-soft); color: #b794f6; }
.ak-pill--off { background: rgba(248,113,113,0.16); color: #f08a7e; }

.ak-btn { padding: .55rem 1.1rem; border: none; border-radius: 11px; font-weight: 700; cursor: pointer; font-size: .875rem; font-family: inherit; }
.ak-btn:disabled { opacity: .5; cursor: not-allowed; }
.ak-btn--primary { background: var(--brand); color: #fff; }
.ak-btn--primary:hover:not(:disabled) { background: var(--brand-deep); }
.ak-btn--ghost { background: var(--hover); color: var(--ink-2); border: 1px solid var(--line); }
.ak-btn--ghost:hover:not(:disabled) { background: rgba(255,255,255,0.1); color: var(--ink); }
.ak-btn--danger-sm { background: rgba(248,113,113,0.16); color: #f08a7e; padding: .3rem .6rem; font-size: .75rem; }
.ak-btn--danger-sm:hover:not(:disabled) { background: rgba(248,113,113,0.28); }

.ak-form { padding: 1rem 0; }
.ak-form__title { margin: 0 0 1rem; font-size: 1.1rem; color: var(--ink); }
.ak-error { background: rgba(248,113,113,0.16); color: #f08a7e; padding: .5rem .75rem; border-radius: 8px; margin-bottom: 1rem; font-size: .875rem; }
.ak-field { display: block; margin-bottom: 1rem; }
.ak-field span { display: block; margin-bottom: .35rem; font-size: .875rem; color: var(--ink-2); font-weight: 700; }
.ak-field input, .ak-field select {
  width: 100%; padding: .65rem .8rem; border: 1px solid var(--line); border-radius: 11px; font-size: .9rem; background: #242424; color: var(--ink); font-family: inherit;
}
.ak-field input::placeholder { color: var(--muted); }
.ak-field input:focus, .ak-field select:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 4px var(--brand-soft); background: #2b2b2b; }
.ak-form__actions { display: flex; justify-content: flex-end; gap: .5rem; margin-top: 1rem; }

.ak-reveal { padding: 1rem 0; }
.ak-reveal__title { margin: 0 0 .5rem; color: #e8c96e; font-size: 1.1rem; }
.ak-reveal__hint  { margin: 0 0 1rem; color: var(--muted); font-size: .875rem; }
.ak-reveal__key {
  display: flex; gap: .5rem; align-items: center;
  background: #242424; padding: .75rem; border: 1px dashed var(--line); border-radius: 10px;
  word-break: break-all; margin-bottom: 1rem;
}
.ak-reveal__key code { flex: 1; font-size: .875rem; color: var(--ink); }
.ak-reveal__example { margin: 1rem 0; }
.ak-reveal__example summary { cursor: pointer; color: var(--muted); font-size: .875rem; padding: .25rem 0; }
.ak-reveal__example pre {
  background: #161616; color: var(--ink-2); padding: 1rem; border-radius: 10px; border: 1px solid var(--line);
  font-size: .8rem; overflow-x: auto; margin-top: .5rem;
}

.ak-footer { display: flex; justify-content: flex-end; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--line); }
</style>
