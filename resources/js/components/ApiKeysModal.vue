<template>
  <div v-if="show" class="ak-modal" @click.self="$emit('close')">
    <div class="ak-card" role="dialog" aria-modal="true" aria-labelledby="ak-title">

      <!-- HEADER -->
      <header class="ak-header">
        <div>
          <h2 id="ak-title" class="ak-title">Chaves de API</h2>
          <p class="ak-subtitle">
            Use estas chaves em integrações externas (acca, n8n, etc).
            Cada chave fica amarrada a uma instância.
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
              <th>Instância</th>
              <th>Criada em</th>
              <th>Último uso</th>
              <th>Status</th>
              <th aria-label="Ações"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="k in keys" :key="k.id" :class="{ 'ak-row--revoked': k.revoked_at }">
              <td><code>{{ k.key_prefix }}…</code><div class="ak-name">{{ k.name }}</div></td>
              <td>{{ k.instance_slug }}</td>
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

          <label class="ak-field">
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
            <pre>curl -X POST {{ baseUrl }}/api/v1/whatsapp/instances/{{ revealedKey.instance_slug }}/send-message \
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
    canSubmit() {
      return this.form.instance_slug && this.form.name.trim().length > 0;
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
        this.keys = data;
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
        const { data } = await axios.post('/api/whatsapp/api-keys', this.form);
        this.revealedKey = data;
        this.showCreateForm = false;
      } catch (e) {
        this.createError = e?.response?.data?.message
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
  background: #fff; border-radius: 12px;
  width: 100%; max-width: 880px;
  max-height: 90vh; overflow-y: auto;
  padding: 1.5rem;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
  font-family: system-ui, -apple-system, sans-serif;
}

.ak-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1.5rem; }
.ak-title { margin: 0 0 .25rem; font-size: 1.5rem; color: #111827; }
.ak-subtitle { margin: 0; font-size: .875rem; color: #6b7280; max-width: 480px; }

.ak-loading, .ak-empty { padding: 2rem; text-align: center; color: #6b7280; }

.ak-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
.ak-table th { text-align: left; padding: .75rem .5rem; border-bottom: 1px solid #e5e7eb; color: #6b7280; font-weight: 500; font-size: .8rem; }
.ak-table td { padding: .75rem .5rem; border-bottom: 1px solid #f3f4f6; }
.ak-table code { background: #f3f4f6; padding: .15rem .4rem; border-radius: 4px; font-size: .85rem; }
.ak-name { font-size: .8rem; color: #6b7280; margin-top: .15rem; }
.ak-actions { text-align: right; }
.ak-row--revoked td { color: #9ca3af; }
.ak-row--revoked code { background: #fee2e2; color: #991b1b; }

.ak-pill { display: inline-block; padding: .15rem .55rem; border-radius: 999px; font-size: .75rem; font-weight: 500; }
.ak-pill--on  { background: #d1fae5; color: #065f46; }
.ak-pill--off { background: #fee2e2; color: #991b1b; }

.ak-btn { padding: .5rem 1rem; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; font-size: .875rem; }
.ak-btn:disabled { opacity: .5; cursor: not-allowed; }
.ak-btn--primary { background: #008069; color: #fff; }
.ak-btn--primary:hover:not(:disabled) { background: #006e57; }
.ak-btn--ghost { background: #f3f4f6; color: #374151; }
.ak-btn--ghost:hover:not(:disabled) { background: #e5e7eb; }
.ak-btn--danger-sm { background: #fee2e2; color: #991b1b; padding: .25rem .5rem; font-size: .75rem; }
.ak-btn--danger-sm:hover:not(:disabled) { background: #fecaca; }

.ak-form { padding: 1rem 0; }
.ak-form__title { margin: 0 0 1rem; font-size: 1.1rem; }
.ak-error { background: #fee2e2; color: #991b1b; padding: .5rem .75rem; border-radius: 6px; margin-bottom: 1rem; font-size: .875rem; }
.ak-field { display: block; margin-bottom: 1rem; }
.ak-field span { display: block; margin-bottom: .35rem; font-size: .875rem; color: #374151; font-weight: 500; }
.ak-field input, .ak-field select {
  width: 100%; padding: .5rem .75rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: .9rem; background: #fff;
}
.ak-field input:focus, .ak-field select:focus { outline: 2px solid #008069; outline-offset: -1px; }
.ak-form__actions { display: flex; justify-content: flex-end; gap: .5rem; margin-top: 1rem; }

.ak-reveal { padding: 1rem 0; }
.ak-reveal__title { margin: 0 0 .5rem; color: #92400e; font-size: 1.1rem; }
.ak-reveal__hint  { margin: 0 0 1rem; color: #6b7280; font-size: .875rem; }
.ak-reveal__key {
  display: flex; gap: .5rem; align-items: center;
  background: #f9fafb; padding: .75rem; border: 1px dashed #d1d5db; border-radius: 6px;
  word-break: break-all; margin-bottom: 1rem;
}
.ak-reveal__key code { flex: 1; font-size: .875rem; }
.ak-reveal__example { margin: 1rem 0; }
.ak-reveal__example summary { cursor: pointer; color: #6b7280; font-size: .875rem; padding: .25rem 0; }
.ak-reveal__example pre {
  background: #1f2937; color: #d1d5db; padding: 1rem; border-radius: 6px;
  font-size: .8rem; overflow-x: auto; margin-top: .5rem;
}

.ak-footer { display: flex; justify-content: flex-end; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #e5e7eb; }
</style>
