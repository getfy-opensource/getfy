<script setup>
import { onBeforeUnmount, ref, watch, computed, defineExpose } from 'vue';
import {
    mountCajuPayCheckout,
    confirmCajuPayController,
    cajupayDefaultMethodFor,
    setCajuPayPayer,
    cajuPayRefusalMessage,
    isCajuPaySoftAuthError,
} from '@/composables/useCajuPaySdk';

const props = defineProps({
    paymentMethod: { type: String, required: true },
    sessionToken: { type: String, default: '' },
    /**
     * Pré-preenchimento no mount do widget. No cartão, omita `name` para não
     * copiar o nome do comprador para o campo "titular do cartão" do SDK.
     */
    initialPayer: { type: Object, default: () => ({}) },
    /**
     * Dados enviados em setPayer (priming / sync). Pode incluir `name` mesmo
     * quando initialPayer não pré-preenche o titular.
     */
    syncPayer: { type: Object, default: null },
    containerId: { type: String, default: 'cajupay-method' },
    /** Apple/Google Pay: chamado imediatamente antes do 1º `confirm()` do SDK (materializar Order no Getfy). */
    beforeWalletPrime: { type: Function, default: null },
    /** Espelha validateCajuPayCustomerFields no host — bloqueia priming da wallet sem pedido. */
    payerReadyForPrime: { type: Boolean, default: false },
    /**
     * Quando false (warmup em background), monta o SDK/scripts sem a 1ª confirm().
     * Assim Apple/Google aquecem sem materializar Order até o método estar ativo.
     */
    primeEnabled: { type: Boolean, default: true },
    /** Assinatura: pede persistência de cartão (card_token) no SDK/sessão. */
    saveCard: { type: Boolean, default: false },
});

const emit = defineEmits(['wallet-payment-completed', 'payment-failed']);

const error = ref('');
const loading = ref(false);
const controller = ref(null);
const mountedToken = ref('');
// Para card / Apple Pay / Google Pay em embeddedOnly: o SDK exige DOIS controller.confirm():
//   1ª chamada (auto, logo após mount) → cria a cobrança no PSP e renderiza o widget
//      (input do cartão / botão da wallet nativa)
//   2ª chamada (no clique do Pagar) → submete os dados / dispara o flow nativo
// `cardFieldReady` vira true quando o SDK emite onStatus phase "awaiting_card_details"
// OU quando a 1ª promise de confirm resolve — o que vier primeiro.
// Para PIX (quando a sessão permitir), NÃO há priming: um único confirm no clique do Pagar.
const cardFieldReady = ref(false);
// Flag pra impedir que a 1ª confirm() automática seja interpretada como erro normal
// (ela pode lançar `awaiting_card_details` como rejection em alguns SDKs antigos).
const cardPrimingInFlight = ref(false);
let recoveryTimer = null;
let mountGeneration = 0;

const containerSelector = computed(() => `#${props.containerId}`);
// Métodos que precisam do priming (1ª confirm() automática pra renderizar widget):
// card (input), apple_pay (botão Apple Pay), google_pay (botão Google Pay).
// PIX não precisa — gera no único confirm do Pagar.
const needsPriming = computed(() => ['card', 'apple_pay', 'google_pay'].includes(props.paymentMethod));
const isCardMethod = computed(() => props.paymentMethod === 'card');
const isWalletMethod = computed(() => props.paymentMethod === 'apple_pay' || props.paymentMethod === 'google_pay');

const showWalletPreparing = computed(() => {
    if (!isWalletMethod.value || !props.sessionToken || !props.primeEnabled) {
        return false;
    }
    if (cardFieldReady.value) {
        return false;
    }
    // Spinner durante mount/priming mesmo se ainda há hint antigo de “preencha os dados”.
    if (loading.value || cardPrimingInFlight.value) {
        return true;
    }
    // Sem erro e ainda sem botão = painel vazio; cobre o gap.
    return !error.value;
});

const walletPreparingLabel = computed(() => {
    if (props.paymentMethod === 'apple_pay') {
        return 'Carregando Apple Pay…';
    }
    if (props.paymentMethod === 'google_pay') {
        return 'Carregando Google Pay…';
    }
    return 'Carregando pagamento…';
});

function payerForSync() {
    const sync = props.syncPayer && typeof props.syncPayer === 'object' ? props.syncPayer : null;
    const initial = props.initialPayer && typeof props.initialPayer === 'object' ? props.initialPayer : {};
    return sync || initial;
}

function clearRecoveryTimer() {
    if (recoveryTimer) {
        clearTimeout(recoveryTimer);
        recoveryTimer = null;
    }
}

function scheduleRecoveryRetry(delayMs = 2500) {
    clearRecoveryTimer();
    if (!props.sessionToken) {
        return;
    }
    recoveryTimer = setTimeout(() => {
        recoveryTimer = null;
        if (!props.sessionToken || cardFieldReady.value || cardPrimingInFlight.value || loading.value) {
            return;
        }
        mountedToken.value = '';
        tryMount(0);
    }, delayMs);
}

function destroyController() {
    try {
        controller.value?.destroy?.();
    } catch (_) {
        // ignore
    }
    controller.value = null;
    mountedToken.value = '';
    cardFieldReady.value = false;
    cardPrimingInFlight.value = false;
    const el = typeof document !== 'undefined' ? document.querySelector(containerSelector.value) : null;
    if (el) {
        try { el.innerHTML = ''; } catch (_) { /* ignore */ }
    }
}

function reportPaymentFailed(payload) {
    if (isCajuPaySoftAuthError(payload)) {
        return;
    }
    const message = cajuPayRefusalMessage(payload);
    error.value = message;
    // Priming / mount: erro de carga do widget — não é recusa de cobrança.
    if (cardPrimingInFlight.value || loading.value || !cardFieldReady.value) {
        return;
    }
    emit('payment-failed', { message, raw: payload });
}

function isTransientSdkLoadError(err) {
    const msg = String(err?.message || err?.error || err || '').toLowerCase();
    return (
        msg.includes('failed to fetch')
        || msg.includes('network')
        || msg.includes('evervault')
        || msg.includes('rinne_js')
        || msg.includes('load_failed')
        || msg.includes('buscar configura')
        || msg.includes('public-settings')
        || msg.includes('err_network')
        || msg.includes('timeout')
        || msg.includes('abort')
        || msg.includes('não está pronto')
        || msg.includes('nao esta pronto')
    );
}

async function tryMount(attempt = 0) {
    if (!props.sessionToken) {
        if (controller.value) destroyController();
        return;
    }
    if (mountedToken.value === props.sessionToken && attempt === 0 && controller.value) {
        if (needsPriming.value && props.primeEnabled && !cardFieldReady.value && !cardPrimingInFlight.value) {
            await primeCardField();
        }
        return;
    }
    error.value = '';
    loading.value = true;
    const generation = ++mountGeneration;
    if (controller.value) destroyController();
    try {
        await new Promise((r) => setTimeout(r, 0));
        if (generation !== mountGeneration) {
            return;
        }
        controller.value = await mountCajuPayCheckout(containerSelector.value, {
            token: props.sessionToken,
            defaultMethod: cajupayDefaultMethodFor(props.paymentMethod),
            initialPayer: props.initialPayer,
            saveCard: props.saveCard === true,
            // Doc Caju: onStatus phase "error" + onError → recusa no host.
            onStatus: (event) => {
                const phase = event?.phase || event?.status || '';
                if (phase === 'awaiting_card_details' || phase === 'awaiting_wallet_confirmation') {
                    cardFieldReady.value = true;
                }
                if (phase === 'completed') {
                    emit('wallet-payment-completed', event);
                }
                // confirming / AWAITING_3DS: SDK abre challenge — não é recusa.
                if (phase === 'error' && !isCajuPaySoftAuthError(event)) {
                    reportPaymentFailed(event);
                }
            },
            onError: (event) => {
                reportPaymentFailed(event);
            },
        });
        if (generation !== mountGeneration) {
            return;
        }
        mountedToken.value = props.sessionToken;

        // Para CARD / APPLE PAY / GOOGLE PAY: dispara a 1ª confirm() automaticamente —
        // sem isso o widget NÃO aparece (input do cartão / botão da wallet). Essa
        // chamada cria a cobrança no PSP e monta o widget. NÃO submete pagamento ainda
        // — isso é a 2ª chamada (vem do clique do Pagar via .confirm() exposto).
        // Warmup de wallet (primeEnabled=false): só monta scripts/SDK, sem Order.
        if (needsPriming.value && props.primeEnabled) {
            await primeCardField();
        } else if (!needsPriming.value) {
            cardFieldReady.value = true;
        }
        clearRecoveryTimer();
    } catch (e) {
        if (generation !== mountGeneration) {
            return;
        }
        // ERR_NETWORK_CHANGED / Failed to fetch no Rinne/Evervault — tenta de novo.
        if (attempt < 4 && isTransientSdkLoadError(e)) {
            mountedToken.value = '';
            await new Promise((r) => setTimeout(r, 600 * (attempt + 1)));
            return tryMount(attempt + 1);
        }
        error.value = e?.message || 'Não foi possível carregar o checkout CajuPay.';
        if (isTransientSdkLoadError(e)) {
            error.value = 'Falha de conexão ao carregar o pagamento. Tentando novamente…';
            scheduleRecoveryRetry(2000 + attempt * 500);
        }
        controller.value = null;
    } finally {
        if (generation === mountGeneration) {
            loading.value = false;
        }
    }
}

/**
 * Dispara a 1ª confirm() do fluxo de cartão (embeddedOnly). Após resolve, o input
 * do cartão deve estar visível dentro do container. Tratamos rejection de "awaiting"
 * como sucesso — alguns SDKs sinalizam o estado intermediário via reject.
 */
async function primeCardField() {
    if (!controller.value || cardPrimingInFlight.value || cardFieldReady.value) return;
    if (!props.primeEnabled) return;

    if (isWalletMethod.value && !props.payerReadyForPrime) {
        error.value = 'Preencha e-mail e os dados obrigatórios acima para usar a carteira.';
        return;
    }

    cardPrimingInFlight.value = true;
    error.value = '';

    // Sincroniza o payer ANTES da cobrança ser criada no PSP. Sem isso, a 1ª
    // confirm pode falhar com payer_name_required dependendo da config da sessão.
    // No cartão NÃO enviamos name aqui: o SDK preenche o campo "Nome do Titular"
    // com esse valor. Nome do comprador só vai no confirm final (host setPayer).
    const payer = payerForSync();
    const syncPayload = {
        email: payer?.email,
        document: payer?.document,
    };
    if (!isCardMethod.value && payer?.name) {
        syncPayload.name = payer.name;
    }
    setCajuPayPayer(controller.value, syncPayload);

    try {
        if (isWalletMethod.value && typeof props.beforeWalletPrime === 'function') {
            await props.beforeWalletPrime();
        }
        await controller.value.confirm();
        cardFieldReady.value = true;
        error.value = '';
        clearRecoveryTimer();
    } catch (e) {
        const msg = (e?.message || e?.error || '').toString().toLowerCase();
        // "awaiting_card_details" / similar = estado normal pós-priming, não é erro real.
        if (msg.includes('awaiting') || msg.includes('card_details')) {
            cardFieldReady.value = true;
            error.value = '';
            clearRecoveryTimer();
        } else if (msg.includes('payer_name') || msg.includes('payer_email') || msg.includes('payer_document')) {
            // Cliente ainda não preencheu os dados; mostra hint suave em vez de erro
            // alarmante. O watcher de initialPayer vai re-tentar quando os campos forem preenchidos.
            error.value = 'Preencha seus dados acima para carregar o pagamento.';
        } else if (msg.includes('method_not_available') || msg.includes('confirm_unavailable_for_method')) {
            // O método selecionado não está liberado nessa conta CajuPay (interseção
            // de allow_* da sessão com allowed_payment_methods da conta). O ideal é
            // que o controller já tenha bloqueado lá em CheckoutForm.vue antes de
            // chegar aqui — esse caminho é só fallback se methods_available não veio.
            const label = props.paymentMethod === 'apple_pay' ? 'Apple Pay'
                : props.paymentMethod === 'google_pay' ? 'Google Pay'
                : 'Esse método';
            error.value = `${label} não está disponível para esta conta CajuPay no momento. Selecione outra forma de pagamento (ex.: Cartão).`;
        } else if (isTransientSdkLoadError(e)) {
            error.value = 'Falha de conexão ao carregar o pagamento. Tentando novamente…';
            scheduleRecoveryRetry(2000);
        } else if (!cardFieldReady.value) {
            // Erro real (ex.: PSP recusou criar cobrança) — surface ao usuário.
            const label = isCardMethod.value
                ? 'cartão'
                : props.paymentMethod === 'apple_pay' ? 'Apple Pay'
                : props.paymentMethod === 'google_pay' ? 'Google Pay'
                : 'pagamento';
            error.value = e?.message || `Falha ao iniciar o ${label}.`;
            scheduleRecoveryRetry(4000);
        }
    } finally {
        cardPrimingInFlight.value = false;
    }
}

function remount() {
    clearRecoveryTimer();
    mountedToken.value = '';
    error.value = '';
    tryMount(0);
}

watch(() => props.sessionToken, () => { tryMount(); }, { immediate: true });
watch(() => props.paymentMethod, () => {
    if (props.sessionToken) {
        mountedToken.value = '';
        tryMount();
    }
});

watch(
    () => props.primeEnabled,
    (enabled) => {
        if (!enabled || !props.sessionToken || !controller.value) return;
        if (cardFieldReady.value || cardPrimingInFlight.value) return;
        primeCardField();
    }
);

// Re-tenta o priming (card/wallets) quando o cliente preenche/atualiza os dados
// (debounced). Só dispara se o priming ainda não deu certo. Sem isso, se o cliente
// seleciona o método sem ter preenchido nada, o widget nunca aparece mesmo depois
// que ele preenche.
let primeRetryTimer = null;
watch(
    () => [props.payerReadyForPrime, props.primeEnabled, props.initialPayer, props.syncPayer],
    () => {
        if (!needsPriming.value || !props.primeEnabled) return;
        if (!controller.value) return;
        if (cardFieldReady.value) return;
        if (isWalletMethod.value && !props.payerReadyForPrime) return;
        clearTimeout(primeRetryTimer);
        primeRetryTimer = setTimeout(() => { primeCardField(); }, 400);
    },
    { deep: true }
);

onBeforeUnmount(() => {
    clearTimeout(primeRetryTimer);
    clearRecoveryTimer();
    mountGeneration += 1;
    destroyController();
});

async function confirm() {
    if (!controller.value) {
        throw new Error('CajuPay: aguarde o checkout terminar de carregar.');
    }
    // Para card / wallets, garante que a 1ª confirm() (priming do widget) terminou
    // antes de disparar a 2ª (submissão real). Espera até 15s pra cardFieldReady=true.
    if (needsPriming.value && !cardFieldReady.value) {
        if (props.primeEnabled && !cardPrimingInFlight.value) {
            await primeCardField();
        }
        const start = Date.now();
        while (!cardFieldReady.value && Date.now() - start < 15000) {
            await new Promise((r) => setTimeout(r, 100));
        }
        if (!cardFieldReady.value) {
            remount();
            throw new Error('CajuPay: o método de pagamento ainda não está pronto. Aguarde 1-2 segundos e clique novamente.');
        }
    }
    return await confirmCajuPayController(controller.value);
}

/**
 * Atualiza o payer (name/email/document) no controller atual, sem remontar. Use
 * isto antes de confirm() quando o cliente preencher os dados DEPOIS do widget
 * já estar montado — funciona pra todos os métodos, incluindo card (não destrói
 * os inputs do iframe). Indicação oficial da CajuPay para fluxo embeddedOnly.
 *
 * Retorna true se aplicou (SDK suporta setPayer), false se silenciosamente
 * ignorou (SDK antigo sem setPayer ainda no cache do navegador).
 *
 * @param {{ name?: string, email?: string, document?: string, phone?: string }} payer
 * @returns {boolean}
 */
function setPayer(payer) {
    if (!controller.value) return false;
    return setCajuPayPayer(controller.value, payer);
}

defineExpose({
    confirm,
    isReady: () => !!controller.value,
    setPayer,
    isCardFieldReady: () => cardFieldReady.value,
    remount,
});
</script>

<template>
    <div class="relative w-full min-w-0">
        <!--
            Overlay enquanto o botão nativo sobe. O host fica montado com tamanho
            real por baixo (sem display:none/sr-only) pra o priming do SDK não travar.
        -->
        <div
            v-if="showWalletPreparing"
            class="absolute inset-x-0 top-0 z-10 flex min-h-[52px] w-full items-center justify-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600 shadow-sm"
            role="status"
            aria-live="polite"
        >
            <svg class="h-4 w-4 shrink-0 animate-spin text-gray-500" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
                <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8v3a5 5 0 00-5 5H4z" />
            </svg>
            <span>{{ walletPreparingLabel }}</span>
        </div>
        <div
            :id="containerId"
            class="cajupay-sdk-host w-full min-w-0 [&_iframe]:max-w-full"
            :class="{
                'min-h-[8rem] animate-pulse rounded-lg bg-gray-50/80': loading && !error && !isWalletMethod,
                'min-h-[52px]': showWalletPreparing,
            }"
            :aria-busy="loading || showWalletPreparing"
        />
        <div v-if="error && primeEnabled" class="mt-2 space-y-1">
            <p class="text-sm text-red-600" role="alert">{{ error }}</p>
            <button
                type="button"
                class="text-xs font-medium text-gray-600 underline decoration-gray-400 hover:text-gray-800"
                @click="remount"
            >
                Tentar carregar novamente
            </button>
        </div>
    </div>
</template>
