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

        <template v-else>
          <div v-if="hasWarmingOnly" class="pd-filter">
            <label class="pd-check">
              <input type="checkbox" v-model="showWarmingOnly" />
              Mostrar telefones de aquecimento
            </label>
          </div>

          <ul class="pd-list">
            <li
              v-for="phone in visiblePhones"
              :key="phone.id"
              class="pd-phone"
              :class="{ 'pd-phone--active': project.active_instance_id === phone.id }"
            >
              <span class="pd-phone__prio">#{{ phone.priority }}</span>
              <div class="pd-phone__info">
                <div class="pd-phone__name">
                  {{ phone.name }}
                  <span v-if="project.active_instance_id === phone.id" class="pd-tag-active">ATIVO</span>
                  <span v-if="phone.warming_only" class="pd-tag-warming" title="Chip dedicado ao aquecimento — não envia mensagens externas">🔥 aquecimento</span>
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
                  v-if="project.active_instance_id !== phone.id && !phone.warming_only"
                  class="pd-btn pd-btn--xs pd-btn--ghost"
                  :disabled="busy"
                  @click="promote(phone)"
                >Tornar ativo</button>
                <label class="pd-warmtoggle" title="Usar este telefone apenas para aquecimento">
                  <input
                    type="checkbox"
                    :checked="phone.warming_only"
                    :disabled="busy"
                    @change="toggleWarmingOnly(phone)"
                  />
                  <span>Só aquecimento</span>
                </label>
                <button class="pd-btn pd-btn--xs pd-btn--danger" :disabled="busy" @click="removePhone(phone)">Remover</button>
              </div>
            </li>
          </ul>
        </template>
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

    <!-- Modal: confirmar dedicar ao aquecimento um telefone que é o ativo -->
    <div v-if="warmingConfirm.show" class="pd-modal" @click.self="cancelWarming">
      <div class="pd-modal__card">
        <h2 class="pd-modal__title">Dedicar ao aquecimento?</h2>
        <p class="pd-modal__hint">
          Esta instância está sendo usada como telefone ativo do projeto no momento.
          Ativar aquecimento-apenas vai forçar uma troca imediata para o próximo chip de
          produção CONNECTED. Deseja continuar?
        </p>
        <div class="pd-modal__actions">
          <button type="button" class="pd-btn pd-btn--ghost" :disabled="busy" @click="cancelWarming">Cancelar</button>
          <button type="button" class="pd-btn pd-btn--primary" :disabled="busy" @click="confirmWarming">Continuar</button>
        </div>
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

      showWarmingOnly: true, // filtro: padrão mostra todos os telefones
      warmingConfirm: { show: false, phone: null },
    };
  },

  computed: {
    phones() {
      return this.project?.instances || [];
    },
    // Lista renderizada respeitando o filtro "mostrar telefones de aquecimento".
    visiblePhones() {
      return this.showWarmingOnly ? this.phones : this.phones.filter((p) => !p.warming_only);
    },
    hasWarmingOnly() {
      return this.phones.some((p) => p.warming_only);
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
    // Alterna o papel warming-only. Ligar num telefone que é o ativo pede confirmação
    // (vai forçar failover); nos demais casos aplica direto.
    toggleWarmingOnly(phone) {
      const target = !phone.warming_only;
      if (target && this.project.active_instance_id === phone.id) {
        this.warmingConfirm = { show: true, phone };
        return;
      }
      this.setWarmingOnly(phone, target);
    },
    cancelWarming() {
      if (this.busy) return;
      this.warmingConfirm = { show: false, phone: null };
      // Re-sincroniza o checkbox (bind :checked) com o estado real do servidor.
      this.fetchProject();
    },
    async confirmWarming() {
      const phone = this.warmingConfirm.phone;
      this.warmingConfirm = { show: false, phone: null };
      if (phone) await this.setWarmingOnly(phone, true);
    },
    async setWarmingOnly(phone, value) {
      this.busy = true;
      try {
        await axios.patch(
          `/api/whatsapp/projects/${encodeURIComponent(this.projectSlug)}/instances/${encodeURIComponent(phone.slug)}`,
          { warming_only: value },
        );
        await this.fetchProject();
      } catch (e) {
        alert('Falha ao atualizar aquecimento: ' + this.errMsg(e));
        await this.fetchProject(); // volta o checkbox ao estado real
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
.pd-wrap { min-height: 100vh; background: var(--wpg-bg); font-family: var(--wpg-font); color: var(--wpg-ink); }
.pd-container { max-width: 880px; margin: 0 auto; padding: 32px 24px 64px; }
.pd-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 28px; flex-wrap: wrap; }
.pd-header__text { min-width: 0; flex: 1 1 auto; }
.pd-header__actions { display: flex; gap: 8px; flex-shrink: 0; flex-wrap: wrap; }
.pd-back { display: inline-block; font-size: 13px; color: var(--wpg-muted); text-decoration: none; margin-bottom: 8px; }
.pd-back:hover { color: var(--wpg-brand); }
.pd-title { margin: 0; font-size: 26px; font-weight: 800; color: var(--wpg-ink); letter-spacing: -0.02em; }
.pd-subtitle { margin: 4px 0 0; font-size: 13px; color: var(--wpg-muted); }
.pd-slug { font-family: ui-monospace, SFMono-Regular, monospace; }

.pd-btn { display: inline-flex; align-items: center; justify-content: center; border: none; border-radius: 10px; padding: 10px 18px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background 0.15s ease; font-family: inherit; text-decoration: none; }
.pd-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.pd-btn--primary { background: var(--wpg-brand); color: #fff; }
.pd-btn--primary:hover:not(:disabled) { background: var(--wpg-brand-deep); }
.pd-btn--ghost { background: transparent; color: var(--wpg-ink); border: 1px solid var(--wpg-line); }
.pd-btn--ghost:hover:not(:disabled) { background: var(--wpg-hover); }
.pd-btn--danger { background: var(--wpg-err-soft); color: var(--wpg-err); }
.pd-btn--danger:hover:not(:disabled) { background: rgba(248,113,113,0.28); }
.pd-btn--xs { padding: 6px 12px; font-size: 12px; }

.pd-resp { font-size: 13px; color: var(--wpg-ink); background: var(--wpg-brand-soft); border: 1px solid rgba(139,92,246,0.4); border-radius: 10px; padding: 10px 14px; margin-bottom: 16px; }
.pd-resp--warn { background: var(--wpg-warn-soft); border-color: rgba(251,191,36,0.4); color: var(--wpg-warn); }
.pd-link { background: none; border: none; color: var(--wpg-brand); font-weight: 600; cursor: pointer; font-size: 13px; padding: 0 0 0 4px; font-family: inherit; }
.pd-link:hover { text-decoration: underline; }

.pd-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
.pd-phone { display: flex; align-items: center; gap: 12px; background: var(--wpg-panel); border: 1px solid var(--wpg-line); border-radius: 12px; padding: 14px 16px; box-shadow: var(--wpg-shadow); flex-wrap: wrap; }
.pd-phone--active { border-color: var(--wpg-brand); background: var(--wpg-brand-soft); }
.pd-phone__prio { font-family: ui-monospace, monospace; font-size: 12px; color: var(--wpg-muted); background: var(--wpg-hover); padding: 4px 8px; border-radius: 6px; }
.pd-phone__info { flex: 1; min-width: 120px; }
.pd-phone__name { font-weight: 600; font-size: 15px; color: var(--wpg-ink); display: flex; align-items: center; gap: 8px; }
.pd-phone__slug { font-family: ui-monospace, SFMono-Regular, monospace; font-size: 12px; color: var(--wpg-muted); }
.pd-tag-active { font-size: 10px; font-weight: 700; letter-spacing: 0.04em; color: #c4b5fd; background: var(--wpg-brand-soft); padding: 2px 6px; border-radius: 4px; }
.pd-tag-warming { font-size: 10px; font-weight: 700; letter-spacing: 0.04em; color: var(--wpg-warn); background: var(--wpg-warn-soft); padding: 2px 6px; border-radius: 4px; }
.pd-phone__actions { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
.pd-warmtoggle { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: var(--wpg-muted); cursor: pointer; user-select: none; padding: 0 4px; }
.pd-warmtoggle input { cursor: pointer; margin: 0; }

.pd-filter { margin-bottom: 10px; }
.pd-check { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--wpg-muted); cursor: pointer; }
.pd-check input { cursor: pointer; margin: 0; }

.pd-status { font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 12px; }
.pd-status--ok { background: var(--wpg-ok-soft); color: var(--wpg-ok); }
.pd-status--warn { background: var(--wpg-warn-soft); color: var(--wpg-warn); }
.pd-status--err { background: var(--wpg-err-soft); color: var(--wpg-err); }

.pd-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 56px 24px; text-align: center; background: var(--wpg-panel); border: 1px solid var(--wpg-line); border-radius: var(--wpg-radius); box-shadow: var(--wpg-shadow); }
.pd-empty__title { margin: 0 0 8px; font-size: 18px; color: var(--wpg-ink); }
.pd-empty__hint { margin: 0 0 20px; font-size: 14px; color: var(--wpg-muted); max-width: 360px; }

.pd-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; padding: 16px; z-index: 50; }
.pd-modal__card { background: var(--wpg-panel); border: 1px solid var(--wpg-line); border-radius: var(--wpg-radius); padding: 28px; width: 100%; max-width: 440px; box-shadow: var(--wpg-shadow-lg); }
.pd-modal__title { margin: 0 0 4px; font-size: 20px; font-weight: 700; color: var(--wpg-ink); }
.pd-modal__hint { margin: 0 0 16px; font-size: 13px; color: var(--wpg-muted); }
.pd-modal__error { background: var(--wpg-err-soft); color: var(--wpg-err); padding: 10px 12px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; border: 1px solid rgba(248,113,113,0.3); }
.pd-field { display: block; margin-bottom: 16px; }
.pd-field__label { display: block; font-size: 13px; font-weight: 600; color: var(--wpg-ink); margin-bottom: 6px; }
.pd-field input { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid var(--wpg-line); border-radius: 10px; font-size: 14px; outline: none; font-family: inherit; background: var(--wpg-bg); color: var(--wpg-ink); }
.pd-field input::placeholder { color: var(--wpg-muted); }
.pd-field input:focus { border-color: var(--wpg-brand); box-shadow: 0 0 0 3px var(--wpg-brand-soft); }
.pd-field input:disabled { opacity: 0.6; cursor: not-allowed; }
.pd-field__hint { display: block; margin-top: 6px; font-size: 12px; color: var(--wpg-muted); }
.pd-modal__actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 8px; }
</style>
