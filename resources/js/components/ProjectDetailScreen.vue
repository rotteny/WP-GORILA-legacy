<template>
  <div class="pd-wrap">
    <div class="pd-container">
      <header class="pd-header">
        <div class="pd-header__text">
          <a href="/" class="pd-back">← Projetos</a>
          <h1 class="pd-title">{{ project ? project.name : 'Carregando…' }}</h1>
          <p v-if="project" class="pd-subtitle">
            <span class="pd-slug">{{ project.slug }}</span>
            · telefones em ordem de failover (menor prioridade tenta primeiro)
          </p>
        </div>
        <div class="pd-header__actions">
          <button v-if="project" class="pd-btn pd-btn--ghost" @click="openSettings">✉️ Responsável</button>
          <button v-if="project" class="pd-btn pd-btn--ghost" @click="showApiKeys = true">🔑 Chaves de API</button>
          <button v-if="project" class="pd-btn pd-btn--primary" @click="openAddModal">+ Novo telefone</button>
        </div>
      </header>

      <div v-if="project && !project.responsible_email" class="pd-resp pd-resp--warn">
        ⚠️ Nenhum responsável definido — ninguém será avisado se um telefone cair.
        <button class="pd-link" @click="openSettings">Definir email</button>
      </div>

      <ApiKeysModal
        v-if="project"
        :show="showApiKeys"
        :project="project"
        @close="showApiKeys = false"
      />

      <div v-if="notFound" class="pd-empty">
        <h2 class="pd-empty__title">Projeto não encontrado</h2>
        <a href="/" class="pd-btn pd-btn--primary">Voltar para projetos</a>
      </div>

      <template v-else-if="project">
        <div v-if="phones.length === 0" class="pd-empty">
          <h2 class="pd-empty__title">Nenhum telefone neste projeto</h2>
          <p class="pd-empty__hint">Adicione o primeiro telefone e leia o QR code para conectar.</p>
          <button class="pd-btn pd-btn--primary" @click="openAddModal">+ Novo telefone</button>
        </div>

        <ul v-else class="pd-list">
          <li
            v-for="phone in phones"
            :key="phone.id"
            class="pd-phone"
            :class="{ 'pd-phone--active': project.active_instance_id === phone.id }"
          >
            <span class="pd-phone__prio">#{{ phone.priority }}</span>
            <div class="pd-phone__info">
              <div class="pd-phone__name">
                {{ phone.name }}
                <span v-if="project.active_instance_id === phone.id" class="pd-tag-active">ATIVO</span>
              </div>
              <div class="pd-phone__slug">{{ phone.slug }}</div>
            </div>
            <span class="pd-status" :class="statusClass(phone.status)">{{ statusLabel(phone.status) }}</span>
            <div class="pd-phone__actions">
              <a
                v-if="phone.status !== 'CONNECTED'"
                :href="`/p/${encodeURIComponent(phone.slug)}/qr${backSuffix}`"
                class="pd-btn pd-btn--xs pd-btn--primary"
              >Ler QR</a>
              <a
                v-else
                :href="`/p/${encodeURIComponent(phone.slug)}/chat${backSuffix}`"
                class="pd-btn pd-btn--xs pd-btn--ghost"
              >Abrir chat</a>
              <button
                v-if="project.active_instance_id !== phone.id"
                class="pd-btn pd-btn--xs pd-btn--ghost"
                :disabled="busy"
                @click="promote(phone)"
              >Tornar ativo</button>
              <button class="pd-btn pd-btn--xs pd-btn--danger" :disabled="busy" @click="removePhone(phone)">Remover</button>
            </div>
          </li>
        </ul>
      </template>
    </div>

    <!-- Modal: responsável (email de aviso) -->
    <div v-if="showSettings" class="pd-modal" @click.self="closeSettings">
      <div class="pd-modal__card">
        <h2 class="pd-modal__title">Responsável pelo grupo</h2>
        <p class="pd-modal__hint">Email que recebe aviso quando um telefone do grupo cai ou é bloqueado.</p>
        <div v-if="settingsError" class="pd-modal__error">{{ settingsError }}</div>
        <form @submit.prevent="saveSettings">
          <label class="pd-field">
            <span class="pd-field__label">Email do responsável</span>
            <input v-model="settingsForm.responsible_email" type="email" placeholder="responsavel@empresa.com" :disabled="savingSettings" />
            <span class="pd-field__hint">Deixe em branco para não enviar avisos por email.</span>
          </label>
          <div class="pd-modal__actions">
            <button type="button" class="pd-btn pd-btn--ghost" :disabled="savingSettings" @click="closeSettings">Cancelar</button>
            <button type="submit" class="pd-btn pd-btn--primary" :disabled="savingSettings">{{ savingSettings ? 'Salvando…' : 'Salvar' }}</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Modal: novo telefone -->
    <div v-if="showAddModal" class="pd-modal" @click.self="closeAddModal">
      <div class="pd-modal__card">
        <h2 class="pd-modal__title">Novo telefone</h2>
        <p class="pd-modal__hint">Cria a sessão. Depois clique em “Ler QR” pra conectar o número.</p>
        <div v-if="addError" class="pd-modal__error">{{ addError }}</div>
        <form @submit.prevent="addPhone">
          <label class="pd-field">
            <span class="pd-field__label">Nome</span>
            <input v-model="addForm.name" type="text" placeholder="Tik 1" :disabled="adding" @input="onNameInput" />
          </label>
          <label class="pd-field">
            <span class="pd-field__label">Slug (identificador único)</span>
            <input v-model="addForm.slug" type="text" placeholder="tik1" :disabled="adding" @input="onSlugInput" />
            <span class="pd-field__hint">Minúsculas, números, hífen/underscore (1–31 caracteres). Único no sistema.</span>
          </label>
          <label class="pd-field">
            <span class="pd-field__label">Prioridade (ordem de failover)</span>
            <input v-model.number="addForm.priority" type="number" min="0" :disabled="adding" />
            <span class="pd-field__hint">Menor número tenta primeiro (1 = principal, 2 = backup…).</span>
          </label>
          <div class="pd-modal__actions">
            <button type="button" class="pd-btn pd-btn--ghost" :disabled="adding" @click="closeAddModal">Cancelar</button>
            <button type="submit" class="pd-btn pd-btn--primary" :disabled="adding">{{ adding ? 'Criando…' : 'Criar telefone' }}</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script>
import axios from 'axios';
import ApiKeysModal from './ApiKeysModal.vue';

const SLUG_REGEX = /^[a-z0-9][a-z0-9_-]{0,30}$/;
const POLL_MS = 4000;

export default {
  name: 'ProjectDetailScreen',
  components: { ApiKeysModal },
  props: {
    projectSlug: { type: String, required: true },
  },

  data() {
    return {
      project: null,
      notFound: false,
      loading: false,
      busy: false,
      pollHandle: null,
      showApiKeys: false,

      showAddModal: false,
      adding: false,
      addError: '',
      slugEdited: false,
      addForm: { name: '', slug: '', priority: 1 },

      showSettings: false,
      savingSettings: false,
      settingsError: '',
      settingsForm: { responsible_email: '' },
    };
  },

  computed: {
    phones() {
      return this.project?.instances || [];
    },
    // Repassado às telas de QR/chat pra que o "voltar" retorne a este projeto.
    backSuffix() {
      return `?back=${encodeURIComponent('/projetos/' + this.projectSlug)}`;
    },
  },

  mounted() {
    this.fetchProject();
    this.pollHandle = setInterval(this.fetchProject, POLL_MS);
  },
  beforeUnmount() {
    clearInterval(this.pollHandle);
  },

  methods: {
    async fetchProject() {
      this.loading = true;
      try {
        const { data } = await axios.get(`/api/whatsapp/projects/${encodeURIComponent(this.projectSlug)}`);
        this.project = data;
        this.notFound = false;
      } catch (e) {
        if (e?.response?.status === 404) this.notFound = true;
        else console.error('Erro ao carregar projeto:', e);
      } finally {
        this.loading = false;
      }
    },

    openSettings() {
      this.settingsError = '';
      this.settingsForm = { responsible_email: this.project?.responsible_email || '' };
      this.showSettings = true;
    },
    closeSettings() {
      if (this.savingSettings) return;
      this.showSettings = false;
    },
    async saveSettings() {
      this.settingsError = '';
      this.savingSettings = true;
      try {
        await axios.patch(`/api/whatsapp/projects/${encodeURIComponent(this.projectSlug)}`, {
          responsible_email: this.settingsForm.responsible_email?.trim() || null,
        });
        this.showSettings = false;
        await this.fetchProject();
      } catch (e) {
        this.settingsError = this.errMsg(e, 'Falha ao salvar o responsável.');
      } finally {
        this.savingSettings = false;
      }
    },

    openAddModal() {
      this.showAddModal = true;
      this.addError = '';
      this.slugEdited = false;
      const nextPriority = (this.phones.length || 0) + 1;
      this.addForm = { name: '', slug: '', priority: nextPriority };
    },
    closeAddModal() {
      if (this.adding) return;
      this.showAddModal = false;
    },
    onNameInput() {
      if (!this.slugEdited) this.addForm.slug = this.slugFromName(this.addForm.name);
    },
    onSlugInput() {
      this.slugEdited = true;
    },
    slugFromName(name) {
      if (!name) return '';
      return name.normalize('NFD').replace(/[̀-ͯ]/g, '')
        .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/-+/g, '-').replace(/^-+|-+$/g, '').slice(0, 31);
    },
    validateAdd() {
      const name = (this.addForm.name || '').trim();
      const slug = (this.addForm.slug || '').trim();
      if (!name) return 'Informe um nome.';
      if (!slug) return 'Slug não pode ficar vazio.';
      if (!SLUG_REGEX.test(slug)) return 'Slug inválido (minúsculas, números, hífen/underscore).';
      return '';
    },
    async addPhone() {
      this.addError = '';
      const err = this.validateAdd();
      if (err) { this.addError = err; return; }
      this.adding = true;
      try {
        await axios.post(`/api/whatsapp/projects/${encodeURIComponent(this.projectSlug)}/instances`, {
          name: this.addForm.name.trim(),
          slug: this.addForm.slug.trim(),
          priority: this.addForm.priority ?? 0,
        });
        this.showAddModal = false;
        await this.fetchProject();
      } catch (e) {
        this.addError = this.errMsg(e, 'Falha ao criar telefone.');
      } finally {
        this.adding = false;
      }
    },

    async promote(phone) {
      this.busy = true;
      try {
        await axios.post(`/api/whatsapp/projects/${encodeURIComponent(this.projectSlug)}/instances/${encodeURIComponent(phone.slug)}/promote`);
        await this.fetchProject();
      } catch (e) {
        alert('Falha ao promover: ' + this.errMsg(e));
      } finally {
        this.busy = false;
      }
    },
    async removePhone(phone) {
      if (!window.confirm(`Apagar o telefone '${phone.name}'? A sessão do WhatsApp é encerrada e o número sai do projeto.`)) return;
      this.busy = true;
      try {
        await axios.delete(`/api/whatsapp/projects/${encodeURIComponent(this.projectSlug)}/instances/${encodeURIComponent(phone.slug)}`);
        await this.fetchProject();
      } catch (e) {
        alert('Falha ao remover: ' + this.errMsg(e));
      } finally {
        this.busy = false;
      }
    },

    errMsg(e, fallback = '') {
      return e?.response?.data?.error || e?.response?.data?.message || fallback || e.message;
    },
    statusLabel(status) {
      return {
        CONNECTED: 'Conectado',
        PENDING_QR: 'Aguardando QR',
        RECONNECTING: 'Reconectando',
        LOGGED_OUT: 'Desconectado',
        INITIALIZING: 'Iniciando',
      }[status] || status || '—';
    },
    statusClass(status) {
      if (status === 'CONNECTED') return 'pd-status--ok';
      if (status === 'LOGGED_OUT') return 'pd-status--err';
      return 'pd-status--warn';
    },
  },
};
</script>

<style scoped>
.pd-wrap { min-height: 100vh; background: #f0f2f5; font-family: system-ui, -apple-system, sans-serif; color: #1f2937; }
.pd-container { max-width: 880px; margin: 0 auto; padding: 32px 24px 64px; }
.pd-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 28px; flex-wrap: wrap; }
.pd-header__text { min-width: 0; flex: 1 1 auto; }
.pd-header__actions { display: flex; gap: 8px; flex-shrink: 0; flex-wrap: wrap; }
.pd-back { display: inline-block; font-size: 13px; color: #6b7280; text-decoration: none; margin-bottom: 8px; }
.pd-back:hover { color: #008069; }
.pd-title { margin: 0; font-size: 26px; font-weight: 700; color: #111827; }
.pd-subtitle { margin: 4px 0 0; font-size: 13px; color: #6b7280; }
.pd-slug { font-family: ui-monospace, SFMono-Regular, monospace; }

.pd-btn { display: inline-flex; align-items: center; justify-content: center; border: none; border-radius: 8px; padding: 10px 18px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background 0.15s ease; font-family: inherit; text-decoration: none; }
.pd-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.pd-btn--primary { background: #008069; color: #fff; }
.pd-btn--primary:hover:not(:disabled) { background: #006e57; }
.pd-btn--ghost { background: #fff; color: #374151; border: 1px solid #d1d5db; }
.pd-btn--ghost:hover:not(:disabled) { background: #f9fafb; }
.pd-btn--danger { background: #fee2e2; color: #991b1b; }
.pd-btn--danger:hover:not(:disabled) { background: #fecaca; }
.pd-btn--xs { padding: 6px 12px; font-size: 12px; }

.pd-resp { font-size: 13px; color: #374151; background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; }
.pd-resp--warn { background: #fffbeb; border-color: #fde68a; color: #92400e; }
.pd-link { background: none; border: none; color: #008069; font-weight: 600; cursor: pointer; font-size: 13px; padding: 0 0 0 4px; font-family: inherit; }
.pd-link:hover { text-decoration: underline; }

.pd-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
.pd-phone { display: flex; align-items: center; gap: 12px; background: #fff; border-radius: 10px; padding: 14px 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.07); flex-wrap: wrap; }
.pd-phone--active { border: 1px solid #6ee7b7; background: #f0fdf9; }
.pd-phone__prio { font-family: ui-monospace, monospace; font-size: 12px; color: #6b7280; background: #f3f4f6; padding: 4px 8px; border-radius: 6px; }
.pd-phone__info { flex: 1; min-width: 120px; }
.pd-phone__name { font-weight: 600; font-size: 15px; color: #111827; display: flex; align-items: center; gap: 8px; }
.pd-phone__slug { font-family: ui-monospace, SFMono-Regular, monospace; font-size: 12px; color: #6b7280; }
.pd-tag-active { font-size: 10px; font-weight: 700; letter-spacing: 0.04em; color: #065f46; background: #d1fae5; padding: 2px 6px; border-radius: 4px; }
.pd-phone__actions { display: flex; gap: 6px; flex-wrap: wrap; }

.pd-status { font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 12px; }
.pd-status--ok { background: #d1fae5; color: #065f46; }
.pd-status--warn { background: #fef3c7; color: #92400e; }
.pd-status--err { background: #fee2e2; color: #991b1b; }

.pd-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 56px 24px; text-align: center; background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
.pd-empty__title { margin: 0 0 8px; font-size: 18px; color: #111827; }
.pd-empty__hint { margin: 0 0 20px; font-size: 14px; color: #6b7280; max-width: 360px; }

.pd-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; padding: 16px; z-index: 50; }
.pd-modal__card { background: #fff; border-radius: 12px; padding: 28px; width: 100%; max-width: 440px; box-shadow: 0 10px 40px rgba(0,0,0,0.25); }
.pd-modal__title { margin: 0 0 4px; font-size: 20px; font-weight: 700; color: #111827; }
.pd-modal__hint { margin: 0 0 16px; font-size: 13px; color: #6b7280; }
.pd-modal__error { background: #fef2f2; color: #991b1b; padding: 10px 12px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; border: 1px solid #fecaca; }
.pd-field { display: block; margin-bottom: 16px; }
.pd-field__label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
.pd-field input { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; font-family: inherit; }
.pd-field input:focus { border-color: #008069; box-shadow: 0 0 0 3px rgba(0,128,105,0.15); }
.pd-field input:disabled { background: #f9fafb; cursor: not-allowed; }
.pd-field__hint { display: block; margin-top: 6px; font-size: 12px; color: #6b7280; }
.pd-modal__actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 8px; }
</style>
