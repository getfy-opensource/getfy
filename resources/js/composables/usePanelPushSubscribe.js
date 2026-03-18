import { ref, onMounted, onUnmounted, computed } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
    return outputArray;
}

/**
 * Registra o Service Worker do painel e subscribe para push.
 * Usar no AppLayout - usa push_enabled e vapid_public das props compartilhadas.
 */
export function usePanelPushSubscribe() {
    const page = usePage();
    const pushEnabled = computed(() => !!page.props.push_enabled);
    const vapidPublic = computed(() => page.props.vapid_public ?? null);
    const pushSubscribing = ref(false);
    const pushRegistered = ref(false);
    const lastPushError = ref(null);

    function serializeSubscription(sub) {
        const p256dh = sub?.getKey?.('p256dh');
        const auth = sub?.getKey?.('auth');
        return {
            endpoint: sub?.endpoint,
            keys: {
                p256dh: p256dh ? btoa(String.fromCharCode.apply(null, new Uint8Array(p256dh))) : '',
                auth: auth ? btoa(String.fromCharCode.apply(null, new Uint8Array(auth))) : '',
            },
        };
    }

    async function syncSubscriptionToServer(sub) {
        const payload = serializeSubscription(sub);
        if (!payload.endpoint || !payload.keys?.p256dh || !payload.keys?.auth) return false;
        const { data } = await axios.post('/painel/push-subscribe', payload);
        return !!data?.success;
    }

    async function registerAndSubscribe() {
        lastPushError.value = null;
        pushRegistered.value = false;
        if (typeof navigator === 'undefined' || !navigator.serviceWorker) {
            lastPushError.value = 'service_worker_unavailable';
            return false;
        }

        try {
            await navigator.serviceWorker.register('/painel-sw.js', { scope: '/' });
        } catch (e) {
            console.warn('Panel SW registration failed:', e);
            lastPushError.value = 'service_worker_registration_failed';
            return false;
        }

        if (!pushEnabled.value || !vapidPublic.value) {
            lastPushError.value = 'push_not_configured';
            return false;
        }
        if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
            lastPushError.value = 'notification_permission_default';
            return false;
        }
        if (typeof Notification !== 'undefined' && Notification.permission === 'denied') {
            lastPushError.value = 'notification_permission_denied';
            return false;
        }
        if (pushSubscribing.value) return false;
        if (pushRegistered.value) return true;

        pushSubscribing.value = true;
        try {
            const reg = await navigator.serviceWorker.getRegistration('/');
            if (!reg) {
                lastPushError.value = 'service_worker_not_found';
                return false;
            }
            const existing = await reg.pushManager?.getSubscription?.();
            if (existing) {
                const synced = await syncSubscriptionToServer(existing);
                pushRegistered.value = synced;
                if (!synced) lastPushError.value = 'subscription_sync_failed';
                return synced;
            }
            const sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidPublic.value),
            });
            const synced = await syncSubscriptionToServer(sub);
            pushRegistered.value = synced;
            if (!synced) lastPushError.value = 'subscription_sync_failed';
            return synced;
        } catch (e) {
            if (e?.name === 'NotAllowedError') {
                lastPushError.value = 'notification_permission_denied';
                return false;
            }
            lastPushError.value = 'subscription_failed';
            console.warn('Panel push subscribe failed:', e);
            return false;
        } finally {
            pushSubscribing.value = false;
        }
    }

    /** Apenas verifica se já existe subscription no browser e atualiza pushRegistered (sem POST). Útil ao reabrir o app. */
    async function checkExistingSubscription() {
        lastPushError.value = null;
        pushRegistered.value = false;
        if (typeof navigator === 'undefined' || !navigator.serviceWorker?.getRegistration) return;
        if (typeof Notification !== 'undefined' && Notification.permission !== 'granted') return false;
        try {
            await navigator.serviceWorker.register('/painel-sw.js', { scope: '/' });
            const reg = await navigator.serviceWorker.getRegistration('/');
            const existing = await reg?.pushManager?.getSubscription?.();
            if (existing) {
                const synced = await syncSubscriptionToServer(existing);
                pushRegistered.value = synced;
                if (!synced) {
                    lastPushError.value = 'subscription_sync_failed';
                }
                return synced;
            }
            return false;
        } catch (_) {}
        return false;
    }

    const notificationPermission = computed(() =>
        typeof Notification !== 'undefined' ? Notification.permission : 'default'
    );

    const isStandalone = computed(() => {
        if (typeof window === 'undefined') return false;
        return (
            window.matchMedia('(display-mode: standalone)').matches ||
            window.navigator.standalone === true ||
            document.referrer.includes('android-app://')
        );
    });

    let permissionCheckInterval = null;

    onMounted(() => {
        // Em standalone com permissão "default", não inscrever de imediato; quando o usuário permitir, inscrever
        if (isStandalone.value && notificationPermission.value === 'default') {
            permissionCheckInterval = setInterval(() => {
                if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
                    if (permissionCheckInterval) {
                        clearInterval(permissionCheckInterval);
                        permissionCheckInterval = null;
                    }
                    registerAndSubscribe();
                }
            }, 1500);
            setTimeout(() => {
                if (permissionCheckInterval) {
                    clearInterval(permissionCheckInterval);
                    permissionCheckInterval = null;
                }
            }, 60000);
            return;
        }
        registerAndSubscribe();
    });

    onUnmounted(() => {
        if (permissionCheckInterval) {
            clearInterval(permissionCheckInterval);
        }
    });

    return {
        pushSubscribing,
        pushRegistered,
        lastPushError,
        notificationPermission,
        isStandalone,
        registerAndSubscribe,
        checkExistingSubscription,
    };
}
