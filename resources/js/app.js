import { createApp } from 'vue';
import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import WhatsAppConnect from './components/WhatsAppConnect.vue';
import ChatScreen from './components/ChatScreen.vue';
import InstancesScreen from './components/InstancesScreen.vue';

// Configura axios globalmente para enviar CSRF token e cookies de sessão
axios.defaults.headers.common['X-CSRF-TOKEN'] =
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
axios.defaults.withCredentials = true;

window.Pusher = Pusher;

// WebSocket via proxy nginx (mesmo host da página, porta 443/80).
// cluster é obrigatório no pusher-js mesmo com wsHost customizado.
const _isTLS = window.location.protocol === 'https:';
window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: window.location.hostname,
    wsPort: 80,
    wssPort: 443,
    forceTLS: _isTLS,
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
    cluster: 'mt1',
});

// Roteamento mínimo: o ID do mount escolhe qual componente carregar.
// #wa-app           → tela de conexão (QR code) de UMA instância
// #wa-chat-app      → tela de chat de UMA instância
// #wa-instances-app → listagem de projetos (instâncias)
//
// Os mounts por-instância exigem `data-instance-slug` no elemento — o slug
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

const instancesEl = document.getElementById('wa-instances-app');
if (instancesEl) createApp(InstancesScreen).mount(instancesEl);
