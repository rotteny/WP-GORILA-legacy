<template>
  <div class="screen">
    <Topbar />

    <div class="scroll">
      <div class="wrap">
        <div class="page-head">
          <div>
            <h1>Projetos</h1>
            <p>Cada projeto representa um número WhatsApp conectado.</p>
          </div>
        </div>

        <!-- LOADING (1ª carga) -->
        <div v-if="loading && instances.length === 0" class="state-empty">
          <p>Carregando projetos...</p>
        </div>

        <!-- ESTADO VAZIO -->
        <div v-else-if="instances.length === 0" class="state-empty">
          <div class="state-empty__ill" aria-hidden="true">
            <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z" />
            </svg>
          </div>
          <h2>Nenhum projeto ainda</h2>
          <p>Crie seu primeiro projeto para conectar um número WhatsApp.</p>
          <button class="btn-primary" type="button" @click="openModal">Criar primeiro projeto</button>
        </div>

        <!-- GRID DE CARDS -->
        <div v-else class="grid">
          <article
            v-for="inst in instances"
            :key="inst.id"
            class="pcard"
            tabindex="0"
            @click="openInstance(inst)"
            @keydown.enter="openInstance(inst)"
          >
            <div class="pcard-top">
              <div class="av-lg" :style="avatarStyle(inst.slug)">{{ initials(inst.name) }}</div>
              <div class="kebab-wrap" @click.stop>
                <button
                  class="kebab"
                  type="button"
                  :aria-label="`Ações do projeto ${inst.name}`"
                  @click="toggleMenu(inst.id)"
                >
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                    <circle cx="12" cy="5" r="2" /><circle cx="12" cy="12" r="2" /><circle cx="12" cy="19" r="2" />
                  </svg>
                </button>
                <div v-if="openMenuId === inst.id" class="kebab-popover">
                  <button type="button" class="kebab-item" @click="openWebhooks(inst)">
                    Webhooks
                  </button>
                  <button type="button" class="kebab-item kebab-item--danger" @click="deleteInstance(inst)">
                    Excluir
                  </button>
                </div>
              </div>
            </div>

            <h3>{{ inst.name }}</h3>
            <p class="mail">{{ inst.slug }}@wp-gorila</p>

            <!-- TODO: Chunk 2 já persiste mensagens — pegar contagem via API v1 num próximo trabalho -->
            <div class="stat-row">
              <div class="stat"><b>—</b> conversas</div>
              <div class="stat"><b>—</b> não lidas</div>
            </div>

            <div class="pcard-foot">
              <span class="pill" :class="inst.status === 'CONNECTED' ? 'on' : 'off'">
                <span class="dot"></span>
                {{ inst.status === 'CONNECTED' ? 'Conectado' : 'Offline' }}
              </span>
              <span class="meta">{{ formatRelative(inst.updated_at || inst.last_event_at || inst.created_at) }}</span>
            </div>
          </article>

          <button class="add-card" type="button" @click="openModal">
            <span class="plus">+</span>
            Adicionar projeto
          </button>
        </div>
      </div>
    </div>

    <!-- MODAL DE CRIAÇÃO -->
    <Transition name="pop">
      <div v-if="showModal" class="overlay" @click.self="closeModal">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
          <h2 id="modal-title">Novo projeto</h2>

          <div v-if="formError" class="modal-error">{{ formError }}</div>

          <form @submit.prevent="createInstance">
            <div class="fgroup">
              <label for="inst-name">Nome</label>
              <input
                id="inst-name"
                v-model="form.name"
                type="text"
                placeholder="Atendimento ACCA"
                :disabled="creating"
                autofocus
                @input="onNameInput"
              />
            </div>

            <div class="fgroup">
              <label for="inst-slug">Slug</label>
              <input
                id="inst-slug"
                v-model="form.slug"
                class="mono"
                type="text"
                placeholder="acca"
                maxlength="31"
                :disabled="creating"
                @input="onSlugInput"
              />
            </div>
            <p class="help">Use letras minúsculas, números, hífen ou underscore. 1 a 31 caracteres.</p>
            <!-- TODO: telefone vem em chunk futuro -->

            <div class="modal-actions">
              <button type="button" class="btn-ghost" :disabled="creating" @click="closeModal">Cancelar</button>
              <button type="submit" class="btn-primary" :disabled="creating">
                {{ creating ? 'Criando...' : 'Criar' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script>
import axios from 'axios';
import Topbar from './Topbar.vue';

const POLL_MS = 5000;
const SLUG_REGEX = /^[a-z0-9][a-z0-9_-]{0,30}$/;

export default {
  name: 'InstancesScreen',

  components: { Topbar },

  data() {
    return {
      instances: [],
      loading: false,
      pollHandle: null,
      openMenuId: null,
      showModal: false,
      creating: false,
      formError: '',
      slugEdited: false,
      form: { name: '', slug: '' },
    };
  },

  mounted() {
    this.fetchInstances();
    this.pollHandle = setInterval(this.fetchInstances, POLL_MS);
    document.addEventListener('click', this.handleDocumentClick);
  },

  beforeUnmount() {
    this.stopPolling();
    document.removeEventListener('click', this.handleDocumentClick);
  },

  beforeDestroy() {
    this.stopPolling();
    document.removeEventListener('click', this.handleDocumentClick);
  },

  methods: {
    stopPolling() {
      if (this.pollHandle) {
        clearInterval(this.pollHandle);
        this.pollHandle = null;
      }
    },

    handleDocumentClick() {
      this.openMenuId = null;
    },

    async fetchInstances() {
      this.loading = true;
      try {
        const { data } = await axios.get('/api/whatsapp/instances');
        this.instances = Array.isArray(data) ? data : (data.instances || []);
      } catch (e) {
        console.error('Erro ao carregar projetos:', e);
      } finally {
        this.loading = false;
      }
    },

    openModal() {
      this.showModal = true;
      this.formError = '';
      this.slugEdited = false;
      this.form.name = '';
      this.form.slug = '';
    },

    closeModal() {
      if (this.creating) return;
      this.showModal = false;
      this.formError = '';
    },

    onNameInput() {
      if (!this.slugEdited) {
        this.form.slug = this.slugFromName(this.form.name);
      }
    },

    onSlugInput() {
      this.slugEdited = true;
    },

    slugFromName(name) {
      if (!name) return '';
      const noAccents = name.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
      let slug = noAccents
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-+|-+$/g, '');
      slug = slug.replace(/^[^a-z0-9]+/, '');
      return slug.slice(0, 31);
    },

    validateForm() {
      const name = (this.form.name || '').trim();
      const slug = (this.form.slug || '').trim();
      if (!name) return 'Informe um nome para o projeto.';
      if (!slug) return 'O slug não pode ficar vazio.';
      if (!SLUG_REGEX.test(slug)) {
        return 'Slug inválido. Use letras minúsculas, números, hífen ou underscore (1 a 31 caracteres, começando com letra ou número).';
      }
      return '';
    },

    async createInstance() {
      this.formError = '';
      const err = this.validateForm();
      if (err) {
        this.formError = err;
        return;
      }

      this.creating = true;
      try {
        await axios.post('/api/whatsapp/instances', {
          name: this.form.name.trim(),
          slug: this.form.slug.trim(),
        });
        this.showModal = false;
        await this.fetchInstances();
      } catch (e) {
        const apiMsg =
          e?.response?.data?.error ||
          e?.response?.data?.message ||
          (e?.response?.data?.errors
            ? Object.values(e.response.data.errors).flat().join(' ')
            : null);
        this.formError = apiMsg || 'Não foi possível criar o projeto agora. Tente novamente.';
      } finally {
        this.creating = false;
      }
    },

    toggleMenu(id) {
      this.openMenuId = this.openMenuId === id ? null : id;
    },

    async deleteInstance(instance) {
      this.openMenuId = null;
      const ok = window.confirm(
        `Excluir o projeto '${instance.name}'? Esta ação não pode ser desfeita.`,
      );
      if (!ok) return;

      try {
        await axios.delete('/api/whatsapp/instances/' + encodeURIComponent(instance.slug));
        await this.fetchInstances();
      } catch (e) {
        const apiMsg = e?.response?.data?.error || e?.response?.data?.message;
        alert('Falha ao excluir: ' + (apiMsg || e.message));
      }
    },

    openInstance(instance) {
      if (instance.status === 'CONNECTED') {
        window.location.href = `/p/${encodeURIComponent(instance.slug)}/chat`;
      } else {
        window.location.href = `/p/${encodeURIComponent(instance.slug)}/qr`;
      }
    },

    openWebhooks(instance) {
      this.openMenuId = null;
      window.location.href = `/p/${encodeURIComponent(instance.slug)}/webhooks`;
    },

    initials(name) {
      if (!name) return '?';
      const parts = name.trim().split(/\s+/);
      if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
      return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    },

    avatarStyle(slug) {
      let h = 0;
      const s = slug || '';
      for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
      const hue = h % 360;
      return { background: `linear-gradient(150deg, hsl(${hue}, 55%, 50%), hsl(${hue}, 55%, 38%))` };
    },

    formatRelative(iso) {
      if (!iso) return '';
      const then = new Date(iso).getTime();
      if (Number.isNaN(then)) return '';
      const diffSec = Math.max(0, Math.floor((Date.now() - then) / 1000));

      if (diffSec < 30) return 'atualizado agora';
      if (diffSec < 60) return `atualizado há ${diffSec}s`;

      const diffMin = Math.floor(diffSec / 60);
      if (diffMin < 60) return `atualizado há ${diffMin} min`;

      const diffH = Math.floor(diffMin / 60);
      if (diffH < 24) return `atualizado há ${diffH}h`;

      const diffD = Math.floor(diffH / 24);
      if (diffD === 1) return 'atualizado há 1 dia';
      if (diffD < 30) return `atualizado há ${diffD} dias`;

      const diffMo = Math.floor(diffD / 30);
      if (diffMo < 12) return `atualizado há ${diffMo} ${diffMo === 1 ? 'mês' : 'meses'}`;

      const diffY = Math.floor(diffMo / 12);
      return `atualizado há ${diffY} ${diffY === 1 ? 'ano' : 'anos'}`;
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
  padding: 48px 36px 80px;
}

.page-head {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 24px;
  margin-bottom: 34px;
}

.page-head h1 {
  font-size: 34px;
  font-weight: 800;
  margin: 0 0 6px;
  letter-spacing: -0.03em;
}

.page-head p {
  margin: 0;
  color: var(--muted);
  font-size: 15px;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
  gap: 20px;
}

.pcard {
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: var(--radius);
  padding: 22px;
  box-shadow: var(--shadow);
  transition: transform 0.14s, box-shadow 0.14s, border-color 0.14s;
  cursor: pointer;
  position: relative;
  outline: none;
}

.pcard:hover {
  transform: translateY(-3px);
  box-shadow: var(--shadow-lg);
  border-color: #4a4a4a;
}

.pcard:focus-visible {
  box-shadow: 0 0 0 3px var(--brand-soft);
}

.pcard-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 16px;
}

.av-lg {
  width: 54px;
  height: 54px;
  border-radius: 16px;
  display: grid;
  place-items: center;
  color: #fff;
  font-weight: 800;
  font-size: 19px;
  letter-spacing: -0.02em;
  box-shadow: 0 6px 16px rgba(16, 40, 32, 0.18);
}

.kebab-wrap {
  position: relative;
}

.kebab {
  width: 32px;
  height: 32px;
  border-radius: 9px;
  display: grid;
  place-items: center;
  color: var(--muted);
  transition: background 0.12s, color 0.12s;
}

.kebab:hover {
  background: var(--hover);
  color: var(--ink-2);
}

.kebab-popover {
  position: absolute;
  top: 36px;
  right: 0;
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 12px;
  box-shadow: var(--shadow-lg);
  min-width: 140px;
  z-index: 10;
  overflow: hidden;
}

.kebab-item {
  display: block;
  width: 100%;
  padding: 10px 14px;
  font-size: 13px;
  font-weight: 600;
  text-align: left;
  color: var(--ink);
  transition: background 0.12s;
}

.kebab-item:hover {
  background: var(--hover);
}

.kebab-item--danger {
  color: #f08a7e;
}

.pcard h3 {
  margin: 0 0 3px;
  font-size: 18px;
  font-weight: 700;
  letter-spacing: -0.01em;
}

.mail {
  margin: 0;
  color: var(--muted);
  font-size: 13px;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}

.stat-row {
  display: flex;
  gap: 18px;
  margin-top: 14px;
}

.stat {
  font-size: 12.5px;
  color: var(--muted);
}

.stat b {
  color: var(--ink);
  font-weight: 700;
  font-size: 15px;
  display: block;
  letter-spacing: -0.01em;
}

.pcard-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 20px;
  padding-top: 16px;
  border-top: 1px solid var(--line);
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

.meta {
  font-size: 12px;
  color: var(--muted);
}

.add-card {
  border: 1.5px dashed #444;
  background: transparent;
  border-radius: var(--radius);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  color: var(--muted);
  font-weight: 600;
  min-height: 200px;
  transition: border-color 0.14s, color 0.14s, background 0.14s;
  font-family: inherit;
  font-size: 14px;
}

.add-card:hover {
  border-color: var(--brand);
  color: var(--brand);
  background: rgba(139, 92, 246, 0.06);
}

.add-card .plus {
  width: 46px;
  height: 46px;
  border-radius: 14px;
  background: var(--brand-soft);
  color: #b794f6;
  display: grid;
  place-items: center;
  font-size: 26px;
  font-weight: 400;
}

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
  border: none;
  cursor: pointer;
  font-family: inherit;
}

.btn-primary:hover:not(:disabled) {
  background: var(--brand-deep);
  transform: translateY(-1px);
  box-shadow: 0 10px 24px rgba(139, 92, 246, 0.45);
}

.btn-primary:active:not(:disabled) {
  transform: translateY(0);
}

.btn-primary:disabled {
  background: #3a3a3a;
  color: var(--muted);
  box-shadow: none;
  cursor: default;
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

.overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  backdrop-filter: blur(4px);
  display: grid;
  place-items: center;
  z-index: 50;
  padding: 24px;
}

.modal {
  width: 100%;
  max-width: 460px;
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 22px;
  box-shadow: var(--shadow-lg);
  padding: 28px;
}

.modal h2 {
  margin: 0 0 22px;
  font-size: 22px;
  font-weight: 800;
  letter-spacing: -0.02em;
}

.modal-error {
  background: rgba(240, 90, 75, 0.16);
  color: #f08a7e;
  padding: 10px 12px;
  border-radius: 10px;
  font-size: 13px;
  margin-bottom: 16px;
  border: 1px solid rgba(240, 90, 75, 0.3);
}

.fgroup {
  margin-bottom: 18px;
}

.fgroup label {
  display: block;
  font-size: 13px;
  font-weight: 700;
  color: var(--ink-2);
  margin-bottom: 8px;
}

.fgroup input {
  width: 100%;
  border: 1px solid var(--line);
  background: #242424;
  border-radius: 13px;
  padding: 13px 15px;
  font-size: 14.5px;
  font-family: inherit;
  color: var(--ink);
  transition: border-color 0.12s, background 0.12s;
  box-sizing: border-box;
}

.fgroup input.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}

.fgroup input::placeholder {
  color: var(--muted);
}

.fgroup input:focus {
  outline: none;
  border-color: var(--brand);
  background: #2b2b2b;
  box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.14);
}

.fgroup input:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.help {
  font-size: 12.5px;
  color: var(--muted);
  margin: -6px 0 0;
  line-height: 1.5;
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 26px;
}

.pop-enter-active {
  transition: opacity 0.18s, transform 0.18s cubic-bezier(0.2, 0.8, 0.3, 1);
}

.pop-leave-active {
  transition: opacity 0.14s, transform 0.14s;
}

.pop-enter-from,
.pop-leave-to {
  opacity: 0;
}

.pop-enter-from .modal,
.pop-leave-to .modal {
  transform: translateY(10px) scale(0.97);
}
</style>
