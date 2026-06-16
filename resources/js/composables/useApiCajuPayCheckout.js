import { ref, computed, watch, onBeforeUnmount, nextTick } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';

function getCsrfToken() {
    const match = typeof document !== 'undefined' && document.cookie ? document.cookie.match(/XSRF-TOKEN=([^;]+)/) : null;
    if (match) {
        try {
            return decodeURIComponent(match[1]);
        } catch (_) {}
    }
    return '';
}

/**
 * Fluxo CajuPay SDK no checkout hospedado da API (/api-checkout).
 */
export function useApiCajuPayCheckout(options) {
    const sessionToken = computed(() => options.sessionToken?.value ?? options.sessionToken ?? '');
    const paymentMethod = computed(() => options.paymentMethod?.value ?? options.paymentMethod ?? 'card');
    const displayCurrency = computed(() => options.displayCurrency?.value ?? options.displayCurrency ?? null);
    const customerEmail = computed(() => options.customerEmail?.value ?? options.customerEmail ?? '');
    const customerName = computed(() => options.customerName?.value ?? options.customerName ?? '');
    const customerCpf = computed(() => options.customerCpf?.value ?? options.customerCpf ?? '');
    const customerPhone = computed(() => options.customerPhone?.value ?? options.customerPhone ?? '');
    const onError = options.onError ?? (() => {});

    const cajupaySessionToken = ref('');
    const cajupayPollingToken = ref('');
    const cajupayMountRef = ref(null);
    const cajupayError = ref('');
    const cajupayPolling = ref(false);
    const cajupaySessionLoading = ref(false);
    const cajupayOrderMaterialized = ref(false);
    const cardSubmitting = ref(false);
    let cajupayPollTimer = null;
    let cajupaySessionDebounce = null;

    const isWalletMethod = computed(() =>
        paymentMethod.value === 'apple_pay' || paymentMethod.value === 'google_pay'
    );

    const cajupayPayerReadyForPrime = computed(() => {
        const email = (customerEmail.value || '').trim();
        if (email.length < 5 || !/.+@.+\..+/.test(email)) {
            return false;
        }
        const cpf = (customerCpf.value || '').replace(/\D/g, '');
        if (cpf.length !== 11) {
            return false;
        }
        return true;
    });

    const cajupayInitialPayer = computed(() => ({
        name: (customerName.value || '').trim() || customerEmail.value,
        email: customerEmail.value,
        document: (customerCpf.value || '').replace(/\D/g, ''),
    }));

    function stopCajuPayPolling() {
        if (cajupayPollTimer) {
            clearTimeout(cajupayPollTimer);
            cajupayPollTimer = null;
        }
        cajupayPolling.value = false;
    }

    function resetCajuPaySession() {
        cajupaySessionToken.value = '';
        cajupayPollingToken.value = '';
        cajupayOrderMaterialized.value = false;
        stopCajuPayPolling();
    }

    function buildSessionPayload() {
        const payload = {
            session_token: sessionToken.value,
            payment_method: paymentMethod.value,
        };
        if (displayCurrency.value) {
            payload.display_currency = displayCurrency.value;
        }
        return payload;
    }

    async function ensureCajuPaySession({ silent = false } = {}) {
        if (cajupaySessionToken.value) {
            return cajupaySessionToken.value;
        }
        if (cajupaySessionLoading.value) {
            return null;
        }
        cajupaySessionLoading.value = true;
        if (!silent) {
            cajupayError.value = '';
        }
        try {
            const res = await axios.post('/api-checkout/cajupay/session', buildSessionPayload(), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                withCredentials: true,
            });
            const data = res?.data || {};
            if (!data.success || !data.token) {
                cajupayError.value = data?.message || 'Não foi possível iniciar o pagamento na CajuPay.';
                return null;
            }
            cajupaySessionToken.value = data.token;
            cajupayPollingToken.value = data.polling_token || '';
            if (data.method_supported === false) {
                cajupayError.value = 'Este método não está disponível na sua conta CajuPay.';
            }
            return data.token;
        } catch (e) {
            cajupayError.value = e?.response?.data?.message || e?.message || 'Falha ao iniciar pagamento.';
            return null;
        } finally {
            cajupaySessionLoading.value = false;
        }
    }

    function scheduleEnsureCajuPaySession() {
        if (cajupaySessionDebounce) {
            clearTimeout(cajupaySessionDebounce);
        }
        cajupaySessionDebounce = setTimeout(() => {
            ensureCajuPaySession({ silent: true });
        }, 300);
    }

    async function postCajuPayConfirmOrder() {
        const pollingToken = cajupayPollingToken.value;
        if (!pollingToken) {
            throw new Error('Sessão de pagamento não iniciada.');
        }
        const orderRes = await axios.post(
            '/api-checkout/cajupay/confirm-order',
            {
                session_token: sessionToken.value,
                polling_token: pollingToken,
                email: customerEmail.value,
                name: customerName.value || customerEmail.value,
                cpf: (customerCpf.value || '').replace(/\D/g, ''),
                phone: customerPhone.value || '',
            },
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                withCredentials: true,
            }
        );
        const orderData = orderRes?.data || {};
        if (!orderData.success) {
            throw new Error(orderData?.message || 'Não foi possível registrar o pedido.');
        }
        return orderData;
    }

    async function pollCajuPayOrderStatus() {
        if (!cajupayPollingToken.value) {
            return;
        }
        try {
            const res = await axios.get('/api-checkout/order-status', {
                params: { token: cajupayPollingToken.value },
                headers: { Accept: 'application/json' },
                withCredentials: true,
            });
            const data = res?.data || {};
            if (data.status === 'completed' && data.redirect_url) {
                stopCajuPayPolling();
                router.visit(data.redirect_url);
                return;
            }
            if (['rejected', 'cancelled', 'failed'].includes(data.status)) {
                stopCajuPayPolling();
                cajupayError.value = 'Pagamento recusado. Tente novamente ou use outro método.';
                onError({ payment: [cajupayError.value] });
                return;
            }
        } catch (_) {
            /* retry */
        }
        cajupayPollTimer = setTimeout(pollCajuPayOrderStatus, 3000);
    }

    function startCajuPayPolling(token) {
        if (!token) {
            return;
        }
        cajupayPollingToken.value = token;
        stopCajuPayPolling();
        cajupayPolling.value = true;
        cajupayPollTimer = setTimeout(pollCajuPayOrderStatus, 2500);
    }

    async function beforeCajuPayWalletPrime() {
        if (!isWalletMethod.value) {
            return;
        }
        if (cajupayOrderMaterialized.value) {
            return;
        }
        if (!cajupayPayerReadyForPrime.value) {
            throw new Error('CPF e e-mail do comprador são obrigatórios para usar a carteira digital.');
        }
        await postCajuPayConfirmOrder();
        cajupayOrderMaterialized.value = true;
        if (cajupayPollingToken.value) {
            startCajuPayPolling(cajupayPollingToken.value);
        }
    }

    async function onCajuPayWalletPaymentCompleted() {
        if (!cajupayOrderMaterialized.value) {
            try {
                await postCajuPayConfirmOrder();
                cajupayOrderMaterialized.value = true;
            } catch (e) {
                cajupayError.value = e?.response?.data?.message || e?.message || 'Falha ao registrar pedido.';
                return;
            }
        }
        if (!cajupayPolling.value && cajupayPollingToken.value) {
            startCajuPayPolling(cajupayPollingToken.value);
        }
    }

    async function submitCajuPaySdkFlow() {
        cajupayError.value = '';
        if (!cajupayPayerReadyForPrime.value) {
            cajupayError.value = 'Informe CPF e e-mail válidos na sessão de checkout.';
            onError({ cpf: [cajupayError.value] });
            return;
        }
        cardSubmitting.value = true;
        try {
            let token = cajupaySessionToken.value;
            if (!token) {
                await ensureCajuPaySession();
                token = cajupaySessionToken.value;
                if (!token) {
                    throw new Error(cajupayError.value || 'Não foi possível iniciar o pagamento.');
                }
                await nextTick();
                const start = Date.now();
                while (!cajupayMountRef.value?.isReady?.() && Date.now() - start < 8000) {
                    await new Promise((r) => setTimeout(r, 150));
                }
            }
            if (!cajupayMountRef.value?.isReady?.()) {
                throw new Error('Aguarde o checkout CajuPay carregar.');
            }
            if (!cajupayOrderMaterialized.value) {
                await postCajuPayConfirmOrder();
                cajupayOrderMaterialized.value = true;
            }
            cajupayMountRef.value?.setPayer?.({
                name: customerName.value || customerEmail.value,
                email: customerEmail.value,
                document: (customerCpf.value || '').replace(/\D/g, ''),
                phone: customerPhone.value || undefined,
            });
            await cajupayMountRef.value.confirm();
            if (!cajupayPolling.value && cajupayPollingToken.value) {
                startCajuPayPolling(cajupayPollingToken.value);
            }
        } catch (e) {
            const msg = e?.response?.data?.message || e?.message || 'Falha ao processar pagamento.';
            cajupayError.value = msg;
            onError({ payment: [msg] });
        } finally {
            cardSubmitting.value = false;
        }
    }

    watch(paymentMethod, () => {
        resetCajuPaySession();
        scheduleEnsureCajuPaySession();
    });

    watch(displayCurrency, () => {
        resetCajuPaySession();
        scheduleEnsureCajuPaySession();
    });

    onBeforeUnmount(() => {
        stopCajuPayPolling();
        if (cajupaySessionDebounce) {
            clearTimeout(cajupaySessionDebounce);
        }
    });

    return {
        cajupaySessionToken,
        cajupayPollingToken,
        cajupayMountRef,
        cajupayError,
        cajupayPolling,
        cajupaySessionLoading,
        cajupayOrderMaterialized,
        cardSubmitting,
        isWalletMethod,
        cajupayPayerReadyForPrime,
        cajupayInitialPayer,
        resetCajuPaySession,
        ensureCajuPaySession,
        scheduleEnsureCajuPaySession,
        submitCajuPaySdkFlow,
        beforeCajuPayWalletPrime,
        onCajuPayWalletPaymentCompleted,
    };
}
