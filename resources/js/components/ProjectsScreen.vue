<template>
  <div class="wpg">
    <!-- topbar -->
    <div class="topbar">
      <div class="logo">
        <span class="logo-mark"><img :src="logoSrc" alt="WP Gorila" /></span>
        <span class="logo-name">WP<small>·</small>Gorila</span>
      </div>
      <div class="spacer"></div>
    </div>

    <div class="scroll">
      <div class="wrap">
        <div class="page-head">
          <div>
            <h1>Projetos</h1>
            <p>Cada projeto representa um número WhatsApp conectado.</p>
          </div>
        </div>

        <div class="grid">
          <div
            v-for="p in projects"
            :key="p.id"
            class="pcard"
            @click="openProject(p)"
          >
            <div class="pcard-top">
              <div class="av-lg" :style="avatarStyle(p.slug)">{{ initials(p.name) }}</div>
              <div class="kebab-wrap" @click.stop>
                <button class="kebab" @click="toggleMenu(p.id)">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="2" /><circle cx="12" cy="12" r="2" /><circle cx="12" cy="19" r="2" /></svg>
                </button>
                <div v-if="openMenuId === p.id" class="kebab-menu">
                  <button class="kebab-menu__item" @click="deleteProject(p)">Excluir projeto</button>
                </div>
              </div>
            </div>
            <h3>{{ p.name }}</h3>
            <p class="mail">{{ p.slug }}</p>
            <div class="stat-row">
              <div class="stat"><b>{{ (p.instances || []).length }}</b>telefones</div>
              <div class="stat"><b>{{ connectedCount(p) }}</b>conectados</div>
            </div>
            <div class="pcard-foot">
              <span class="pill" :class="isConnected(p) ? 'on' : 'off'"><span class="dot"></span>{{ isConnected(p) ? 'Conectado' : 'Offline' }}</span>
              <span class="meta">atualizado {{ relativeTime(p.updated_at) }}</span>
            </div>
          </div>

          <button class="add-card" @click="openModal"><span class="plus">+</span>Adicionar projeto</button>
        </div>
      </div>
    </div>

    <!-- modal novo projeto -->
    <transition name="pop">
      <div v-if="showCreateModal" class="overlay" @click.self="closeCreateModal">
        <div class="modal">
          <h2>Novo projeto</h2>
          <div v-if="createError" class="modal-error">{{ createError }}</div>
          <div class="fgroup">
            <label>Nome</label>
            <input :value="createForm.name" @input="onNameInput($event.target.value)" placeholder="Atendimento ACCA" :disabled="creating" />
          </div>
          <div class="fgroup">
            <label>Slug</label>
            <input class="mono" :value="createForm.slug" @input="onSlugInput($event.target.value)" placeholder="acca" maxlength="31" :disabled="creating" />
          </div>
          <p class="help">Use letras minúsculas, números, hífen ou underscore. 1 a 31 caracteres.</p>
          <div class="modal-actions">
            <button class="btn-ghost" @click="closeCreateModal" :disabled="creating">Cancelar</button>
            <button class="btn-primary" @click="createProject" :disabled="creating">{{ creating ? 'Criando…' : 'Criar' }}</button>
          </div>
        </div>
      </div>
    </transition>
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
      logoSrc: '/img/gorila.png',
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

    isConnected(p) {
      return !!(p.active_instance && p.active_instance.status === 'CONNECTED');
    },
    connectedCount(p) {
      return (p.instances || []).filter((i) => i.status === 'CONNECTED').length;
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
    onNameInput(v) {
      this.createForm.name = v;
      if (!this.slugEdited) this.createForm.slug = this.slugFromName(v);
    },
    onSlugInput(v) {
      this.slugEdited = true;
      this.createForm.slug = this.slugFromName(v);
    },
    slugFromName(name) {
      if (!name) return '';
      return name.normalize('NFD').replace(/[̀-ͯ]/g, '')
        .toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/-+/g, '-').replace(/^-+|-+$/g, '').slice(0, 31);
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
      for (let i = 0; i < (slug || '').length; i++) h = (h * 31 + slug.charCodeAt(i)) >>> 0;
      const hue = h % 360;
      return { background: `linear-gradient(150deg, hsl(${hue},55%,55%), hsl(${hue},55%,42%))` };
    },
    relativeTime(iso) {
      if (!iso) return 'agora';
      const diff = Math.max(0, Date.now() - new Date(iso).getTime());
      const min = Math.floor(diff / 60000);
      if (min < 1) return 'agora';
      if (min < 60) return `há ${min} min`;
      const h = Math.floor(min / 60);
      if (h < 24) return `há ${h} h`;
      return `há ${Math.floor(h / 24)} d`;
    },
  },
};
</script>

<style scoped>
.wpg { min-height: 100vh; background: var(--bg); color: var(--ink); font-family: var(--font, 'Plus Jakarta Sans'), system-ui, -apple-system, sans-serif; }

/* topbar */
.topbar { height: 64px; display: flex; align-items: center; gap: 14px; padding: 0 28px; background: var(--panel); border-bottom: 1px solid var(--line); position: relative; z-index: 5; }
.logo { display: flex; align-items: center; gap: 8px; font-weight: 800; font-size: 18px; letter-spacing: -0.02em; }
.logo-name small { margin: 0 4px; color: var(--brand); font-weight: 800; }
.logo-mark { width: 34px; height: 34px; border-radius: 11px; display: grid; place-items: center; overflow: hidden; }
.logo-mark img { width: 100%; height: 100%; object-fit: contain; }
.spacer { flex: 1; }

/* projetos */
.scroll { min-height: calc(100vh - 64px); }
.wrap { max-width: 1180px; margin: 0 auto; padding: 48px 36px 80px; }
.page-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 24px; margin-bottom: 34px; }
.page-head h1 { font-size: 34px; font-weight: 800; margin: 0 0 6px; letter-spacing: -0.03em; }
.page-head p { margin: 0; color: var(--muted); font-size: 15px; }

.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(310px, 1fr)); gap: 20px; }

.pcard { background: var(--panel); border: 1px solid var(--line); border-radius: var(--radius); padding: 22px; box-shadow: var(--shadow); transition: transform 0.14s, box-shadow 0.14s, border-color 0.14s; cursor: pointer; position: relative; }
.pcard:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); border-color: #4a4a4a; }
.pcard-top { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 16px; }
.av-lg { width: 54px; height: 54px; border-radius: 16px; display: grid; place-items: center; color: #fff; font-weight: 800; font-size: 19px; letter-spacing: -0.02em; box-shadow: 0 6px 16px rgba(0,0,0,0.18); }
.kebab-wrap { position: relative; }
.kebab { width: 32px; height: 32px; border-radius: 9px; display: grid; place-items: center; color: var(--muted); transition: background 0.12s, color 0.12s; }
.kebab:hover { background: var(--hover); color: var(--ink-2); }
.kebab-menu { position: absolute; top: 36px; right: 0; background: #333; border: 1px solid var(--line); border-radius: 11px; box-shadow: var(--shadow-lg); min-width: 160px; z-index: 6; overflow: hidden; }
.kebab-menu__item { display: block; width: 100%; text-align: left; padding: 11px 14px; font-size: 13px; color: #f08a7e; }
.kebab-menu__item:hover { background: rgba(240,90,75,0.14); }
.pcard h3 { margin: 0 0 3px; font-size: 18px; font-weight: 700; letter-spacing: -0.01em; }
.pcard .mail { margin: 0; color: var(--muted); font-size: 13px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.stat-row { display: flex; gap: 18px; margin-top: 14px; }
.stat { font-size: 12.5px; color: var(--muted); }
.stat b { color: var(--ink); font-weight: 700; font-size: 15px; display: block; letter-spacing: -0.01em; }
.pcard-foot { display: flex; align-items: center; justify-content: space-between; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--line); }
.pill { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 700; padding: 5px 11px; border-radius: 999px; }
.pill.on { background: var(--brand-soft); color: #b794f6; }
.pill.off { background: rgba(240,90,75,0.16); color: #f08a7e; }
.pill .dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
.pcard .meta { font-size: 12px; color: var(--muted); }

.add-card { border: 1.5px dashed #444; background: transparent; border-radius: var(--radius); display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; color: var(--muted); font-weight: 600; min-height: 200px; transition: border-color 0.14s, color 0.14s, background 0.14s; cursor: pointer; font-family: inherit; font-size: 14px; }
.add-card:hover { border-color: var(--brand); color: var(--brand); background: rgba(139,92,246,0.06); }
.add-card .plus { position: relative; width: 46px; height: 46px; border-radius: 14px; background: var(--brand-soft); font-size: 0; }
.add-card .plus::before,
.add-card .plus::after { content: ''; position: absolute; top: 50%; left: 50%; background: #b794f6; border-radius: 2px; }
.add-card .plus::before { width: 18px; height: 2.5px; transform: translate(-50%, -50%); }
.add-card .plus::after { width: 2.5px; height: 18px; transform: translate(-50%, -50%); }

/* botões */
.btn-primary { display: inline-flex; align-items: center; gap: 8px; background: var(--brand); color: #fff; font-weight: 700; font-size: 14.5px; padding: 12px 20px; border-radius: 13px; box-shadow: 0 6px 18px rgba(139,92,246,0.35); transition: transform 0.12s, box-shadow 0.12s, background 0.12s; border: none; cursor: pointer; font-family: inherit; }
.btn-primary:hover:not(:disabled) { background: var(--brand-deep); transform: translateY(-1px); box-shadow: 0 10px 24px rgba(139,92,246,0.45); }
.btn-primary:disabled { background: #3a3a3a; color: var(--muted); box-shadow: none; cursor: default; }
.btn-ghost { font-weight: 700; font-size: 14.5px; padding: 12px 20px; border-radius: 13px; background: transparent; color: var(--ink-2); border: 1px solid var(--line); transition: background 0.12s, color 0.12s, border-color 0.12s; cursor: pointer; font-family: inherit; }
.btn-ghost:hover:not(:disabled) { background: var(--hover); color: var(--ink); border-color: #4a4a4a; }

/* modal */
.overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.55); backdrop-filter: blur(4px); display: grid; place-items: center; z-index: 50; padding: 24px; }
.modal { width: 100%; max-width: 460px; background: var(--panel); border: 1px solid var(--line); border-radius: 22px; box-shadow: var(--shadow-lg); padding: 28px; }
.modal h2 { margin: 0 0 22px; font-size: 22px; font-weight: 800; letter-spacing: -0.02em; }
.modal-error { background: rgba(248,113,113,0.16); color: #f87171; padding: 10px 12px; border-radius: 10px; font-size: 13px; margin-bottom: 16px; border: 1px solid rgba(248,113,113,0.3); }
.fgroup { margin-bottom: 18px; }
.fgroup label { display: block; font-size: 13px; font-weight: 700; color: var(--ink-2); margin-bottom: 8px; }
.fgroup input { width: 100%; border: 1px solid var(--line); background: #242424; border-radius: 13px; padding: 13px 15px; font-size: 14.5px; font-family: inherit; color: var(--ink); transition: border-color 0.12s, background 0.12s; }
.fgroup input::placeholder { color: var(--muted); }
.fgroup input:focus { outline: none; border-color: var(--brand); background: #2b2b2b; box-shadow: 0 0 0 4px rgba(139,92,246,0.14); }
.fgroup input.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.help { font-size: 12.5px; color: var(--muted); margin: -6px 0 0; line-height: 1.5; }
.modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 26px; }

.pop-enter-active { transition: opacity 0.18s, transform 0.18s cubic-bezier(0.2,0.8,0.3,1); }
.pop-leave-active { transition: opacity 0.14s, transform 0.14s; }
.pop-enter-from, .pop-leave-to { opacity: 0; }
.pop-enter-from .modal, .pop-leave-to .modal { transform: translateY(10px) scale(0.97); }
</style>
