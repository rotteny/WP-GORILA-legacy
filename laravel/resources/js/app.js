import { createApp } from 'vue';
import WhatsAppConnect from './components/WhatsAppConnect.vue';
import ChatScreen from './components/ChatScreen.vue';
import InstancesScreen from './components/InstancesScreen.vue';

// Roteamento mínimo: o ID do mount escolhe qual componente carregar.
// #wa-app           → tela de conexão (QR code)
// #wa-chat-app      → tela de chat
// #wa-instances-app → listagem de projetos (instâncias)
const connectEl = document.getElementById('wa-app');
if (connectEl) createApp(WhatsAppConnect).mount(connectEl);

const chatEl = document.getElementById('wa-chat-app');
if (chatEl) createApp(ChatScreen).mount(chatEl);

const instancesEl = document.getElementById('wa-instances-app');
if (instancesEl) createApp(InstancesScreen).mount(instancesEl);
