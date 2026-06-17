<template>
  <div class="ip-wrap">
    <div class="ip-container">
      <!-- HEADER -->
      <header class="ip-header">
        <div class="ip-header__text">
          <h1 class="ip-title">Projetos</h1>
          <p class="ip-subtitle">Cada projeto representa um número WhatsApp conectado.</p>
        </div>
        <button
          v-if="instances.length > 0"
          class="ip-btn ip-btn--primary"
          type="button"
          @click="openModal"
        >
          + Novo projeto
        </button>
      </header>

      <!-- LOADING (1ª carga) -->
      <div v-if="loading && instances.length === 0" class="ip-empty">
        <p>Carregando projetos...</p>
      </div>

      <!-- ESTADO VAZIO -->
      <div v-else-if="instances.length === 0" class="ip-empty">
        <div class="ip-empty__illustration" aria-hidden="true">
          <svg width="96" height="96" viewBox="0 0 24 24" fill="none">
            <path
              d="M12 2C6.48 2 2 6.48 2 12c0 1.85.5 3.58 1.38 5.07L2 22l5.07-1.38A9.93 9.93 0 0012 22c5.52 0 10-4.48 10-10S17.52 2 12 2zm0 18a7.95 7.95 0 01-4.07-1.11l-.29-.17-3 .82.82-3-.17-.29A7.95 7.95 0 014 12c0-4.41 3.59-8 8-8s8 3.59 8 8-3.59 8-8 8z"
              fill="#008069"
            />
            <path
              d="M16.5 14.36c-.25-.13-1.48-.73-1.71-.81-.23-.08-.4-.13-.56.13-.17.25-.65.81-.79.97-.15.17-.29.18-.54.06-.25-.13-1.05-.39-2-1.24-.74-.66-1.24-1.47-1.38-1.72-.15-.25-.02-.39.11-.51.11-.11.25-.29.38-.43.13-.15.17-.25.25-.42.08-.17.04-.31-.02-.43-.06-.13-.56-1.35-.77-1.85-.2-.48-.41-.42-.56-.43h-.48c-.17 0-.43.06-.66.31-.23.25-.87.85-.87 2.07 0 1.22.89 2.4 1.02 2.57.13.17 1.76 2.69 4.27 3.77.6.26 1.06.41 1.42.53.6.19 1.14.16 1.57.1.48-.07 1.48-.6 1.69-1.18.21-.59.21-1.09.15-1.18-.06-.1-.23-.16-.48-.29z"
              fill="#008069"
            />
          </svg>
        </div>
        <h2 class="ip-empty__title">Nenhum projeto ainda</h2>
        <p class="ip-empty__hint">Crie seu primeiro projeto para conectar um número WhatsApp.</p>
        <button class="ip-btn ip-btn--primary" type="button" @click="openModal">
          Criar primeiro projeto
        </button>
      </div>

      <!-- GRID DE CARDS -->
      <div v-else class="ip-grid">
        <article
          v-for="inst in instances"
          :key="inst.id"
          class="ip-card"
          @click="openInstance(inst)"
          tabindex="0"
          @keydown.enter="openInstance(inst)"
        >
          <!-- Menu ⋮ -->
          <div class="ip-card__menu" @click.stop>
            <button
              class="ip-card__menu-btn"
              type="button"
              :aria-label="`Ações do projeto ${inst.name}`"
              @click="toggleMenu(inst.id)"
            >⋮</button>
            <div v-if="openMenuId === inst.id" class="ip-card__menu-popover">
              <button
                type="button"
                class="ip-card__menu-item ip-card__menu-item--danger"
                @click="deleteInstance(inst)"
              >Excluir</button>
            </div>
          </div>

          <div class="ip-card__avatar" :style="avatarStyle(inst.slug)">
            {{ initials(inst.name) }}
          </div>

          <div class="ip-card__body">
            <div class="ip-card__name">{{ inst.name }}</div>
            <div class="ip-card__slug">{{ inst.slug }}@wp-gorila</div>
          </div>

          <div class="ip-card__footer">
            <span class="ip-badge" :class="badgeClass(inst.status)">
              {{ badgeLabel(inst.status) }}
            </span>
            <span class="ip-card__updated">
              {{ formatRelative(inst.updated_at || inst.last_event_at || inst.created_at) }}
            </span>
          </div>
        </article>
      </div>
    </div>

    <!-- MODAL DE CRIAÇÃO -->
    <div v-if="showModal" class="ip-modal" @click.self="closeModal">
      <div class="ip-modal__card" role="dialog" aria-modal="true" aria-labelledby="ip-modal-title">
        <h2 id="ip-modal-title" class="ip-modal__title">Novo projeto</h2>

        <div v-if="formError" class="ip-modal__error">{{ formError }}</div>

        <form @submit.prevent="createInstance">
          <label class="ip-field">
            <span class="ip-field__label">Nome</span>
            <input
              v-model="form.name"
              class="ip-field__input"
              type="text"
              placeholder="Atendimento ACCA"
              :disabled="creating"
              @input="onNameInput"
              autofocus
            />
          </label>

          <label class="ip-field">
            <span class="ip-field__label">Slug</span>
            <input
              v-model="form.slug"
              class="ip-field__input"
              type="text"
              placeholder="acca"
              :disabled="creating"
              @input="onSlugInput"
            />
            <span class="ip-field__hint">
              Use letras minúsculas, números, hífen ou underscore. 1 a 31 caracteres.
            </span>
          </label>

          <div class="ip-modal__actions">
            <button
              type="button"
              class="ip-btn ip-btn--ghost"
              :disabled="creating"
              @click="closeModal"
            >
              Cancelar
            </button>
            <button
              type="submit"
              class="ip-btn ip-btn--primary"
              :disabled="creating"
            >
              {{ creating ? 'Criando...' : 'Criar' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script>
import axios from 'axios';

const POLL_MS = 5000;
const SLUG_REGEX = /^[a-z0-9][a-z0-9_-]{0,30}$/;

export default {
  name: 'InstancesScreen',

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
      form: {
        name: '',
        slug: '',
      },
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
  // Compat Vue 2
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
      // Fecha o menu ⋮ se clicou fora de qualquer card
      this.openMenuId = null;
    },

    async fetchInstances() {
      this.loading = true;
      try {
        const { data } = await axios.get('/api/whatsapp/instances');
        // Aceita tanto array direto quanto { instances: [...] }
        this.instances = Array.isArray(data) ? data : (data.instances || []);
      } catch (e) {
        // Silencioso: poll segue tentando.
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
      // Auto-gera slug enquanto o usuário não tocou no campo slug
      if (!this.slugEdited) {
        this.form.slug = this.slugFromName(this.form.name);
      }
    },

    onSlugInput() {
      // Marca como editado manualmente quando o usuário interagir
      this.slugEdited = true;
    },

    slugFromName(name) {
      if (!name) return '';
      // Remove acentos (combining marks U+0300 a U+036F)
      const noAccents = name
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '');
      // Lowercase + substitui não alfanum por hífen + colapsa hífens + trim
      let slug = noAccents
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-+|-+$/g, '');
      // Garante que comece com alfanum (regex exige isso)
      slug = slug.replace(/^[^a-z0-9]+/, '');
      // Limita a 31 chars
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
        `Excluir o projeto '${instance.name}'? Esta ação não pode ser desfeita.`
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

    // ---------- helpers visuais ----------
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
      return { background: `hsl(${hue}, 55%, 50%)` };
    },

    badgeLabel(status) {
      return ({
        CONNECTED: '✓ Conectado',
        PENDING_QR: '⏳ Aguardando QR',
        INITIALIZING: 'Iniciando...',
        RECONNECTING: '↻ Reconectando',
        LOGGED_OUT: '✕ Desconectado',
      })[status] || status || '—';
    },

    badgeClass(status) {
      return {
        'ip-badge--ok': status === 'CONNECTED',
        'ip-badge--warn': status === 'PENDING_QR',
        'ip-badge--neutral': status === 'INITIALIZING',
        'ip-badge--orange': status === 'RECONNECTING',
        'ip-badge--err': status === 'LOGGED_OUT',
      };
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
.ip-wrap {
  min-height: 100vh;
  background: #f0f2f5;
  font-family: system-ui, -apple-system, sans-serif;
  color: #1f2937;
}

.ip-container {
  max-width: 1120px;
  margin: 0 auto;
  padding: 32px 24px 64px;
}

/* ---------- HEADER ---------- */
.ip-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 32px;
  flex-wrap: wrap;
}
.ip-header__text { min-width: 0; }
.ip-title {
  margin: 0;
  font-size: 28px;
  font-weight: 700;
  color: #111827;
}
.ip-subtitle {
  margin: 4px 0 0;
  font-size: 14px;
  color: #6b7280;
}

/* ---------- BOTÕES ---------- */
.ip-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: none;
  border-radius: 8px;
  padding: 10px 18px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s ease, transform 0.05s ease;
  font-family: inherit;
}
.ip-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.ip-btn--primary {
  background: #008069;
  color: #fff;
}
.ip-btn--primary:hover:not(:disabled) {
  background: #006e57;
}
.ip-btn--primary:active:not(:disabled) {
  transform: translateY(1px);
}
.ip-btn--ghost {
  background: #fff;
  color: #374151;
  border: 1px solid #d1d5db;
}
.ip-btn--ghost:hover:not(:disabled) {
  background: #f9fafb;
}

/* ---------- GRID ---------- */
.ip-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
}
@media (min-width: 640px) {
  .ip-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (min-width: 960px) {
  .ip-grid { grid-template-columns: repeat(3, 1fr); }
}

/* ---------- CARD ---------- */
.ip-card {
  position: relative;
  background: #fff;
  border-radius: 12px;
  padding: 24px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
  cursor: pointer;
  transition: box-shadow 0.15s ease, transform 0.1s ease;
  display: flex;
  flex-direction: column;
  gap: 16px;
  outline: none;
}
.ip-card:hover {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
  transform: translateY(-2px);
}
.ip-card:focus-visible {
  box-shadow: 0 0 0 3px rgba(0, 128, 105, 0.35);
}

.ip-card__avatar {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  color: #fff;
  display: grid;
  place-items: center;
  font-weight: 700;
  font-size: 20px;
  flex-shrink: 0;
}

.ip-card__body {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}
.ip-card__name {
  font-weight: 700;
  font-size: 16px;
  color: #111827;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ip-card__slug {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12px;
  color: #6b7280;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ip-card__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  flex-wrap: wrap;
}
.ip-card__updated {
  font-size: 11px;
  color: #6b7280;
}

/* ---------- MENU ⋮ ---------- */
.ip-card__menu {
  position: absolute;
  top: 12px;
  right: 12px;
}
.ip-card__menu-btn {
  background: transparent;
  border: none;
  font-size: 20px;
  color: #6b7280;
  cursor: pointer;
  padding: 4px 8px;
  border-radius: 6px;
  line-height: 1;
}
.ip-card__menu-btn:hover {
  background: #f3f4f6;
  color: #111827;
}
.ip-card__menu-popover {
  position: absolute;
  top: 32px;
  right: 0;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
  min-width: 140px;
  z-index: 5;
  overflow: hidden;
}
.ip-card__menu-item {
  display: block;
  width: 100%;
  background: none;
  border: none;
  padding: 10px 14px;
  font-size: 13px;
  text-align: left;
  cursor: pointer;
  color: #1f2937;
  font-family: inherit;
}
.ip-card__menu-item:hover {
  background: #f9fafb;
}
.ip-card__menu-item--danger {
  color: #dc2626;
}
.ip-card__menu-item--danger:hover {
  background: #fef2f2;
}

/* ---------- BADGE ---------- */
.ip-badge {
  font-size: 11px;
  font-weight: 600;
  padding: 4px 10px;
  border-radius: 12px;
  background: #e5e7eb;
  color: #374151;
  white-space: nowrap;
}
.ip-badge--ok      { background: #d1fae5; color: #065f46; }
.ip-badge--warn    { background: #fef3c7; color: #92400e; }
.ip-badge--neutral { background: #e5e7eb; color: #374151; }
.ip-badge--orange  { background: #ffedd5; color: #9a3412; }
.ip-badge--err     { background: #fee2e2; color: #991b1b; }

/* ---------- ESTADO VAZIO ---------- */
.ip-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 64px 24px;
  text-align: center;
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
}
.ip-empty__illustration {
  margin-bottom: 16px;
  opacity: 0.85;
}
.ip-empty__title {
  margin: 0 0 8px;
  font-size: 20px;
  color: #111827;
}
.ip-empty__hint {
  margin: 0 0 24px;
  font-size: 14px;
  color: #6b7280;
  max-width: 360px;
}

/* ---------- MODAL ---------- */
.ip-modal {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  z-index: 50;
}
.ip-modal__card {
  background: #fff;
  border-radius: 12px;
  padding: 28px;
  width: 100%;
  max-width: 440px;
  box-shadow: 0 10px 40px rgba(0, 0, 0, 0.25);
}
.ip-modal__title {
  margin: 0 0 16px;
  font-size: 20px;
  font-weight: 700;
  color: #111827;
}
.ip-modal__error {
  background: #fef2f2;
  color: #991b1b;
  padding: 10px 12px;
  border-radius: 8px;
  font-size: 13px;
  margin-bottom: 16px;
  border: 1px solid #fecaca;
}

.ip-field {
  display: block;
  margin-bottom: 16px;
}
.ip-field__label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  color: #374151;
  margin-bottom: 6px;
}
.ip-field__input {
  width: 100%;
  box-sizing: border-box;
  padding: 10px 12px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  font-size: 14px;
  outline: none;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
  font-family: inherit;
}
.ip-field__input:focus {
  border-color: #008069;
  box-shadow: 0 0 0 3px rgba(0, 128, 105, 0.15);
}
.ip-field__input:disabled {
  background: #f9fafb;
  cursor: not-allowed;
}
.ip-field__hint {
  display: block;
  margin-top: 6px;
  font-size: 12px;
  color: #6b7280;
}

.ip-modal__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 8px;
}
</style>
