/**
 * useCajuPaySdk
 *
 * Helpers para carregar o SDK do checkout CajuPay (CDN) e montar o widget
 * no modo `embeddedOnly`. O backend cria a sessão (`/checkout/cajupay/session`)
 * e devolve o `token` público; o SDK roda no navegador apenas com esse token.
 */

const SDK_URL = 'https://cdn.cajupay.com.br/sdk/v1/cajupay-sdk.min.js';
const SDK_BASE_URL = 'https://api.cajupay.com.br';
/** Bump ao exigir APIs novas do CDN (ex.: mountPixParcelado). */
const SDK_SCRIPT_VERSION = '20260615-parcelado';

let sdkPromise = null;

function cajuPaySdkHasParceladoMount(sdk) {
    if (!sdk) {
        return false;
    }
    if (typeof sdk.mountPixParcelado === 'function') {
        return true;
    }
    if (typeof sdk.init !== 'function') {
        return false;
    }
    try {
        const instance = sdk.init({ baseUrl: SDK_BASE_URL });

        return typeof instance?.mountPixParcelado === 'function';
    } catch (_) {
        return false;
    }
}

function resetCajuPaySdkLoader() {
    sdkPromise = null;
    if (typeof window !== 'undefined') {
        delete window.CajuPaySDK;
    }
    if (typeof document !== 'undefined') {
        document.querySelectorAll(`script[src^="${SDK_URL}"]`).forEach((node) => node.remove());
    }
}

/**
 * Carrega o script do SDK CajuPay (idempotente).
 *
 * @param {{ requireParcelado?: boolean }} [options]
 * @returns {Promise<typeof window.CajuPaySDK>}
 */
export function loadCajuPaySdk(options = {}) {
    const requireParcelado = options.requireParcelado === true;

    if (typeof window === 'undefined') {
        return Promise.reject(new Error('CajuPay SDK só pode ser carregado no navegador.'));
    }
    if (window.CajuPaySDK) {
        if (!requireParcelado || cajuPaySdkHasParceladoMount(window.CajuPaySDK)) {
            return Promise.resolve(window.CajuPaySDK);
        }
        resetCajuPaySdkLoader();
    }

    const scriptSrc = `${SDK_URL}?v=${encodeURIComponent(SDK_SCRIPT_VERSION)}`;
    const expectedScriptSrc = scriptSrc;

    if (sdkPromise) {
        return sdkPromise;
    }

    sdkPromise = new Promise((resolve, reject) => {
        const resolveIfReady = () => {
            if (window.CajuPaySDK && (!requireParcelado || cajuPaySdkHasParceladoMount(window.CajuPaySDK))) {
                resolve(window.CajuPaySDK);
                return true;
            }
            return false;
        };
        const rejectNotReady = () => {
            sdkPromise = null;
            reject(new Error(requireParcelado
                ? 'CajuPay SDK carregado, mas mountPixParcelado() não está disponível. Limpe o cache do navegador.'
                : 'CajuPay SDK carregado, mas window.CajuPaySDK não existe.'));
        };

        const existing = document.querySelector(`script[src^="${SDK_URL}"]`);
        if (existing) {
            const sameVersion = existing.src === expectedScriptSrc || existing.getAttribute('src') === expectedScriptSrc;
            if (sameVersion && existing.readyState === 'complete') {
                if (resolveIfReady()) {
                    return;
                }
                rejectNotReady();
                return;
            }
            existing.remove();
        }

        const script = document.createElement('script');
        script.src = scriptSrc;
        script.async = true;
        script.addEventListener('load', () => {
            if (resolveIfReady()) {
                return;
            }
            rejectNotReady();
        });
        script.addEventListener('error', () => {
            sdkPromise = null;
            reject(new Error('Falha ao carregar o SDK da CajuPay.'));
        });
        document.head.appendChild(script);
    });

    return sdkPromise;
}

/**
 * Monta o checkout SDK em modo `embeddedOnly` no container indicado e
 * retorna o controller (com `.confirm()`, `.setPayer()`).
 *
 * @param {string} containerSelector  Seletor CSS do container (ex.: '#cajupay-method').
 * @param {{ token: string, defaultMethod?: string, initialPayer?: object, baseUrl?: string, onStatus?: (event: any) => void }} opts
 * @returns {Promise<{ confirm: () => Promise<any>, setPayer?: (p: object) => any, [k: string]: any }>}
 */
export async function mountCajuPayCheckout(containerSelector, opts) {
    if (!opts || !opts.token) {
        throw new Error('CajuPay: token público da sessão é obrigatório.');
    }
    const sdk = await loadCajuPaySdk();
    if (!sdk?.init) {
        throw new Error('CajuPay SDK não expõe init().');
    }
    const instance = sdk.init({ baseUrl: opts.baseUrl || SDK_BASE_URL });
    if (!instance?.mountCheckout) {
        throw new Error('CajuPay SDK não expõe mountCheckout().');
    }
    // Omitimos `locale` no mount: o widget herda o locale da sessão criada no servidor
    // (POST /api/sdk/v1/checkout/sessions). Ver doc CajuPay módulo 05 — prioridade.
    return await instance.mountCheckout(containerSelector, {
        token: opts.token,
        defaultMethod: opts.defaultMethod || 'card',
        embeddedOnly: true,
        // O host controla o priming (1ª confirm) em CajuPaySdkMount — evita corrida em
        // que o SDK monta o botão Google Pay antes de confirm-order no Getfy.
        preparePaymentUIOnMount: false,
        initialPayer: opts.initialPayer || undefined,
        onStatus: typeof opts.onStatus === 'function' ? opts.onStatus : undefined,
    });
}

/**
 * Wrapper de `controller.confirm()` que normaliza erros para uma mensagem
 * amigável.
 *
 * @param {{ confirm: () => Promise<any> }} controller
 * @returns {Promise<any>}
 */
export async function confirmCajuPayController(controller) {
    if (!controller || typeof controller.confirm !== 'function') {
        throw new Error('CajuPay: widget não está pronto. Recarregue a página.');
    }
    try {
        return await controller.confirm();
    } catch (err) {
        const msg = err?.message || err?.error || err?.toString?.() || 'Falha ao confirmar pagamento na CajuPay.';
        const e = new Error(msg);
        e.cause = err;
        throw e;
    }
}

/**
 * Normaliza telefone para E.164 (+5511999999999). Retorna undefined se inválido.
 *
 * @param {string|undefined|null} phone
 * @returns {string|undefined}
 */
export function formatCajuPayPhone(phone) {
    if (phone === null || phone === undefined) {
        return undefined;
    }
    const digits = String(phone).replace(/\D/g, '');
    if (digits.length < 8) {
        return undefined;
    }

    return `+${digits}`;
}

/**
 * Monta objeto consumer/payer para o SDK — omite campos vazios para não renderizar inputs extras.
 *
 * @param {{ name?: string, email?: string, document?: string, phone?: string }} source
 * @returns {{ name?: string, email?: string, document?: string, phone?: string }}
 */
export function buildCajuPayConsumer(source) {
    const cleaned = {};
    if (!source || typeof source !== 'object') {
        return cleaned;
    }
    if (typeof source.name === 'string' && source.name.trim() !== '') {
        cleaned.name = source.name.trim();
    }
    if (typeof source.email === 'string' && source.email.trim() !== '') {
        cleaned.email = source.email.trim();
    }
    if (typeof source.document === 'string' && source.document.trim() !== '') {
        cleaned.document = source.document.replace(/\D/g, '');
    }
    const phone = formatCajuPayPhone(source.phone);
    if (phone) {
        cleaned.phone = phone;
    }

    return cleaned;
}

/**
 * Atualiza o pagador (name/email/document/phone) no controller atual do SDK SEM remontar.
 * Indicação oficial CajuPay para fluxo embeddedOnly: chame setPayer() antes do
 * controller.confirm() — assim o SDK envia payer_name / payer_email / payer_document
 * (e phone quando informado) no POST /api/sdk/public/checkout/sessions/{token}/confirm
 * com os dados que o cliente preencheu no SEU formulário.
 *
 * @param {{ setPayer: (payer: object) => any }} controller
 * @param {{ name?: string, email?: string, document?: string, phone?: string }} payer
 */
export function setCajuPayPayer(controller, payer) {
    if (!controller || typeof controller.setPayer !== 'function') {
        // SDK antigo (anterior à atualização que adicionou setPayer). Cai pro fallback
        // de re-mount feito pelo caller. Não joga erro pra não quebrar quem usa CDN
        // sem cache-busting.
        return false;
    }
    const cleaned = buildCajuPayConsumer(payer);
    try {
        controller.setPayer(cleaned);
        return true;
    } catch (_) {
        return false;
    }
}

/**
 * Mapeia o método do Getfy para o nome aceito pelo SDK em `defaultMethod`.
 *
 * @param {string} method  pix|card|apple_pay|google_pay
 * @returns {string}
 */
// IMPORTANTE: deve retornar EXATAMENTE os mesmos slugs que aparecem em
// session.methods_available da CajuPay. O SDK em embeddedOnly: true não mostra seletor
// de método próprio e usa defaultMethod pra escolher o método inicial — se o valor não
// bater com algum item de methods_available, o SDK cai no PRIMEIRO da lista (geralmente
// 'card' porque a regra "Wallets implicam cartão" promove card pra lista). Aí o cliente
// clica em "Google Pay" no nosso UI, mas o SDK monta o formulário de cartão. Doc CajuPay:
// "defaultMethod é obrigatório quando embeddedOnly: true e o pagador escolheu wallet".
export function cajupayDefaultMethodFor(method) {
    switch (method) {
        case 'apple_pay':
            return 'apple_pay';
        case 'google_pay':
            return 'google_pay';
        case 'pix':
            return 'pix';
        case 'pix_parcelado':
            return 'pix_parcelado';
        default:
            return 'card';
    }
}

/**
 * Monta o widget PIX Parcelado (Caju Elements).
 *
 * @param {string} containerSelector
 * @param {{
 *   payAccountId: string,
 *   amountCents: number,
 *   description?: string,
 *   paymentLinkToken?: string,
 *   sdkOptions?: object,
 *   consumer?: object,
 *   baseUrl?: string,
 *   onStatus?: (event: any) => void,
 *   onPlanCreated?: (result: any) => void,
 *   onError?: (event: any) => void,
 * }} opts
 */
export async function mountCajuPayPixParcelado(containerSelector, opts) {
    if (!opts?.payAccountId) {
        throw new Error('CajuPay PIX Parcelado: payAccountId é obrigatório.');
    }
    if (!opts?.amountCents || opts.amountCents < 1) {
        throw new Error('CajuPay PIX Parcelado: amountCents inválido.');
    }
    const sdk = await loadCajuPaySdk({ requireParcelado: true });
    const mountOptions = buildParceladoMountOptions(opts);
    const baseUrl = opts.baseUrl || SDK_BASE_URL;

    if (typeof sdk.init === 'function') {
        const instance = sdk.init({ baseUrl });
        if (typeof instance?.mountPixParcelado === 'function') {
            return await instance.mountPixParcelado(containerSelector, mountOptions);
        }
    }
    if (typeof sdk.mountPixParcelado === 'function') {
        return await sdk.mountPixParcelado(containerSelector, mountOptions);
    }

    throw new Error('CajuPay SDK não expõe mountPixParcelado().');
}

/**
 * Converte opções do backend (parcelado_*) para o formato do SDK embed.
 *
 * @param {Record<string, unknown>} sdkOpts
 * @returns {Record<string, unknown>}
 */
export function normalizeParceladoMountOptions(sdkOpts) {
    if (!sdkOpts || typeof sdkOpts !== 'object') {
        return {};
    }

    const out = { ...sdkOpts };
    const down = out.downPaymentCents ?? out.down_payment_cents ?? out.parcelado_down_payment_cents;
    if (down != null && down !== '' && Number(down) > 0) {
        out.downPaymentCents = Number(down);
    }
    const minBps = out.minDownPaymentBps ?? out.min_down_payment_bps ?? out.parcelado_min_down_payment_bps;
    if (minBps != null && minBps !== '' && Number(minBps) > 0) {
        out.minDownPaymentBps = Number(minBps);
    }
    const maxBps = out.maxDownPaymentBps ?? out.max_down_payment_bps ?? out.parcelado_max_down_payment_bps;
    if (maxBps != null && maxBps !== '' && Number(maxBps) > 0) {
        out.maxDownPaymentBps = Number(maxBps);
    }

    delete out.parcelado_down_payment_cents;
    delete out.parcelado_min_down_payment_bps;
    delete out.parcelado_max_down_payment_bps;
    delete out.parcelado_max_installments;

    return out;
}

function buildParceladoMountOptions(opts) {
    const sdkOpts = normalizeParceladoMountOptions(
        opts.sdkOptions && typeof opts.sdkOptions === 'object' ? opts.sdkOptions : {},
    );
    const mountOpts = {
        payAccountId: opts.payAccountId,
        amountCents: opts.amountCents,
        description: opts.description || 'Compra',
        baseUrl: opts.baseUrl || SDK_BASE_URL,
        embedded: true,
        showBranding: true,
        showSubmitButton: false,
        consumer: buildCajuPayConsumer(opts.consumer || undefined),
        onStatus: typeof opts.onStatus === 'function' ? opts.onStatus : undefined,
        onPlanCreated: typeof opts.onPlanCreated === 'function' ? opts.onPlanCreated : undefined,
        onError: typeof opts.onError === 'function' ? opts.onError : undefined,
        ...sdkOpts,
    };
    if (opts.paymentLinkToken) {
        mountOpts.paymentLinkToken = opts.paymentLinkToken;
    }
    return mountOpts;
}

/**
 * @param {{ setConsumer?: (p: object) => any, setPayer?: (p: object) => any }} controller
 */
export function setCajuPayConsumer(controller, consumer) {
    if (!controller) {
        return false;
    }
    const fn = controller.setConsumer ?? controller.setPayer;
    if (typeof fn !== 'function') {
        return setCajuPayPayer(controller, consumer);
    }
    const cleaned = buildCajuPayConsumer(consumer);
    try {
        fn.call(controller, cleaned);
        return true;
    } catch (_) {
        return false;
    }
}

export async function confirmCajuPayParceladoController(controller) {
    if (!controller || typeof controller.confirm !== 'function') {
        throw new Error('CajuPay PIX Parcelado: widget não está pronto.');
    }
    try {
        return await controller.confirm();
    } catch (err) {
        const msg = err?.message || err?.error || err?.toString?.() || 'Falha ao confirmar PIX Parcelado.';
        const e = new Error(msg);
        e.cause = err;
        throw e;
    }
}
