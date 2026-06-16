<script setup>
import { onBeforeUnmount, ref, watch, computed, defineExpose } from 'vue';
import {
    mountCajuPayPixParcelado,
    confirmCajuPayParceladoController,
    setCajuPayConsumer,
    buildCajuPayConsumer,
} from '@/composables/useCajuPaySdk';

const props = defineProps({
    payAccountId: { type: String, default: '' },
    paymentLinkToken: { type: String, default: '' },
    amountCents: { type: Number, default: 0 },
    description: { type: String, default: '' },
    sdkOptions: { type: Object, default: () => ({}) },
    initialConsumer: { type: Object, default: () => ({}) },
    containerId: { type: String, default: 'cajupay-parcelado-method' },
});

const emit = defineEmits(['ready', 'error']);

const error = ref('');
const loading = ref(false);
const controller = ref(null);
const widgetReady = ref(false);
const mountKey = ref('');

const containerSelector = computed(() => `#${props.containerId}`);

function resolvedConsumer() {
    return buildCajuPayConsumer(props.initialConsumer);
}

function syncConsumerWithWidget() {
    if (!controller.value) {
        return;
    }
    setCajuPayConsumer(controller.value, resolvedConsumer());
}

function destroyController() {
    try {
        controller.value?.destroy?.();
    } catch (_) {
        // ignore
    }
    controller.value = null;
    widgetReady.value = false;
    mountKey.value = '';
    const el = typeof document !== 'undefined' ? document.querySelector(containerSelector.value) : null;
    if (el) {
        try {
            el.innerHTML = '';
        } catch (_) {
            // ignore
        }
    }
}

async function tryMount() {
    const key = `${props.payAccountId}:${props.paymentLinkToken}:${props.amountCents}`;
    if (!props.payAccountId || !props.paymentLinkToken || props.amountCents < 1) {
        destroyController();
        return;
    }
    if (mountKey.value === key && controller.value) {
        return;
    }
    error.value = '';
    loading.value = true;
    destroyController();
    try {
        await new Promise((r) => setTimeout(r, 0));
        controller.value = await mountCajuPayPixParcelado(containerSelector.value, {
            payAccountId: props.payAccountId,
            paymentLinkToken: props.paymentLinkToken,
            amountCents: props.amountCents,
            description: props.description,
            sdkOptions: props.sdkOptions,
            consumer: resolvedConsumer(),
            onStatus: (event) => {
                const phase = event?.phase || event?.status || '';
                if (phase === 'ready' || phase === 'loading') {
                    widgetReady.value = phase === 'ready';
                }
                if (phase === 'ready') {
                    emit('ready');
                }
            },
            onError: (e) => {
                const msg = e?.error || e?.message || 'Erro no widget PIX Parcelado.';
                error.value = msg;
                emit('error', e);
            },
        });
        mountKey.value = key;
        widgetReady.value = true;
        syncConsumerWithWidget();
        emit('ready');
    } catch (e) {
        error.value = e?.message || 'Não foi possível carregar PIX Parcelado.';
        controller.value = null;
        emit('error', e);
    } finally {
        loading.value = false;
    }
}

watch(
    () => [props.payAccountId, props.paymentLinkToken, props.amountCents, props.sdkOptions],
    () => tryMount(),
    { deep: true },
);

watch(
    () => props.initialConsumer,
    () => syncConsumerWithWidget(),
    { deep: true },
);

onBeforeUnmount(() => destroyController());

function setConsumer(payer) {
    return setCajuPayConsumer(controller.value, buildCajuPayConsumer(payer));
}

async function confirm() {
    return confirmCajuPayParceladoController(controller.value);
}

function isReady() {
    return Boolean(controller.value) && widgetReady.value;
}

defineExpose({ confirm, setConsumer, isReady, controller });
</script>

<template>
    <div>
        <p v-if="error" class="mb-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">
            {{ error }}
        </p>
        <div :id="containerId" class="min-h-[120px]" />
        <div v-if="loading" class="mt-2 text-sm text-gray-500">Carregando opções de parcelamento…</div>
    </div>
</template>
