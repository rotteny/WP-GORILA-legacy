import { createApp } from 'vue';
import WhatsAppConnect from './components/WhatsAppConnect.vue';
import ChatScreen from './components/ChatScreen.vue';
import InstancesScreen from './components/InstancesScreen.vue';
import WebhooksScreen from './components/WebhooksScreen.vue';

// Roteamento mínimo: o ID do mount escolhe qual componente carregar.
// #wa-app           → tela de conexão (QR code) de UMA instância
// #wa-chat-app      → tela de chat de UMA instância
// #wa-webhooks-app  → tela de gerenciamento de webhooks de UMA instância
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
mountWithSlug('wa-webhooks-app', WebhooksScreen);

const instancesEl = document.getElementById('wa-instances-app');
if (instancesEl) createApp(InstancesScreen).mount(instancesEl);
