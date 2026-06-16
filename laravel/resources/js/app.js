import { createApp } from 'vue';
import WhatsAppConnect from './components/WhatsAppConnect.vue';

const mountEl = document.getElementById('wa-app');

if (mountEl) {
    createApp(WhatsAppConnect).mount(mountEl);
}
