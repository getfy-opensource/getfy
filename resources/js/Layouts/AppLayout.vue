<script setup>
import { computed, ref, watch, watchEffect, provide } from 'vue';
import { usePage, Head } from '@inertiajs/vue3';
import { useSidebarProvider } from '@/composables/useSidebar';
import { usePanelPushSubscribe } from '@/composables/usePanelPushSubscribe';
import AppSidebar from '@/components/layout/AppSidebar.vue';
import AppHeader from '@/components/layout/AppHeader.vue';
import MobileBottomNav from '@/components/layout/MobileBottomNav.vue';
import PwaInstallPrompt from '@/components/layout/PwaInstallPrompt.vue';
import PullToRefresh from '@/components/layout/PullToRefresh.vue';
import NotificationsPanel from '@/components/layout/NotificationsPanel.vue';
import Backdrop from '@/components/layout/Backdrop.vue';
import FlashToast from '@/components/layout/FlashToast.vue';
import CloudBillingBanner from '@/components/layout/CloudBillingBanner.vue';

const { isExpanded } = useSidebarProvider();
usePanelPushSubscribe();
const page = usePage();
const faviconHref = computed(() => page.props.public_branding?.favicon_url ?? null);
const pageTitle = computed(() => page.props.pageTitle ?? null);
const pageTitleBadge = computed(() => page.props.pageTitleBadge ?? null);
const contentMaxWidth = computed(() => (page.props.layoutFullWidth ? 'max-w-[1600px]' : 'max-w-7xl'));
const layoutContentFlushLeft = computed(() => !!page.props.layoutContentFlushLeft);

const showNotificationsPanel = ref(false);
const notificationsUnreadCount = ref(page.props.notifications_unread_count ?? 0);
watch(
    () => page.props.notifications_unread_count,
    (v) => {
        notificationsUnreadCount.value = v ?? 0;
    }
);
provide('openNotificationsPanel', () => {
    showNotificationsPanel.value = true;
});
provide('notificationsUnreadCount', notificationsUnreadCount);

function onNotificationsUnreadCountUpdate(count) {
    notificationsUnreadCount.value = count;
}

watchEffect(() => {
    const primary = page.props.appSettings?.theme_primary || '#0ea5e9';
    document.documentElement.style.setProperty('--color-primary', primary);
});
</script>

<template>
    <Head v-if="faviconHref">
        <link rel="icon" :href="faviconHref" type="image/png" sizes="32x32" />
        <link rel="shortcut icon" :href="faviconHref" type="image/png" />
    </Head>
    <div class="min-h-screen bg-zinc-100 dark:bg-zinc-900">
        <AppSidebar />
        <slot name="sidebar-after-nav" />
        <Backdrop />
        <div
            class="flex min-h-screen flex-col transition-all duration-300 ease-in-out p-3 md:p-4 lg:p-6"
            :class="[
                isExpanded ? 'lg:ml-[260px]' : 'lg:ml-[64px]',
            ]"
        >
            <div class="flex w-full shrink-0 flex-col gap-2">
                <div class="-mx-3 md:-mx-4 lg:-mx-6">
                    <CloudBillingBanner />
                </div>
                <AppHeader :page-title="pageTitle" :page-title-badge="pageTitleBadge" />
                <slot name="header-actions" />
            </div>
            <FlashToast />
            <PwaInstallPrompt />
            <PullToRefresh />
            <NotificationsPanel
                :open="showNotificationsPanel"
                @update:open="showNotificationsPanel = $event"
                @unread-count-update="onNotificationsUnreadCountUpdate"
            />
            <MobileBottomNav />
            <div
                class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-zinc-800"
            >
                <main class="flex-1 min-w-0 overflow-y-auto px-4 pb-24 pt-4 md:px-6 md:pt-6 lg:pb-8">
                    <div
                        class="w-full min-w-0"
                        :class="[
                            layoutContentFlushLeft ? 'max-w-none lg:-ml-6' : 'mx-auto',
                            !layoutContentFlushLeft && contentMaxWidth,
                        ]"
                    >
                        <slot />
                        <slot name="content-footer" />
                    </div>
                </main>
            </div>
        </div>
    </div>
</template>
