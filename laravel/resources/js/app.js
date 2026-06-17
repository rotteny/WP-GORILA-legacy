import { createApp } from 'vue';
import WhatsAppConnect from './components/WhatsAppConnect.vue';
import ChatScreen from './components/ChatScreen.vue';

// Roteamento mínimo: o ID do mount escolhe qual componente carregar.
// #wa-app          → tela de conexão (QR code)
// #wa-chat-app     → tela de chat
const connectEl = document.getElementById('wa-app');
if (connectEl) createApp(WhatsAppConnect).mount(connectEl);

const chatEl = document.getElementById('wa-chat-app');
if (chatEl) createApp(ChatScreen).mount(chatEl);
