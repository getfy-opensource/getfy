<script setup>
import { ref, watch, computed } from 'vue';
import { X, Bell, BellRing, Check, ExternalLink } from 'lucide-vue-next';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { usePanelPushSubscribe } from '@/composables/usePanelPushSubscribe';
import { pushErrorMessage, pushTestResult } from '@/lib/pushSubscription';
import { usePage } from '@inertiajs/vue3';
import Toggle from '@/components/ui/Toggle.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
});

const emit = defineEmits(['update:open', 'unread-count-update']);

const page = usePage();
const pushEnabled = computed(() => !!page.props.push_enabled);
const {
    pushRegistered,
    registerAndSubscribe,
    checkExistingSubscription,
    lastPushError,
    reactivatePush,
} = usePanelPushSubscribe();

// Não acessar Notification no template (é API global; Vue resolve como prop do componente). Tudo via computeds:
const hasNotificationAPI = computed(() => typeof window !== 'undefined' && typeof window.Notification !== 'undefined');
const notificationPermissionDenied = computed(
    () => hasNotificationAPI.value && window.Notification.permission === 'denied'
);
const notificationPermissionGranted = computed(
    () => hasNotificationAPI.value && window.Notification.permission === 'granted'
);
const pushActive = computed(() => notificationPermissionGranted.value && pushRegistered.value);

const pushErrorLabel = computed(() =>
    lastPushError.value ? pushErrorMessage(lastPushError.value) : null,
);

const canReactivatePush = computed(
    () =>
        pushEnabled.value &&
        notificationPermissionGranted.value &&
        !pushActive.value &&
        (lastPushError.value || !pushRegistered.value),
);

// Botão "Ativar": permissão ainda não concedida neste navegador
const canActivatePush = computed(
    () =>
        pushEnabled.value &&
        !pushActive.value &&
        hasNotificationAPI.value &&
        window.Notification.permission !== 'denied' &&
        !canReactivatePush.value,
);

const loading = ref(false);
const notifications = ref([]);
const unreadCount = ref(0);
const pushSubscribed = ref(false);
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const activatingPush = ref(false);
const pushPreferences = ref({ pix: true, boleto: true, card: true });
const savingPreferences = ref(false);
const sendingTestPush = ref(false);
const testPushFeedback = ref(null);

async function fetchNotifications() {
    if (!props.open) return;
    loading.value = true;
    try {
        const { data } = await axios.get('/painel/notifications', { params: { per_page: 20 } });
        notifications.value = data.data ?? [];
        unreadCount.value = data.unread_count ?? 0;
        pushSubscribed.value = data.push_subscribed ?? false;
        if (data.push_preferences) {
            pushPreferences.value = {
                pix: data.push_preferences.pix !== false,
                boleto: data.push_preferences.boleto !== false,
                card: data.push_preferences.card !== false,
            };
        }
        meta.value = data.meta ?? { current_page: 1, last_page: 1, total: 0 };
        emit('unread-count-update', unreadCount.value);
    } catch (_) {
        notifications.value = [];
    } finally {
        loading.value = false;
    }
}

async function savePushPreferences() {
    if (!pushSubscribed.value && !pushActive.value) return;
    savingPreferences.value = true;
    try {
        const { data } = await axios.patch('/painel/push-preferences', {
            preferences: pushPreferences.value,
        });
        if (data?.preferences) {
            pushPreferences.value = {
                pix: data.preferences.pix !== false,
                boleto: data.preferences.boleto !== false,
                card: data.preferences.card !== false,
            };
        }
    } catch (_) {}
    savingPreferences.value = false;
}

watch(
    () => props.open,
    async (isOpen) => {
        if (!isOpen) return;
        fetchNotifications();
        checkExistingSubscription({ silent: true }).then((synced) => {
            if (synced && !pushSubscribed.value) {
                pushSubscribed.value = true;
            }
        });
    },
);

function close() {
    emit('update:open', false);
}

async function markRead(notification) {
    if (notification.read_at) return;
    try {
        await axios.patch(`/painel/notifications/${notification.id}/read`);
        notification.read_at = new Date().toISOString();
        unreadCount.value = Math.max(0, unreadCount.value - 1);
        emit('unread-count-update', unreadCount.value);
    } catch (_) {}
}

async function markAllRead() {
    try {
        await axios.post('/painel/notifications/mark-all-read');
        notifications.value.forEach((n) => {
            n.read_at = n.read_at || new Date().toISOString();
        });
        unreadCount.value = 0;
        emit('unread-count-update', 0);
    } catch (_) {}
}

async function clearAllNotifications() {
    const ok = confirm('Tem certeza que deseja limpar todas as notificações?');
    if (!ok) return;
    try {
        await axios.delete('/painel/notifications');
        notifications.value = [];
        unreadCount.value = 0;
        meta.value = { current_page: 1, last_page: 1, total: 0 };
        emit('unread-count-update', 0);
    } catch (_) {}
}

async function openNotification(notification) {
    await markRead(notification);
    if (notification.url) {
        close();
        router.visit(notification.url);
    }
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    const now = new Date();
    const diffMs = now - d;
    const diffMins = Math.floor(diffMs / 60000);
    if (diffMins < 1) return 'Agora';
    if (diffMins < 60) return `${diffMins} min`;
    const diffHours = Math.floor(diffMins / 60);
    if (diffHours < 24) return `${diffHours}h`;
    const diffDays = Math.floor(diffHours / 24);
    if (diffDays < 7) return `${diffDays}d`;
    return d.toLocaleDateString();
}

async function activateNotifications() {
    if (typeof window === 'undefined' || typeof window.Notification === 'undefined' || !pushEnabled.value) return;
    activatingPush.value = true;
    try {
        const result = await window.Notification.requestPermission();
        if (result === 'granted') {
            const success = await registerAndSubscribe();
            if (success) {
                await fetchNotifications();
                pushSubscribed.value = true;
            }
        }
    } catch (_) {}
    activatingPush.value = false;
}

const hasUnread = computed(() => unreadCount.value > 0);

async function sendTestPush() {
    sendingTestPush.value = true;
    testPushFeedback.value = null;
    try {
        testPushFeedback.value = pushTestResult(await axios.post('/painel/push-test'));
    } catch (e) {
        testPushFeedback.value = pushTestResult(null, e);
    }
    sendingTestPush.value = false;
}

async function reactivateNotifications() {
    activatingPush.value = true;
    try {
        const success = await reactivatePush();
        if (success) {
            await fetchNotifications();
            pushSubscribed.value = true;
        }
    } catch (_) {}
    activatingPush.value = false;
}
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="fixed inset-0 z-[100000] flex justify-end"
                aria-modal="true"
                role="dialog"
                aria-label="Notificações"
            >
                <div
                    class="absolute inset-0 bg-black/40"
                    aria-hidden="true"
                    @click="close"
                />
                <div
                    class="relative flex w-full max-w-md flex-col bg-white shadow-2xl dark:bg-zinc-800 sm:max-w-sm"
                    @click.stop
                >
                    <div class="flex shrink-0 items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                            Notificações
                        </h2>
                        <button
                            type="button"
                            class="rounded-lg p-2 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300"
                            aria-label="Fechar"
                            @click="close"
                        >
                            <X class="h-5 w-5" />
                        </button>
                    </div>

                    <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                Notificações push
                            </span>
                            <span
                                v-if="pushActive"
                                class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-400"
                            >
                                <Check class="h-3.5 w-3.5" />
                                Ativo
                            </span>
                            <span
                                v-else
                                class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-600 dark:text-zinc-300"
                            >
                                Inativo
                            </span>
                        </div>
                        <p
                            v-if="pushEnabled && !pushActive"
                            class="mt-1 text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            Receba avisos de vendas e pagamentos no navegador ou no app.
                        </p>
                        <p
                            v-if="pushSubscribed && !pushActive"
                            class="mt-1 text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            Notificações já estão ativas em outro dispositivo. Para ativar neste, permita no navegador.
                        </p>
                        <button
                            v-if="canActivatePush"
                            type="button"
                            class="mt-2 w-full rounded-lg bg-[var(--color-primary)] px-3 py-2 text-sm font-medium text-white transition hover:opacity-90 disabled:opacity-60"
                            :disabled="activatingPush"
                            @click="activateNotifications"
                        >
                            {{ activatingPush ? 'Aguarde...' : 'Ativar notificações' }}
                        </button>
                        <p
                            v-else-if="pushEnabled && !pushActive && notificationPermissionDenied"
                            class="mt-2 text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            Notificações bloqueadas. Habilite nas configurações do navegador para receber avisos.
                        </p>
                        <p
                            v-else-if="pushEnabled && !pushActive && pushErrorLabel"
                            class="mt-2 text-xs text-amber-700 dark:text-amber-300/90"
                        >
                            {{ pushErrorLabel }}
                        </p>
                        <button
                            v-if="canReactivatePush"
                            type="button"
                            class="mt-2 w-full rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm font-medium text-zinc-800 transition hover:bg-zinc-100 disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700"
                            :disabled="activatingPush"
                            @click="reactivateNotifications"
                        >
                            {{ activatingPush ? 'Aguarde...' : 'Reativar notificações' }}
                        </button>
                        <p
                            v-else-if="!pushEnabled"
                            class="mt-2 text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            Notificações push não configuradas no servidor (chaves VAPID).
                        </p>

                        <template v-if="pushEnabled && pushActive">
                            <button
                                type="button"
                                class="mt-2 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm font-medium text-zinc-800 transition hover:bg-zinc-100 disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700"
                                :disabled="sendingTestPush"
                                @click="sendTestPush"
                            >
                                <BellRing class="h-4 w-4" />
                                {{ sendingTestPush ? 'Enviando...' : 'Enviar notificação de teste' }}
                            </button>
                            <p
                                v-if="testPushFeedback"
                                class="mt-1 text-xs"
                                :class="testPushFeedback.ok ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-300/90'"
                            >
                                {{ testPushFeedback.message }}
                            </p>
                        </template>

                        <div
                            v-if="pushEnabled && (pushActive || pushSubscribed)"
                            class="mt-3 space-y-2 border-t border-zinc-200 pt-3 dark:border-zinc-600"
                        >
                            <p class="text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                Avisar sobre
                            </p>
                            <Toggle
                                v-model="pushPreferences.pix"
                                label="PIX"
                                :disabled="savingPreferences"
                                @update:model-value="savePushPreferences"
                            />
                            <Toggle
                                v-model="pushPreferences.boleto"
                                label="Boleto"
                                :disabled="savingPreferences"
                                @update:model-value="savePushPreferences"
                            />
                            <Toggle
                                v-model="pushPreferences.card"
                                label="Cartão, Apple Pay e Google Pay"
                                :disabled="savingPreferences"
                                @update:model-value="savePushPreferences"
                            />
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center justify-between border-b border-zinc-200 px-4 py-2 dark:border-zinc-700">
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ meta.total }} {{ meta.total === 1 ? 'notificação' : 'notificações' }}
                        </span>
                        <div class="flex items-center gap-3">
                            <button
                                v-if="notifications.length > 0"
                                type="button"
                                class="text-sm font-medium text-red-600 hover:underline dark:text-red-400"
                                @click="clearAllNotifications"
                            >
                                Limpar
                            </button>
                            <button
                                v-if="hasUnread"
                                type="button"
                                class="text-sm font-medium text-[var(--color-primary)] hover:underline"
                                @click="markAllRead"
                            >
                                Marcar todas como lidas
                            </button>
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto">
                        <div
                            v-if="loading"
                            class="flex items-center justify-center py-12"
                        >
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">Carregando...</span>
                        </div>
                        <div
                            v-else-if="notifications.length === 0"
                            class="flex flex-col items-center justify-center py-12 text-center text-sm text-zinc-500 dark:text-zinc-400"
                        >
                            <Bell class="mb-2 h-10 w-10 opacity-50" />
                            Nenhuma notificação
                        </div>
                        <ul
                            v-else
                            class="divide-y divide-zinc-200 dark:divide-zinc-700"
                        >
                            <li
                                v-for="n in notifications"
                                :key="n.id"
                            >
                                <button
                                    type="button"
                                    class="flex w-full flex-col items-start gap-0.5 px-4 py-3 text-left transition hover:bg-zinc-50 dark:hover:bg-zinc-700/50"
                                    :class="{ 'bg-zinc-50/80 dark:bg-zinc-700/30': !n.read_at }"
                                    @click="openNotification(n)"
                                >
                                    <div class="flex w-full items-start justify-between gap-2">
                                        <span class="font-medium text-zinc-900 dark:text-white">
                                            {{ n.title }}
                                        </span>
                                        <ExternalLink
                                            v-if="n.url"
                                            class="h-4 w-4 shrink-0 text-zinc-400"
                                        />
                                    </div>
                                    <p
                                        v-if="n.body"
                                        class="line-clamp-2 text-sm text-zinc-600 dark:text-zinc-400"
                                    >
                                        {{ n.body }}
                                    </p>
                                    <span class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
                                        {{ formatDate(n.created_at) }}
                                    </span>
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
