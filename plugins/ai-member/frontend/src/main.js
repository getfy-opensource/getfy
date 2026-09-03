import IntegrationsSidebar from './IntegrationsSidebar.vue';
import ProductAgentPanel from './ProductAgentPanel.vue';
import FloatingChatWidget from './FloatingChatWidget.vue';
import SettingsPage from './SettingsPage.vue';

window.__GETFY_PLUGIN_UI__ = window.__GETFY_PLUGIN_UI__ || {};
window.__GETFY_PLUGIN_UI__['ai-member'] = {
    IntegrationsSidebar,
    ProductAgentPanel,
    FloatingChatWidget,
    SettingsPage,
};

export { IntegrationsSidebar, ProductAgentPanel, FloatingChatWidget, SettingsPage };
