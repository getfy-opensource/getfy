<script setup>
import { computed } from 'vue';
import { MessageCircle, Headset, HelpCircle } from 'lucide-vue-next';

const props = defineProps({
    config: { type: Object, default: () => ({}) },
    primaryColor: { type: String, default: '#7427F1' },
});

const enabled = computed(() => props.config?.enabled === true);
const text = computed(() => props.config?.text || 'Suporte');
const url = computed(() => props.config?.url || '#');
const buttonColor = computed(() => props.config?.color || '#25D366');
const positionClass = computed(() => {
    const p = props.config?.position || 'bottom-right';
    const map = {
        'bottom-right': 'bottom-4 right-4',
        'bottom-left': 'bottom-4 left-4',
        'top-right': 'top-4 right-4',
        'top-left': 'top-4 left-4',
    };
    return map[p] || map['bottom-right'];
});

const IconComponent = computed(() => {
    const icon = props.config?.icon || 'whatsapp';
    if (icon === 'message-circle') return MessageCircle;
    if (icon === 'headset') return Headset;
    if (icon === 'help-circle') return HelpCircle;
    return MessageCircle;
});
</script>

<template>
    <a
        v-if="enabled"
        data-checkout="support-button"
        :href="url"
        target="_blank"
        rel="noopener noreferrer"
        class="fixed z-40 flex items-center gap-2 rounded-full px-4 py-3 text-sm font-semibold text-white shadow-lg transition hover:opacity-95 focus:outline-none focus:ring-2 focus:ring-offset-2"
        :class="positionClass"
        :style="{ backgroundColor: buttonColor }"
    >
        <component :is="IconComponent" class="h-5 w-5" />
        <span>{{ text }}</span>
    </a>
</template>
