<script setup>
import { ref, shallowRef, watch, onMounted, onUnmounted, computed, nextTick } from 'vue';
import * as pdfjsLib from 'pdfjs-dist';
import pdfWorkerSrc from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorkerSrc;

const props = defineProps({
    /** Lista `{ url, name }` com URLs absolutas acessíveis ao navegador. */
    files: { type: Array, required: true },
});

const emit = defineEmits(['last-page-reached']);

const canvasRef = ref(null);
const canvasHostRef = ref(null);
const fullscreenRootRef = ref(null);

const loading = ref(true);
const error = ref('');
const globalPage = ref(1);
const totalPages = ref(0);
const pdfDocs = shallowRef([]);
const pageIsLandscape = ref(false);
const isFullscreen = ref(false);

let renderTask = null;
let resizeObserver = null;
let resizeObservedEl = null;

function globalToLocal(globalOneBased) {
    const g = globalOneBased - 1;
    let offset = 0;
    for (const doc of pdfDocs.value) {
        if (g < offset + doc.numPages) {
            return { doc, pageNum: g - offset + 1 };
        }
        offset += doc.numPages;
    }
    const first = pdfDocs.value[0];
    return first ? { doc: first, pageNum: 1 } : { doc: null, pageNum: 1 };
}

async function renderCurrentPage() {
    const canvas = canvasRef.value;
    const host = canvasHostRef.value;
    if (!canvas || !host || !pdfDocs.value.length) return;

    const { doc, pageNum } = globalToLocal(globalPage.value);
    if (!doc) return;

    const page = await doc.getPage(pageNum);
    const outputScale = window.devicePixelRatio || 1;
    const baseViewport = page.getViewport({ scale: 1 });
    pageIsLandscape.value = baseViewport.width > baseViewport.height * 1.05;

    const cw = Math.max(80, host.clientWidth - 16);
    const ch = Math.max(80, host.clientHeight - 16);
    const fit = Math.min(cw / baseViewport.width, ch / baseViewport.height, 8);
    const viewport = page.getViewport({ scale: fit * outputScale });

    const ctx = canvas.getContext('2d');
    if (renderTask) {
        try {
            renderTask.cancel();
        } catch (_) {}
        renderTask = null;
    }

    canvas.width = Math.floor(viewport.width);
    canvas.height = Math.floor(viewport.height);
    canvas.style.width = `${viewport.width / outputScale}px`;
    canvas.style.height = `${viewport.height / outputScale}px`;

    renderTask = page.render({
        canvasContext: ctx,
        viewport,
    });
    try {
        await renderTask.promise;
    } catch (e) {
        if (e?.name !== 'RenderingCancelledException') {
            console.warn(e);
        }
    }
    renderTask = null;
}

async function destroyDocs() {
    for (const d of pdfDocs.value) {
        try {
            await d.destroy();
        } catch (_) {}
    }
    pdfDocs.value = [];
}

async function loadDocuments() {
    loading.value = true;
    error.value = '';
    await destroyDocs();
    totalPages.value = 0;
    globalPage.value = 1;

    const list = (props.files || [])
        .map((f) => ({ url: (f?.url ?? '').toString().trim() }))
        .filter((f) => f.url);
    if (!list.length) {
        loading.value = false;
        return;
    }

    try {
        const docs = [];
        let pages = 0;
        for (const { url } of list) {
            const loadingTask = pdfjsLib.getDocument({ url, withCredentials: false });
            const pdf = await loadingTask.promise;
            docs.push(pdf);
            pages += pdf.numPages;
        }
        pdfDocs.value = docs;
        totalPages.value = pages;
        await nextTick();
        await renderCurrentPage();
    } catch (e) {
        console.error(e);
        error.value =
            'Não foi possível carregar o PDF. Tente atualizar a página ou contacte o suporte se o problema continuar.';
        await destroyDocs();
    } finally {
        loading.value = false;
    }
}

function prevPage() {
    if (globalPage.value <= 1) return;
    globalPage.value -= 1;
    void renderCurrentPage();
}

function nextPage() {
    if (globalPage.value >= totalPages.value) return;
    globalPage.value += 1;
    void renderCurrentPage();
}

async function toggleFullscreen() {
    const el = fullscreenRootRef.value;
    if (!el) return;
    try {
        if (!document.fullscreenElement) {
            await el.requestFullscreen();
            try {
                await screen.orientation?.lock?.('landscape-primary');
            } catch (_) {}
        } else {
            await document.exitFullscreen();
            try {
                screen.orientation?.unlock?.();
            } catch (_) {}
        }
    } catch (_) {}
}

function onFullscreenChange() {
    isFullscreen.value = !!document.fullscreenElement;
    void nextTick().then(() => renderCurrentPage());
}

function onResize() {
    void renderCurrentPage();
}

function onOrientationChange() {
    setTimeout(() => void renderCurrentPage(), 200);
}

function onKeyDown(e) {
    const t = e.target;
    if (t && (t.closest?.('input, textarea, [contenteditable="true"]') || t.isContentEditable)) return;
    if (e.key === 'ArrowLeft') {
        e.preventDefault();
        prevPage();
    } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        nextPage();
    }
}

const showLandscapeHint = computed(() => {
    if (typeof window === 'undefined') return false;
    if (window.innerWidth > window.innerHeight) return false;
    return pageIsLandscape.value && totalPages.value > 0;
});

watch(
    () => props.files,
    () => {
        void loadDocuments();
    },
    { deep: true }
);

watch(globalPage, (p, prev) => {
    if (totalPages.value > 0 && p === totalPages.value && p !== prev) {
        emit('last-page-reached');
    }
});

onMounted(() => {
    document.addEventListener('fullscreenchange', onFullscreenChange);
    window.addEventListener('keydown', onKeyDown);
    window.addEventListener('resize', onResize);
    window.addEventListener('orientationchange', onOrientationChange);
    void loadDocuments();
    nextTick(() => {
        const host = canvasHostRef.value;
        if (host && typeof ResizeObserver !== 'undefined') {
            resizeObservedEl = host;
            resizeObserver = new ResizeObserver(() => void renderCurrentPage());
            resizeObserver.observe(host);
        }
    });
});

onUnmounted(() => {
    document.removeEventListener('fullscreenchange', onFullscreenChange);
    window.removeEventListener('keydown', onKeyDown);
    window.removeEventListener('resize', onResize);
    window.removeEventListener('orientationchange', onOrientationChange);
    if (resizeObserver && resizeObservedEl) {
        try {
            resizeObserver.unobserve(resizeObservedEl);
        } catch (_) {}
    }
    resizeObserver = null;
    resizeObservedEl = null;
    if (renderTask) {
        try {
            renderTask.cancel();
        } catch (_) {}
        renderTask = null;
    }
    void destroyDocs();
});
</script>

<template>
    <div class="member-pdf-presentation flex flex-col gap-2">
        <p
            v-if="showLandscapeHint"
            class="rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-center text-xs text-amber-100"
        >
            Para melhor leitura, use o aparelho em modo paisagem ou a tela cheia.
        </p>

        <div
            ref="fullscreenRootRef"
            class="flex flex-col overflow-hidden rounded-lg border border-zinc-600 bg-zinc-950/80"
        >
            <div
                ref="canvasHostRef"
                class="relative flex min-h-[50vh] w-full items-center justify-center p-3"
            >
                <canvas ref="canvasRef" class="max-h-[85vh] max-w-full" />
                <!-- Metade esquerda: página anterior; metade direita: próxima (só quando o PDF está visível). -->
                <div
                    v-if="!loading && !error && totalPages > 0"
                    class="pointer-events-none absolute inset-3 z-[1] flex"
                >
                    <button
                        type="button"
                        class="pointer-events-auto h-full w-1/2 cursor-w-resize border-0 bg-transparent transition-colors hover:bg-white/[0.06] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ma-primary,#0ea5e9)] focus-visible:ring-offset-2 focus-visible:ring-offset-zinc-950 disabled:pointer-events-none disabled:opacity-0"
                        :disabled="globalPage <= 1"
                        aria-label="Página anterior — clique na metade esquerda da área da apresentação"
                        @click.stop="prevPage"
                    />
                    <button
                        type="button"
                        class="pointer-events-auto h-full w-1/2 cursor-e-resize border-0 bg-transparent transition-colors hover:bg-white/[0.06] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--ma-primary,#0ea5e9)] focus-visible:ring-offset-2 focus-visible:ring-offset-zinc-950 disabled:pointer-events-none disabled:opacity-0"
                        :disabled="globalPage >= totalPages"
                        aria-label="Próxima página — clique na metade direita da área da apresentação"
                        @click.stop="nextPage"
                    />
                </div>
                <div
                    v-if="loading"
                    class="absolute inset-0 z-[2] flex items-center justify-center bg-zinc-950/60 text-sm text-zinc-300"
                >
                    Carregando…
                </div>
                <div
                    v-else-if="error"
                    class="absolute inset-0 z-[2] flex items-center justify-center bg-zinc-950/80 p-4 text-center text-sm text-red-200"
                >
                    {{ error }}
                </div>
            </div>

            <div
                class="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-700 bg-zinc-900/90 px-3 py-2"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="rounded-md border border-zinc-600 px-2 py-1 text-xs font-medium text-zinc-100 hover:bg-zinc-800 disabled:opacity-40"
                        :disabled="globalPage <= 1 || loading || !!error"
                        @click="prevPage"
                    >
                        Anterior
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-zinc-600 px-2 py-1 text-xs font-medium text-zinc-100 hover:bg-zinc-800 disabled:opacity-40"
                        :disabled="globalPage >= totalPages || loading || !!error || totalPages === 0"
                        @click="nextPage"
                    >
                        Próxima
                    </button>
                    <span v-if="totalPages > 0" class="text-xs text-zinc-400">
                        Página {{ globalPage }} de {{ totalPages }}
                    </span>
                </div>
                <button
                    type="button"
                    class="rounded-md border border-zinc-600 px-2 py-1 text-xs font-medium text-zinc-100 hover:bg-zinc-800"
                    @click="toggleFullscreen"
                >
                    {{ isFullscreen ? 'Sair da tela cheia' : 'Tela cheia' }}
                </button>
            </div>
        </div>
    </div>
</template>
