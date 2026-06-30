import { createApp } from 'vue';
import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import WhatsAppConnect from './components/WhatsAppConnect.vue';
import ChatScreen from './components/ChatScreen.vue';
import ProjectsScreen from './components/ProjectsScreen.vue';
import ProjectDetailScreen from './components/ProjectDetailScreen.vue';

// Configura axios globalmente para enviar CSRF token e cookies de sessão
axios.defaults.headers.common['X-CSRF-TOKEN'] =
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
axios.defaults.withCredentials = true;

window.Pusher = Pusher;

// WebSocket via proxy nginx (mesmo host da página, porta 443/80).
// IMPORTANTE: o Echo/Reverb é OPCIONAL. Sem a chave (ex.: build sem
// VITE_REVERB_APP_KEY) NÃO pode derrubar o app — as telas caem no polling.
// Por isso só inicializa se houver chave e protege contra qualquer erro:
// um Reverb mal configurado nunca mais deixa o painel em branco.
const _reverbKey = import.meta.env.VITE_REVERB_APP_KEY;
if (_reverbKey) {
  try {
    const _isTLS = window.location.protocol === 'https:';
    window.Echo = new Echo({
      broadcaster: 'reverb',
      key: _reverbKey,
      wsHost: window.location.hostname,
      wsPort: 80,
      wssPort: 443,
      forceTLS: _isTLS,
      enabledTransports: ['ws', 'wss'],
      disableStats: true,
      cluster: 'mt1', // obrigatório no pusher-js mesmo com wsHost customizado
    });
  } catch (e) {
    console.warn('Echo/Reverb não inicializado — seguindo com polling.', e);
  }
} else {
  console.warn('VITE_REVERB_APP_KEY ausente — Echo desativado, usando polling.');
}

// Roteamento mínimo: o ID do mount escolhe qual componente carregar.
// #wa-app          → tela de conexão (QR code) de UM telefone
// #wa-chat-app     → tela de chat de UM telefone
// #wa-projects-app → lista de projetos (home)
// #wa-project-app  → página de um projeto (telefones + failover)
//
// Os mounts por-telefone exigem `data-instance-slug` no elemento — o slug
// vem da URL (ex.: /p/piloto/qr) e é passado como prop pro componente.

function mountWithSlug(elId, Component) {
  const el = document.getElementById(elId);
  if (!el) return;
  const slug = el.dataset.instanceSlug;
  if (!slug) {
    console.error(`Mount ${elId} sem data-instance-slug — componente não será montado.`);
    return;
  }
  createApp(Component, { instanceSlug: slug }).mount(el);
}

mountWithSlug('wa-app', WhatsAppConnect);
mountWithSlug('wa-chat-app', ChatScreen);

const projectsEl = document.getElementById('wa-projects-app');
if (projectsEl) createApp(ProjectsScreen).mount(projectsEl);

// Página de um projeto (#wa-project-app com data-project-slug).
const projectEl = document.getElementById('wa-project-app');
if (projectEl) {
  const slug = projectEl.dataset.projectSlug;
  if (slug) createApp(ProjectDetailScreen, { projectSlug: slug }).mount(projectEl);
  else console.error('Mount wa-project-app sem data-project-slug.');
}
