/**
 * Injeta CSS, HTML no <head> e JS no <body> a partir de checkout_config.advanced.
 * Limpeza automática ao desmontar ou ao esvaziar campos.
 */

import { watch, onUnmounted } from 'vue';

const STYLE_ID = 'getfy-checkout-custom-css';
const SCRIPT_ID = 'getfy-checkout-custom-js';

/**
 * @param {import('vue').Ref<object>|import('vue').ComputedRef<object>} advancedSource ex.: computed(() => effectiveConfig.value?.advanced ?? {})
 */
export function useCheckoutCustomCode(advancedSource) {
    /** @type {ChildNode[]} */
    let headInjectedNodes = [];

    function clearHeadInjected() {
        headInjectedNodes.forEach((n) => {
            try {
                n.parentNode?.removeChild(n);
            } catch (_) {
                /* ignore */
            }
        });
        headInjectedNodes = [];
    }

    function applyCss(css) {
        if (typeof document === 'undefined') return;
        let el = document.getElementById(STYLE_ID);
        const t = (css || '').trim();
        if (!t) {
            if (el) el.remove();
            return;
        }
        if (!el) {
            el = document.createElement('style');
            el.id = STYLE_ID;
            document.head.appendChild(el);
        }
        el.textContent = css;
    }

    function applyHeadHtml(html) {
        clearHeadInjected();
        if (typeof document === 'undefined') return;
        const t = (html || '').trim();
        if (!t) return;
        const tpl = document.createElement('template');
        tpl.innerHTML = t;
        const nodes = Array.from(tpl.content.childNodes);
        nodes.forEach((node) => {
            document.head.appendChild(node);
            headInjectedNodes.push(node);
        });
    }

    function applyJs(js) {
        if (typeof document === 'undefined') return;
        const old = document.getElementById(SCRIPT_ID);
        if (old) old.remove();
        const t = (js || '').trim();
        if (!t) return;
        const s = document.createElement('script');
        s.id = SCRIPT_ID;
        s.setAttribute('data-getfy-custom', '1');
        s.textContent = js;
        document.body.appendChild(s);
    }

    function applyAll(adv) {
        const a = adv && typeof adv === 'object' ? adv : {};
        applyCss(a.custom_css);
        applyHeadHtml(a.custom_head_html);
        applyJs(a.custom_js);
    }

    watch(
        advancedSource,
        (adv) => applyAll(adv ?? {}),
        { deep: true, immediate: true }
    );

    onUnmounted(() => {
        applyCss('');
        clearHeadInjected();
        if (typeof document !== 'undefined') {
            document.getElementById(SCRIPT_ID)?.remove();
        }
    });
}
