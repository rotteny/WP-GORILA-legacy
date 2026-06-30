<template>
  <div class="pr-wrap">
    <div class="pr-container">
      <header class="pr-header">
        <div class="pr-header__text">
          <h1 class="pr-title">Projetos</h1>
          <p class="pr-subtitle">Agrupe telefones em projetos com failover automático.</p>
        </div>
        <div class="pr-header__actions">
          <button v-if="projects.length > 0" class="pr-btn pr-btn--primary" @click="openModal">+ Novo projeto</button>
        </div>
      </header>

      <div v-if="projects.length > 0" class="pr-grid">
        <article
          v-for="project in projects"
          :key="project.id"
          class="pr-card"
          tabindex="0"
          @click="openProject(project)"
          @keydown.enter="openProject(project)"
        >
          <div class="pr-card__menu" @click.stop>
            <button class="pr-card__menu-btn" @click="toggleMenu(project.id)">⋮</button>
            <div v-if="openMenuId === project.id" class="pr-card__menu-popover">
              <button class="pr-card__menu-item pr-card__menu-item--danger" @click="deleteProject(project)">
                Excluir projeto
              </button>
            </div>
          </div>

          <div class="pr-card__head">
            <div class="pr-card__avatar" :style="avatarStyle(project.slug)">{{ initials(project.name) }}</div>
            <div class="pr-card__body">
              <div class="pr-card__name">{{ project.name }}</div>
              <div class="pr-card__slug">{{ project.slug }}</div>
            </div>
          </div>

          <div class="pr-card__meta">
            <span class="pr-pill">{{ (project.instances || []).length }} telefone(s)</span>
            <span v-if="project.active_instance" class="pr-badge pr-badge--ok">✓ Ativo: {{ project.active_instance.name }}</span>
            <span v-else class="pr-badge pr-badge--warn">⚠ Sem telefone ativo</span>
          </div>

          <div class="pr-card__cta">Abrir projeto →</div>
        </article>
      </div>

      <div v-else-if="!loading" class="pr-empty">
        <h2 class="pr-empty__title">Nenhum projeto ainda</h2>
        <p class="pr-empty__hint">Crie um projeto para agrupar telefones com failover (ex.: TikBot → tik1, tik2, tik3).</p>
        <button class="pr-btn pr-btn--primary" @click="openModal">Criar primeiro projeto</button>
      </div>
    </div>

    <!-- Modal: criar projeto -->
    <div v-if="showCreateModal" class="pr-modal" @click.self="closeCreateModal">
      <div class="pr-modal__card">
        <h2 class="pr-modal__title">Novo projeto</h2>
        <div v-if="createError" class="pr-modal__error">{{ createError }}</div>
        <form @submit.prevent="createProject">
          <label class="pr-field">
            <span class="pr-field__label">Nome</span>
            <input v-model="createForm.name" type="text" placeholder="TikBot" :disabled="creating" @input="onNameInput" />
          </label>
          <label class="pr-field">
            <span class="pr-field__label">Slug</span>
            <input v-model="createForm.slug" type="text" placeholder="tikbot" :disabled="creating" @input="onSlugInput" />
            <span class="pr-field__hint">Minúsculas, números, hífen/underscore (1–31 caracteres).</span>
          </label>
          <div class="pr-modal__actions">
            <button type="button" class="pr-btn pr-btn--ghost" :disabled="creating" @click="closeCreateModal">Cancelar</button>
            <button type="submit" class="pr-btn pr-btn--primary" :disabled="creating">{{ creating ? 'Criando…' : 'Criar' }}</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script>
import axios from 'axios';

const SLUG_REGEX = /^[a-z0-9][a-z0-9_-]{0,30}$/;
const POLL_MS = 5000;

export default {
  name: 'ProjectsScreen',

  data() {
    return {
      projects: [],
      loading: false,
      pollHandle: null,
      openMenuId: null,
      showCreateModal: false,
      creating: false,
      createError: '',
      slugEdited: false,
      createForm: { name: '', slug: '' },
    };
  },

  mounted() {
    this.fetchProjects();
    this.pollHandle = setInterval(this.fetchProjects, POLL_MS);
    document.addEventListener('click', this.handleDocumentClick);
  },
  beforeUnmount() {
    clearInterval(this.pollHandle);
    document.removeEventListener('click', this.handleDocumentClick);
  },

  methods: {
    async fetchProjects() {
      this.loading = true;
      try {
        const { data } = await axios.get('/api/whatsapp/projects');
        this.projects = Array.isArray(data) ? data : (data.projects || []);
      } catch (e) {
        console.error('Erro ao carregar projetos:', e);
      } finally {
        this.loading = false;
      }
    },

    openProject(project) {
      window.location.href = `/projetos/${encodeURIComponent(project.slug)}`;
    },

    openModal() {
      this.showCreateModal = true;
      this.createError = '';
      this.slugEdited = false;
      this.createForm = { name: '', slug: '' };
    },
    closeCreateModal() {
      if (this.creating) return;
      this.showCreateModal = false;
    },
    onNameInput() {
      if (!this.slugEdited) this.createForm.slug = this.slugFromName(this.createForm.name);
    },
    onSlugInput() {
      this.slugEdited = true;
    },
    slugFromName(name) {
      if (!name) return '';
      return name.normalize('NFD').replace(/[̀-ͯ]/g, '')
        .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/-+/g, '-').replace(/^-+|-+$/g, '').slice(0, 31);
    },
    validateCreateForm() {
      const name = (this.createForm.name || '').trim();
      const slug = (this.createForm.slug || '').trim();
      if (!name) return 'Informe um nome.';
      if (!slug) return 'Slug não pode ficar vazio.';
      if (!SLUG_REGEX.test(slug)) return 'Slug inválido (minúsculas, números, hífen/underscore).';
      return '';
    },
    async createProject() {
      this.createError = '';
      const err = this.validateCreateForm();
      if (err) { this.createError = err; return; }
      this.creating = true;
      try {
        const { data } = await axios.post('/api/whatsapp/projects', {
          name: this.createForm.name.trim(),
          slug: this.createForm.slug.trim(),
        });
        this.showCreateModal = false;
        // Já leva direto pra dentro do projeto recém-criado pra cadastrar telefones.
        this.openProject(data);
      } catch (e) {
        this.createError = this.errMsg(e, 'Não foi possível criar o projeto.');
      } finally {
        this.creating = false;
      }
    },

    toggleMenu(id) {
      this.openMenuId = this.openMenuId === id ? null : id;
    },
    handleDocumentClick() {
      this.openMenuId = null;
    },
    async deleteProject(project) {
      this.openMenuId = null;
      if (!window.confirm(`Excluir o projeto '${project.name}'? Os telefones do projeto também serão apagados (a sessão do WhatsApp é encerrada).`)) return;
      try {
        await axios.delete(`/api/whatsapp/projects/${encodeURIComponent(project.slug)}`);
        await this.fetchProjects();
      } catch (e) {
        alert('Falha ao excluir: ' + this.errMsg(e));
      }
    },

    errMsg(e, fallback = '') {
      return e?.response?.data?.error || e?.response?.data?.message || fallback || e.message;
    },
    initials(name) {
      if (!name) return '?';
      const parts = name.trim().split(/\s+/);
      if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
      return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    },
    avatarStyle(slug) {
      let h = 0;
      for (let i = 0; i < slug.length; i++) h = (h * 31 + slug.charCodeAt(i)) >>> 0;
      return { background: `hsl(${h % 360}, 55%, 45%)` };
    },
  },
};
</script>

<style scoped>
.pr-wrap { min-height: 100vh; background: #f0f2f5; font-family: system-ui, -apple-system, sans-serif; color: #1f2937; }
.pr-container { max-width: 1120px; margin: 0 auto; padding: 32px 24px 64px; }
.pr-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 32px; flex-wrap: wrap; }
.pr-title { margin: 0; font-size: 28px; font-weight: 700; color: #111827; }
.pr-subtitle { margin: 4px 0 0; font-size: 14px; color: #6b7280; }
.pr-header__actions { display: flex; gap: 8px; }

.pr-btn { display: inline-flex; align-items: center; justify-content: center; border: none; border-radius: 8px; padding: 10px 18px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background 0.15s ease; font-family: inherit; text-decoration: none; }
.pr-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.pr-btn--primary { background: #008069; color: #fff; }
.pr-btn--primary:hover:not(:disabled) { background: #006e57; }
.pr-btn--ghost { background: #fff; color: #374151; border: 1px solid #d1d5db; }
.pr-btn--ghost:hover:not(:disabled) { background: #f9fafb; }

.pr-grid { display: grid; grid-template-columns: 1fr; gap: 16px; }
@media (min-width: 640px) { .pr-grid { grid-template-columns: repeat(2, 1fr); } }
@media (min-width: 1024px) { .pr-grid { grid-template-columns: repeat(3, 1fr); } }

.pr-card { position: relative; background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); display: flex; flex-direction: column; gap: 14px; cursor: pointer; transition: box-shadow 0.15s ease, transform 0.1s ease; outline: none; }
.pr-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); transform: translateY(-2px); }
.pr-card:focus-visible { box-shadow: 0 0 0 3px rgba(0,128,105,0.4); }
.pr-card__head { display: flex; align-items: center; gap: 12px; }
.pr-card__avatar { width: 48px; height: 48px; border-radius: 50%; color: #fff; display: grid; place-items: center; font-weight: 700; font-size: 17px; flex-shrink: 0; }
.pr-card__body { min-width: 0; }
.pr-card__name { font-weight: 700; font-size: 16px; color: #111827; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pr-card__slug { font-family: ui-monospace, SFMono-Regular, monospace; font-size: 12px; color: #6b7280; }

.pr-card__menu { position: absolute; top: 12px; right: 12px; }
.pr-card__menu-btn { background: transparent; border: none; font-size: 20px; color: #6b7280; cursor: pointer; padding: 2px 8px; border-radius: 6px; }
.pr-card__menu-btn:hover { background: #f3f4f6; }
.pr-card__menu-popover { position: absolute; top: 30px; right: 0; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.12); min-width: 160px; z-index: 5; overflow: hidden; }
.pr-card__menu-item { display: block; width: 100%; background: none; border: none; padding: 10px 14px; font-size: 13px; cursor: pointer; font-family: inherit; text-align: left; }
.pr-card__menu-item--danger { color: #dc2626; }
.pr-card__menu-item--danger:hover { background: #fef2f2; }

.pr-card__meta { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.pr-pill { font-size: 12px; color: #374151; background: #f3f4f6; padding: 4px 10px; border-radius: 12px; }
.pr-badge { display: inline-block; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 12px; }
.pr-badge--ok { background: #d1fae5; color: #065f46; }
.pr-badge--warn { background: #fef3c7; color: #92400e; }
.pr-card__cta { font-size: 13px; font-weight: 600; color: #008069; }

.pr-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 64px 24px; text-align: center; background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
.pr-empty__title { margin: 0 0 8px; font-size: 20px; color: #111827; }
.pr-empty__hint { margin: 0 0 24px; font-size: 14px; color: #6b7280; max-width: 380px; }

.pr-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; padding: 16px; z-index: 50; }
.pr-modal__card { background: #fff; border-radius: 12px; padding: 28px; width: 100%; max-width: 440px; box-shadow: 0 10px 40px rgba(0,0,0,0.25); }
.pr-modal__title { margin: 0 0 16px; font-size: 20px; font-weight: 700; color: #111827; }
.pr-modal__error { background: #fef2f2; color: #991b1b; padding: 10px 12px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; border: 1px solid #fecaca; }
.pr-field { display: block; margin-bottom: 16px; }
.pr-field__label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
.pr-field input { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; font-family: inherit; }
.pr-field input:focus { border-color: #008069; box-shadow: 0 0 0 3px rgba(0,128,105,0.15); }
.pr-field input:disabled { background: #f9fafb; cursor: not-allowed; }
.pr-field__hint { display: block; margin-top: 6px; font-size: 12px; color: #6b7280; }
.pr-modal__actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 8px; }
</style>
