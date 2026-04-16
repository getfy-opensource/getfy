<script setup>
import { onMounted, onUnmounted, watch } from 'vue';

const props = defineProps({
    pixels: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['ready']);

/** Evita reinicializar quando props.pixels oscila com o mesmo conteúdo. */
let lastPixelsFingerprint = '';

let gtagExternalScriptInserted = false;
const gtagConfiguredIds = new Set();
const metaInitedPixelIds = new Set();
const tiktokLoadedPixelIds = new Set();

/** Permite apenas IDs alfanuméricos, hífen e underscore para evitar XSS. */
function isValidPixelId(id) {
    if (typeof id !== 'string' || id.length > 64) return false;
    return /^[a-zA-Z0-9_-]+$/.test(id);
}

function fingerprintPixels(pixels) {
    try {
        return JSON.stringify({
            meta: pixels?.meta,
            tiktok: pixels?.tiktok,
            google_ads: pixels?.google_ads,
            google_analytics: pixels?.google_analytics,
            custom_script: (pixels?.custom_script ?? []).map((x) => x?.id),
        });
    } catch {
        return '';
    }
}

function getMetaEntries(p) {
    const m = p?.meta;
    if (!m?.enabled) return [];
    if (Array.isArray(m.entries)) {
        return m.entries.filter((e) => e && isValidPixelId(String(e.pixel_id || '').trim()));
    }
    if (m.pixel_id && isValidPixelId(String(m.pixel_id).trim())) {
        return [m];
    }
    return [];
}

function getTiktokEntries(p) {
    const m = p?.tiktok;
    if (!m?.enabled) return [];
    if (Array.isArray(m.entries)) {
        return m.entries.filter((e) => e && isValidPixelId(String(e.pixel_id || '').trim()));
    }
    if (m.pixel_id && isValidPixelId(String(m.pixel_id).trim())) {
        return [m];
    }
    return [];
}

function getGoogleAdsEntries(p) {
    const m = p?.google_ads;
    if (!m?.enabled) return [];
    if (Array.isArray(m.entries)) {
        return m.entries.filter((e) => e && isValidPixelId(String(e.conversion_id || '').trim()));
    }
    if (m.conversion_id && isValidPixelId(String(m.conversion_id).trim())) {
        return [m];
    }
    return [];
}

function getGaEntries(p) {
    const m = p?.google_analytics;
    if (!m?.enabled) return [];
    if (Array.isArray(m.entries)) {
        return m.entries.filter((e) => e && isValidPixelId(String(e.measurement_id || '').trim()));
    }
    if (m.measurement_id && isValidPixelId(String(m.measurement_id).trim())) {
        return [m];
    }
    return [];
}

const META_FBEvents_URL = 'https://connect.facebook.net/en_US/fbevents.js';

/** Evita segundo <script> se o checkout ou um script personalizado já carregou fbevents.js. */
function findExistingFbeventsScript() {
    const tagged = document.querySelector('script[data-getfy-fbevents="1"]');
    if (tagged) return tagged;
    const scripts = document.querySelectorAll('script[src]');
    for (let i = 0; i < scripts.length; i++) {
        const el = scripts[i];
        try {
            const u = new URL(el.getAttribute('src') || '', location.href);
            if (u.hostname === 'connect.facebook.net' && u.pathname.includes('fbevents')) {
                return el;
            }
        } catch {
            /* ignore */
        }
    }
    return null;
}

/**
 * Base do Meta Pixel: fila `fbq` antes de fbevents.js executar.
 * Só carregar o script externo sem isto provoca ReferenceError dentro de fbevents.js (fbq is not defined).
 * @see https://developers.facebook.com/docs/meta-pixel/get-started
 */
function ensureMetaFbqStub() {
    if (typeof window.fbq === 'function') return;
    const f = window;
    const n = (f.fbq = function () {
        if (n.callMethod) {
            n.callMethod.apply(n, arguments);
        } else {
            n.queue.push(arguments);
        }
    });
    if (!f._fbq) f._fbq = n;
    n.push = n;
    n.loaded = false;
    n.version = '2.0';
    n.queue = [];
}

function injectMetaLibAndInit(metaEntries) {
    const ids = metaEntries.map((e) => String(e.pixel_id).trim()).filter((id) => id && isValidPixelId(id));
    if (!ids.length) return;

    const runInits = () => {
        if (typeof window.fbq !== 'function') return;
        ids.forEach((id) => {
            if (!metaInitedPixelIds.has(id)) {
                window.fbq('init', id);
                metaInitedPixelIds.add(id);
            }
        });
        window.fbq('track', 'PageView');
    };

    /** Pixel já hidratado pelo script (não é só o stub com loaded === false). */
    const fbqReady = typeof window.fbq === 'function' && window.fbq.loaded === true;
    if (fbqReady) {
        runInits();
        return;
    }

    ensureMetaFbqStub();

    const existing = findExistingFbeventsScript();
    if (existing) {
        runInits();
        return;
    }

    const s = document.createElement('script');
    s.async = true;
    s.src = META_FBEvents_URL;
    s.setAttribute('data-getfy-fbevents', '1');
    s.onerror = () => {
        if (import.meta.env.DEV) {
            console.warn(
                '[Getfy][Meta Pixel] Falha ao carregar fbevents.js. Causas comuns: extensão bloqueando connect.facebook.net (ERR_BLOCKED_BY_CLIENT), rede ou firewall.'
            );
        }
    };
    document.head.appendChild(s);
    runInits();
}

function injectTiktokWithFirstPixel(pixelId) {
    const s = document.createElement('script');
    s.async = true;
    s.innerHTML = `!function (w, d, t) { w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var i="https://analytics.tiktok.com/i18n/pixel/events.js";ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=i,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};var o=document.createElement("script");o.type="text/javascript",o.async=!0,o.src=i+"?sdkid="+e+"&lib="+t;var a=document.getElementsByTagName("script")[0];a.parentNode.insertBefore(o,a)}; ttq.load('${pixelId}'); ttq.page(); }(window, document, 'ttq');`;
    document.head.appendChild(s);
}

function setupTiktokPixels(entries) {
    const ids = entries.map((e) => String(e.pixel_id).trim()).filter((id) => id && isValidPixelId(id));
    if (!ids.length) return;

    const loadRemaining = () => {
        if (typeof window.ttq?.load !== 'function') return;
        ids.forEach((id) => {
            if (!tiktokLoadedPixelIds.has(id)) {
                window.ttq.load(id);
                window.ttq.page();
                tiktokLoadedPixelIds.add(id);
            }
        });
    };

    if (typeof window.ttq?.load === 'function') {
        loadRemaining();
        return;
    }

    injectTiktokWithFirstPixel(ids[0]);
    tiktokLoadedPixelIds.add(ids[0]);

    const deadline = Date.now() + 10000;
    const iv = setInterval(() => {
        if (typeof window.ttq?.load === 'function') {
            clearInterval(iv);
            for (let i = 1; i < ids.length; i++) {
                const id = ids[i];
                if (!tiktokLoadedPixelIds.has(id)) {
                    window.ttq.load(id);
                    window.ttq.page();
                    tiktokLoadedPixelIds.add(id);
                }
            }
        } else if (Date.now() > deadline) {
            clearInterval(iv);
        }
    }, 40);
}

function setupGtag(pixels) {
    const ads = getGoogleAdsEntries(pixels);
    const ga = getGaEntries(pixels);
    const adIds = ads.map((e) => String(e.conversion_id).trim()).filter((id) => id && isValidPixelId(id));
    const gaIds = ga.map((e) => String(e.measurement_id).trim()).filter((id) => id && isValidPixelId(id));
    const allIds = [...adIds, ...gaIds];
    if (!allIds.length) return;

    const first = allIds[0];

    if (!gtagExternalScriptInserted) {
        window.dataLayer = window.dataLayer || [];
        const s = document.createElement('script');
        s.async = true;
        s.src = `https://www.googletagmanager.com/gtag/js?id=${first}`;
        document.head.appendChild(s);
        const inline = document.createElement('script');
        inline.innerHTML = 'window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag("js", new Date());';
        document.head.appendChild(inline);
        gtagExternalScriptInserted = true;
    }

    allIds.forEach((id) => {
        if (!gtagConfiguredIds.has(id)) {
            window.gtag('config', id);
            gtagConfiguredIds.add(id);
        }
    });
}

/** Domínios permitidos para script src em pixels customizados (evita XSS). */
const ALLOWED_SCRIPT_ORIGINS = ['https://www.googletagmanager.com', 'https://connect.facebook.net', 'https://analytics.tiktok.com', 'https://js.stripe.com'];

function isAllowedScriptSrc(src) {
    if (!src || typeof src !== 'string') return false;
    try {
        const u = new URL(src, location.origin);
        return ALLOWED_SCRIPT_ORIGINS.some((origin) => u.origin === origin || u.href.startsWith(origin + '/'));
    } catch {
        return false;
    }
}

function injectCustomScripts() {
    const items = props.pixels?.custom_script ?? [];
    if (!Array.isArray(items)) return;
    items.forEach((item) => {
        if (!item?.script || typeof item.script !== 'string') return;
        const s = document.createElement('div');
        s.innerHTML = item.script;
        const scripts = s.querySelectorAll('script');
        scripts.forEach((script) => {
            if (script.src && !isAllowedScriptSrc(script.src)) return;
            const newScript = document.createElement('script');
            if (script.src) newScript.src = script.src;
            if (script.innerHTML) newScript.innerHTML = script.innerHTML;
            newScript.async = script.async ?? true;
            document.head.appendChild(newScript);
        });
        const nonScripts = s.childNodes;
        nonScripts.forEach((node) => {
            if (node.nodeType === 1 && node.tagName !== 'SCRIPT') {
                document.head.appendChild(node.cloneNode(true));
            }
        });
    });
}

function init() {
    const p = props.pixels || {};
    const fp = fingerprintPixels(p);
    if (fp === lastPixelsFingerprint) return;
    lastPixelsFingerprint = fp;

    metaInitedPixelIds.clear();
    tiktokLoadedPixelIds.clear();
    gtagConfiguredIds.clear();

    const metaEntries = getMetaEntries(p);
    if (metaEntries.length) {
        injectMetaLibAndInit(metaEntries);
    }

    const tiktokEntries = getTiktokEntries(p);
    if (tiktokEntries.length) {
        setupTiktokPixels(tiktokEntries);
    }

    setupGtag(p);

    injectCustomScripts();

    emit('ready');
}

/**
 * Adia a injeção de scripts de terceiros para depois do próximo paint (2× rAF + macrotask), sem competir com FCP/LCP/hidratação.
 * Não usa requestIdleCallback: pode atrasar demais e interagir mal com extensões; o stub fbq + fila já torna o Meta Pixel seguro.
 */
function scheduleDeferredInit(run) {
    if (typeof window === 'undefined') {
        run();
        return;
    }
    const w = window;
    w.requestAnimationFrame(() => {
        w.requestAnimationFrame(() => {
            w.setTimeout(run, 0);
        });
    });
}

onMounted(() => scheduleDeferredInit(init));
watch(() => props.pixels, () => scheduleDeferredInit(init), { deep: true });

function shouldFireForEntry(entry, triggerType, isOrderBump) {
    if (isOrderBump && entry?.disable_order_bump_events) return false;
    if (triggerType === 'pix' && entry?.fire_purchase_on_pix === false) return false;
    if (triggerType === 'boleto' && entry?.fire_purchase_on_boleto === false) return false;
    return true;
}

defineExpose({
    fireInitiateCheckout(value, currency = 'BRL', extras = {}) {
        const p = props.pixels || {};
        const num = Number(value) || 0;
        const cur = typeof currency === 'string' && currency.trim() ? currency.trim().toUpperCase() : 'BRL';
        const payload = {
            value: num,
            currency: cur,
            ...(extras && typeof extras === 'object' ? extras : {}),
        };

        if (p.meta?.enabled && window.fbq) {
            getMetaEntries(p).forEach((entry) => {
                if (!entry.pixel_id) return;
                window.fbq('track', 'InitiateCheckout', payload);
            });
        }
    },
    firePurchase(value, currency = 'BRL', orderId = '', isOrderBump = false, triggerType = 'approved') {
        const p = props.pixels || {};
        const num = Number(value) || 0;

        if (p.meta?.enabled && window.fbq) {
            getMetaEntries(p).forEach((entry) => {
                if (!entry.pixel_id || !shouldFireForEntry(entry, triggerType, isOrderBump)) return;
                window.fbq('track', 'Purchase', { value: num, currency, content_ids: orderId ? [orderId] : [] });
            });
        }
        if (p.tiktok?.enabled && window.ttq?.track) {
            getTiktokEntries(p).forEach((entry) => {
                if (!entry.pixel_id || !shouldFireForEntry(entry, triggerType, isOrderBump)) return;
                window.ttq.track('CompletePayment', { value: num, currency, content_id: orderId });
            });
        }
        if (p.google_ads?.enabled && window.gtag) {
            getGoogleAdsEntries(p).forEach((entry) => {
                if (!entry.conversion_id || !shouldFireForEntry(entry, triggerType, isOrderBump)) return;
                const sendTo = `${String(entry.conversion_id).trim()}/${String(entry.conversion_label || '').trim()}`.replace(/\/+$/, '');
                window.gtag('event', 'conversion', {
                    send_to: sendTo,
                    value: num,
                    currency,
                    transaction_id: orderId,
                });
            });
        }
        if (p.google_analytics?.enabled && window.gtag) {
            getGaEntries(p).forEach((entry) => {
                if (!entry.measurement_id || !shouldFireForEntry(entry, triggerType, isOrderBump)) return;
                window.gtag('event', 'purchase', {
                    send_to: String(entry.measurement_id).trim(),
                    value: num,
                    currency,
                    transaction_id: orderId,
                });
            });
        }
    },
});
</script>

<template>
    <div class="hidden" aria-hidden="true" data-checkout="conversion-pixels" />
</template>
