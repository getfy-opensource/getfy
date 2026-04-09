<script setup>
import { ref, shallowRef, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { EditorView, basicSetup } from 'codemirror';
import { EditorState } from '@codemirror/state';
import { css } from '@codemirror/lang-css';
import { javascript } from '@codemirror/lang-javascript';
import { html } from '@codemirror/lang-html';
import { autocompletion, completeFromList } from '@codemirror/autocomplete';
import { checkoutDomCompletions } from '@/checkout-builder/checkoutDomHints';

const props = defineProps({
    modelValue: { type: String, default: '' },
    /** @type {'css' | 'javascript' | 'html'} */
    language: { type: String, default: 'css' },
    minHeight: { type: String, default: '220px' },
});

const emit = defineEmits(['update:modelValue']);

const host = ref(null);
const viewRef = shallowRef(null);

/** Alinha o facet de tema do CodeMirror com `html.dark` (tooltips usam &light/&dark). */
const isAppDark = ref(false);

function syncAppDarkFromDocument() {
    if (typeof document === 'undefined') {
        return;
    }
    const next = document.documentElement.classList.contains('dark');
    if (next === isAppDark.value) {
        return;
    }
    isAppDark.value = next;
    if (viewRef.value) {
        destroyEditor();
        nextTick(() => createEditor());
    }
}

let darkClassObserver = null;

function langExtension() {
    if (props.language === 'javascript') {
        return javascript();
    }
    if (props.language === 'html') {
        return html();
    }
    return css();
}

const checkoutHints = autocompletion({
    override: [completeFromList(checkoutDomCompletions)],
});

function buildExtensions() {
    return [
        basicSetup,
        langExtension(),
        checkoutHints,
        EditorView.theme(
            {
                '&': { height: props.minHeight },
                '.cm-scroller': { overflow: 'auto' },
                '.cm-content': { fontFamily: 'ui-monospace, monospace', fontSize: '13px' },
            },
            { dark: isAppDark.value },
        ),
        EditorView.updateListener.of((u) => {
            if (u.docChanged) {
                emit('update:modelValue', u.state.doc.toString());
            }
        }),
    ];
}

function createEditor() {
    if (!host.value) {
        return;
    }
    const start = props.modelValue ?? '';
    const state = EditorState.create({
        doc: start,
        extensions: buildExtensions(),
    });
    const view = new EditorView({ state, parent: host.value });
    viewRef.value = view;
}

function destroyEditor() {
    viewRef.value?.destroy();
    viewRef.value = null;
}

onMounted(() => {
    syncAppDarkFromDocument();
    darkClassObserver = new MutationObserver(() => syncAppDarkFromDocument());
    darkClassObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    });
    nextTick(() => createEditor());
});

watch(
    () => props.modelValue,
    (v) => {
        const view = viewRef.value;
        if (!view) {
            return;
        }
        const cur = view.state.doc.toString();
        if (v !== cur) {
            view.dispatch({
                changes: { from: 0, to: view.state.doc.length, insert: v ?? '' },
            });
        }
    }
);

watch(
    () => props.language,
    () => {
        destroyEditor();
        nextTick(() => createEditor());
    }
);

onBeforeUnmount(() => {
    darkClassObserver?.disconnect();
    darkClassObserver = null;
    destroyEditor();
});
</script>

<template>
    <div
        class="checkout-code-editor overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-600 dark:bg-zinc-900"
    >
        <div ref="host" class="min-h-[120px]" />
    </div>
</template>
