<script setup>
import { computed, ref, watch } from 'vue';
import Button from '@/components/ui/Button.vue';
import { VueFlow, useVueFlow, MarkerType, Handle, Position, BaseEdge, EdgeLabelRenderer, getBezierPath } from '@vue-flow/core';
import { Background } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import { Trash2 } from 'lucide-vue-next';

import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import '@vue-flow/controls/dist/style.css';

const props = defineProps({
    flow: { type: Object, required: true },
});
const emit = defineEmits(['save', 'close']);

function clone(v) {
    return JSON.parse(JSON.stringify(v || {}));
}

function ensureId(prefix) {
    return `${prefix}_${Math.random().toString(16).slice(2, 10)}`;
}

const nodes = ref([]);
const edges = ref([]);

const selectedNodeId = ref(null);
const selectedEdgeId = ref(null);

const { project, onConnect, addEdges, fitView } = useVueFlow();

function nodeLabel(type) {
    if (type === 'trigger') return 'Gatilho';
    if (type === 'send_message') return 'Enviar mensagem';
    if (type === 'delay') return 'Aguardar';
    if (type === 'condition') return 'Condição';
    if (type === 'end') return 'Fim';
    return type;
}

function defaultDataFor(type) {
    if (type === 'send_message') return { mode: 'text', text: 'Olá {{customer.name}}!' };
    if (type === 'delay') return { seconds: 30 };
    if (type === 'condition') return { kind: 'has_phone' };
    return {};
}

function subtitleFor(type, data) {
    const d = isObject(data) ? data : {};
    if (type === 'send_message') {
        const mode = (d.mode || 'text') === 'media' ? 'Mídia' : (d.mode || 'text') === 'interactive' ? 'Interativo' : 'Texto';
        const text = typeof d.text === 'string' ? d.text.trim() : '';
        const preview = text ? text.replace(/\s+/g, ' ').slice(0, 42) : '';
        return preview ? `${mode} • ${preview}${text.length > 42 ? '…' : ''}` : mode;
    }
    if (type === 'delay') {
        const s = Number.isFinite(d.seconds) ? d.seconds : parseInt(d.seconds || 0, 10) || 0;
        return `Aguardar ${Math.max(0, s)}s`;
    }
    if (type === 'condition') {
        const kind = String(d.kind || 'has_phone');
        if (kind === 'has_phone') return 'Cliente tem telefone?';
        if (kind === 'payment_method_is') return `Pagamento é: ${String(d.value || '').trim() || '…'}`;
        if (kind === 'event_is') return `Evento é: ${String(d.value || '').trim() || '…'}`;
        return 'Condição';
    }
    return '';
}

function syncPresentation(n) {
    if (!n || !n.data) return;
    const sub = subtitleFor(n.type, n.data);
    if (sub) n.data.subtitle = sub;
    else if (n.data.subtitle) delete n.data.subtitle;
    n.data._label = nodeLabel(n.type);
}

function fromStoredGraph(graphJson) {
    const g = isObject(graphJson) ? graphJson : {};
    const storedNodes = Array.isArray(g.nodes) ? g.nodes : [];
    const storedEdges = Array.isArray(g.edges) ? g.edges : [];

    const vfNodes = storedNodes
        .filter((n) => isObject(n) && typeof n.id === 'string')
        .map((n, idx) => {
            const type = typeof n.type === 'string' ? n.type : 'custom';
            const x = typeof n.x === 'number' ? n.x : 80 + (idx % 4) * 220;
            const y = typeof n.y === 'number' ? n.y : 80 + Math.floor(idx / 4) * 150;
            const node = {
                id: n.id,
                type,
                position: { x, y },
                data: { ...(isObject(n.data) ? n.data : {}), _label: nodeLabel(type) },
                draggable: n.id !== 'trigger',
            };
            syncPresentation(node);
            return node;
        });

    // If many nodes share the same position (legacy graphs), spread them a bit.
    const byPos = new Map();
    for (const n of vfNodes) {
        const key = `${Math.round(n.position.x)}:${Math.round(n.position.y)}`;
        byPos.set(key, (byPos.get(key) || 0) + 1);
    }
    if ([...byPos.values()].some((c) => c > 1)) {
        let bump = 0;
        for (const n of vfNodes) {
            const key = `${Math.round(n.position.x)}:${Math.round(n.position.y)}`;
            const count = byPos.get(key) || 0;
            if (count <= 1) continue;
            bump++;
            n.position = { x: n.position.x + bump * 40, y: n.position.y + (bump % 2) * 22 };
        }
    }

    // Back-compat: edges can be stored as {from,to} (engine format) or {source,target} (vue-flow)
    const vfEdges = storedEdges
        .filter((e) => isObject(e))
        .map((e, idx) => {
            const source = typeof e.source === 'string' ? e.source : (typeof e.from === 'string' ? e.from : '');
            const target = typeof e.target === 'string' ? e.target : (typeof e.to === 'string' ? e.to : '');
            if (!source || !target) return null;
            const data = isObject(e.data) ? e.data : {};
            const condition = typeof data.condition === 'string' ? data.condition : null;
            return {
                id: typeof e.id === 'string' ? e.id : `${source}->${target}:${idx}`,
                source,
                target,
                type: 'bezier',
                markerEnd: MarkerType.ArrowClosed,
                data,
                label: condition === 'true' ? 'SIM' : condition === 'false' ? 'NÃO' : undefined,
                animated: true,
                style: { strokeDasharray: '6 6' },
            };
        })
        .filter(Boolean);

    // Ensure there is a trigger node (non deletable)
    if (!vfNodes.some((n) => n.id === 'trigger')) {
        const t = {
            id: 'trigger',
            type: 'trigger',
            position: { x: 80, y: 80 },
            data: { _label: nodeLabel('trigger') },
            draggable: false,
        };
        syncPresentation(t);
        vfNodes.unshift(t);
    }

    return { vfNodes, vfEdges };
}

function toStoredGraph(vfNodes, vfEdges) {
    const storedNodes = (vfNodes || []).map((n) => ({
        id: n.id,
        type: n.type,
        x: Math.round(n.position?.x ?? 0),
        y: Math.round(n.position?.y ?? 0),
        data: sanitizeNodeData(n.data),
    }));
    const storedEdges = (vfEdges || []).map((e) => ({
        from: e.source,
        to: e.target,
        data: isObject(e.data) ? e.data : undefined,
    }));
    return { nodes: storedNodes, edges: storedEdges };
}

function sanitizeNodeData(data) {
    if (!isObject(data)) return {};
    const d = { ...data };
    delete d._label;
    return d;
}

function isObject(v) {
    return !!v && typeof v === 'object' && !Array.isArray(v);
}

function loadFromProps() {
    const { vfNodes, vfEdges } = fromStoredGraph(props.flow?.graph_json);
    nodes.value = vfNodes;
    edges.value = vfEdges;
    selectedNodeId.value = null;
    selectedEdgeId.value = null;
    // nice initial view
    setTimeout(() => fitView({ padding: 0.18, duration: 200 }), 0);
}

watch(
    () => props.flow?.id,
    () => loadFromProps(),
    { immediate: true }
);

watch(
    nodes,
    (list) => {
        // Keep node title/subtitle in sync while editing
        for (const n of list || []) syncPresentation(n);
    },
    { deep: true }
);

onConnect((params) => {
    addEdges([
        {
            ...params,
            type: 'autozap',
            markerEnd: MarkerType.ArrowClosed,
            data: {},
            animated: true,
            style: { strokeDasharray: '6 6' },
        },
    ]);
});

const selectedNode = computed(() => nodes.value.find((n) => n.id === selectedNodeId.value) || null);
const selectedEdge = computed(() => edges.value.find((e) => e.id === selectedEdgeId.value) || null);
const showInspector = computed(() => !!selectedNode.value || !!selectedEdge.value);

function selectNode(id) {
    selectedNodeId.value = id || null;
    selectedEdgeId.value = null;
}

function selectEdge(id) {
    selectedEdgeId.value = id || null;
    selectedNodeId.value = null;
}

function clearSelection() {
    selectedNodeId.value = null;
    selectedEdgeId.value = null;
}

function onNodeClick(a, b) {
    const node = (b && typeof b === 'object') ? b : (a && typeof a === 'object' ? (a.node || a) : null);
    selectNode(node?.id || null);
}

function onEdgeClick(a, b) {
    const edge = (b && typeof b === 'object') ? b : (a && typeof a === 'object' ? (a.edge || a) : null);
    selectEdge(edge?.id || null);
}

function addNode(type, position) {
    const id = type === 'trigger' ? 'trigger' : ensureId(type);
    if (type === 'trigger' && nodes.value.some((n) => n.id === 'trigger')) return;
    const n = {
        id,
        type,
        position: position || { x: 240, y: 120 },
        data: { ...defaultDataFor(type), _label: nodeLabel(type) },
        draggable: id !== 'trigger',
    };
    syncPresentation(n);
    nodes.value.push(n);
    selectedNodeId.value = id;
    selectedEdgeId.value = null;
}

function deleteSelectedNode() {
    if (!selectedNode.value) return;
    if (selectedNode.value.id === 'trigger') return;
    const id = selectedNode.value.id;
    nodes.value = nodes.value.filter((n) => n.id !== id);
    edges.value = edges.value.filter((e) => e.source !== id && e.target !== id);
    selectedNodeId.value = null;
}

function deleteSelectedEdge() {
    if (!selectedEdge.value) return;
    const id = selectedEdge.value.id;
    edges.value = edges.value.filter((e) => e.id !== id);
    selectedEdgeId.value = null;
}

function deleteNodeById(id) {
    if (!id || id === 'trigger') return;
    nodes.value = nodes.value.filter((n) => n.id !== id);
    edges.value = edges.value.filter((e) => e.source !== id && e.target !== id);
    if (selectedNodeId.value === id) selectedNodeId.value = null;
}

function deleteEdgeById(id) {
    if (!id) return;
    edges.value = edges.value.filter((e) => e.id !== id);
    if (selectedEdgeId.value === id) selectedEdgeId.value = null;
}

const palette = [
    {
        type: 'trigger',
        title: 'Gatilho',
        desc: 'Início do fluxo. Define qual evento dispara as mensagens.',
    },
    {
        type: 'send_message',
        title: 'Enviar mensagem',
        desc: 'Envia uma mensagem no WhatsApp (texto, mídia ou interativo).',
    },
    { type: 'delay', title: 'Aguardar', desc: 'Espera X segundos antes de continuar.' },
    { type: 'condition', title: 'Condição', desc: 'Decide o próximo passo (SIM/NÃO).' },
    { type: 'end', title: 'Fim', desc: 'Finaliza a execução do fluxo.' },
];

function onDragStart(e, type) {
    e.dataTransfer?.setData('application/autozap-node', type);
    e.dataTransfer?.setData('text/plain', type);
    e.dataTransfer?.setDragImage?.(e.currentTarget, 20, 20);
    e.dataTransfer.effectAllowed = 'move';
}

function onDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
}

function onDrop(e) {
    e.preventDefault();
    const type = e.dataTransfer?.getData('application/autozap-node') || '';
    if (!type) return;
    const bounds = e.currentTarget.getBoundingClientRect();
    const pos = project({ x: e.clientX - bounds.left, y: e.clientY - bounds.top });
    addNode(type, pos);
}

function save() {
    emit('save', toStoredGraph(nodes.value, edges.value));
}

const defaultEdgeOptions = {
    type: 'autozap',
    markerEnd: MarkerType.ArrowClosed,
    animated: true,
    style: { strokeDasharray: '6 6' },
};

const connectionLineStyle = { strokeDasharray: '6 6', strokeWidth: 2.5, stroke: 'rgba(14, 165, 233, 0.9)' };
</script>

<template>
    <div class="grid gap-4" :class="showInspector ? 'lg:grid-cols-[280px_1fr_360px]' : 'lg:grid-cols-[280px_1fr]'">
        <!-- Palette -->
        <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
            <div class="mb-2 text-sm font-semibold text-zinc-900 dark:text-white">Blocos</div>
            <div class="text-xs text-zinc-500 dark:text-zinc-400">
                Arraste um bloco para o canvas. Conecte arrastando do ponto (•) de saída para o ponto de entrada.
            </div>
            <div class="mt-3 space-y-2">
                <div
                    v-for="p in palette"
                    :key="p.type"
                    class="cursor-grab select-none rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-left shadow-sm hover:border-zinc-300 active:cursor-grabbing dark:border-zinc-800 dark:bg-zinc-900"
                    draggable="true"
                    @dragstart="(e) => onDragStart(e, p.type)"
                    @dblclick="addNode(p.type)"
                >
                    <div class="text-sm font-semibold text-zinc-900 dark:text-white">{{ p.title }}</div>
                    <div class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ p.desc }}</div>
                </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <Button type="button" size="sm" variant="outline" :disabled="!selectedNode || selectedNode.id === 'trigger'" @click="deleteSelectedNode">
                    Remover nó
                </Button>
                <Button type="button" size="sm" variant="outline" :disabled="!selectedEdge" @click="deleteSelectedEdge">
                    Remover conexão
                </Button>
            </div>
        </div>

        <!-- Canvas -->
        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
            <div class="flex items-center justify-between gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                <div>
                    <div class="text-sm font-semibold text-zinc-900 dark:text-white">Editor de fluxo</div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                        Dica: use scroll para zoom, clique e arraste o fundo para mover, e conecte nós pelos pontos.
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button type="button" variant="outline" @click="emit('close')">Fechar</Button>
                    <Button type="button" @click="save">Salvar</Button>
                </div>
            </div>

            <div class="h-[620px]" @dragover="onDragOver" @drop="onDrop">
                <VueFlow
                    v-model:nodes="nodes"
                    v-model:edges="edges"
                    :default-viewport="{ x: 0, y: 0, zoom: 1 }"
                    :min-zoom="0.2"
                    :max-zoom="1.8"
                    :default-edge-options="defaultEdgeOptions"
                    :connection-line-style="connectionLineStyle"
                    connection-line-type="bezier"
                    class="autozap-flow"
                    @node-click="onNodeClick"
                    @edge-click="onEdgeClick"
                    @pane-click="clearSelection"
                >
                    <!-- Custom edge with trash icon at center -->
                    <template
                        #edge-autozap="{
                            id,
                            sourceX,
                            sourceY,
                            targetX,
                            targetY,
                            sourcePosition,
                            targetPosition,
                            markerEnd,
                            style,
                            selected,
                        }"
                    >
                        <BaseEdge
                            :id="id"
                            :path="getBezierPath({ sourceX, sourceY, targetX, targetY, sourcePosition, targetPosition })[0]"
                            :marker-end="markerEnd"
                            :style="style"
                            :class="selected ? 'autozap-edge--selected' : ''"
                        />
                        <EdgeLabelRenderer>
                            <div
                                class="autozap-edge-trash"
                                :style="(() => { const [, x, y] = getBezierPath({ sourceX, sourceY, targetX, targetY, sourcePosition, targetPosition }); return { transform: `translate(-50%, -50%) translate(${x}px, ${y}px)` }; })()"
                            >
                                <button
                                    type="button"
                                    class="autozap-edge-trashBtn"
                                    title="Excluir ligação"
                                    @click.stop="deleteEdgeById(id)"
                                >
                                    <Trash2 class="h-3.5 w-3.5" />
                                </button>
                            </div>
                        </EdgeLabelRenderer>
                    </template>

                    <!-- Node renderers (slots) -->
                    <template #node-trigger="{ id, data, selected }">
                        <div class="autozap-node" :class="selected ? 'autozap-node--selected' : ''">
                            <Handle type="source" :position="Position.Right" class="autozap-handle autozap-handle--source" />
                            <div class="autozap-node__title">
                                <span class="autozap-node__titleText">{{ data?._label || 'Gatilho' }}</span>
                                <span class="autozap-node__badge">INÍCIO</span>
                            </div>
                            <div class="autozap-node__meta">
                                <div class="autozap-node__k">Evento</div>
                                <div class="autozap-node__v">{{ data?.event_class || 'Definido pelo gatilho do produto' }}</div>
                            </div>
                            <div class="autozap-node__help">O fluxo começa quando esse evento acontecer no produto.</div>
                        </div>
                    </template>

                    <template #node-send_message="{ id, data, selected }">
                        <div class="autozap-node" :class="selected ? 'autozap-node--selected' : ''">
                            <Handle type="target" :position="Position.Left" class="autozap-handle autozap-handle--target" />
                            <Handle type="source" :position="Position.Right" class="autozap-handle autozap-handle--source" />
                            <button
                                type="button"
                                class="autozap-node-trash"
                                title="Excluir bloco"
                                @click.stop="deleteNodeById(id)"
                            >
                                <Trash2 class="h-3.5 w-3.5" />
                            </button>
                            <div class="autozap-node__title">
                                <span class="autozap-node__titleText">{{ data?._label || 'Enviar mensagem' }}</span>
                            </div>
                            <div v-if="data?.subtitle" class="autozap-node__subtitle">{{ data.subtitle }}</div>
                        </div>
                    </template>

                    <template #node-delay="{ id, data, selected }">
                        <div class="autozap-node" :class="selected ? 'autozap-node--selected' : ''">
                            <Handle type="target" :position="Position.Left" class="autozap-handle autozap-handle--target" />
                            <Handle type="source" :position="Position.Right" class="autozap-handle autozap-handle--source" />
                            <button
                                type="button"
                                class="autozap-node-trash"
                                title="Excluir bloco"
                                @click.stop="deleteNodeById(id)"
                            >
                                <Trash2 class="h-3.5 w-3.5" />
                            </button>
                            <div class="autozap-node__title">
                                <span class="autozap-node__titleText">{{ data?._label || 'Aguardar' }}</span>
                            </div>
                            <div v-if="data?.subtitle" class="autozap-node__subtitle">{{ data.subtitle }}</div>
                        </div>
                    </template>

                    <template #node-condition="{ id, data, selected }">
                        <div class="autozap-node" :class="selected ? 'autozap-node--selected' : ''">
                            <Handle type="target" :position="Position.Left" class="autozap-handle autozap-handle--target" />
                            <Handle type="source" :position="Position.Right" class="autozap-handle autozap-handle--source" />
                            <button
                                type="button"
                                class="autozap-node-trash"
                                title="Excluir bloco"
                                @click.stop="deleteNodeById(id)"
                            >
                                <Trash2 class="h-3.5 w-3.5" />
                            </button>
                            <div class="autozap-node__title">
                                <span class="autozap-node__titleText">{{ data?._label || 'Condição' }}</span>
                            </div>
                            <div v-if="data?.subtitle" class="autozap-node__subtitle">{{ data.subtitle }}</div>
                            <div class="autozap-node__help">Use a conexão “SIM/NÃO” no painel de conexão.</div>
                        </div>
                    </template>

                    <template #node-end="{ id, data, selected }">
                        <div class="autozap-node" :class="selected ? 'autozap-node--selected' : ''">
                            <Handle type="target" :position="Position.Left" class="autozap-handle autozap-handle--target" />
                            <button
                                type="button"
                                class="autozap-node-trash"
                                title="Excluir bloco"
                                @click.stop="deleteNodeById(id)"
                            >
                                <Trash2 class="h-3.5 w-3.5" />
                            </button>
                            <div class="autozap-node__title">
                                <span class="autozap-node__titleText">{{ data?._label || 'Fim' }}</span>
                                <span class="autozap-node__badge">FIM</span>
                            </div>
                            <div class="autozap-node__help">Finaliza a execução.</div>
                        </div>
                    </template>

                    <Background pattern-color="rgba(120,120,120,0.25)" :gap="18" />
                    <Controls />
                </VueFlow>
            </div>
        </div>

        <!-- Properties (right sidebar only when selected) -->
        <div v-if="showInspector" class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-950">
            <div class="mb-2 flex items-center justify-between gap-2">
                <div class="text-sm font-semibold text-zinc-900 dark:text-white">Propriedades</div>
                <div class="flex items-center gap-2">
                    <Button
                        v-if="selectedNode && selectedNode.id !== 'trigger'"
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="deleteSelectedNode"
                    >
                        Excluir bloco
                    </Button>
                    <Button
                        v-if="selectedEdge"
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="deleteSelectedEdge"
                    >
                        Excluir ligação
                    </Button>
                </div>
            </div>

            <div class="space-y-4">
                <template v-if="selectedNode">
                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">
                        <div class="font-semibold text-zinc-900 dark:text-white">{{ nodeLabel(selectedNode.type) }}</div>
                        <div class="mt-1">
                            ID: <span class="font-mono">{{ selectedNode.id }}</span>
                        </div>
                    </div>

                    <template v-if="selectedNode.type === 'trigger'">
                        <div>
                            <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300">O que é um “Gatilho”?</div>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                É o evento que dispara o fluxo (ex.: “PIX gerado”, “Venda aprovada”, “Carrinho abandonado”). Cada fluxo deste produto já nasce com o gatilho correto.
                            </p>
                        </div>
                        <div class="space-y-1">
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Evento</label>
                            <input
                                :value="selectedNode.data?.event_class || ''"
                                disabled
                                type="text"
                                class="w-full rounded-lg border border-zinc-300 bg-zinc-100 px-3 py-2 text-sm text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300"
                            />
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">Esse valor vem do evento escolhido no produto.</div>
                        </div>
                    </template>

                    <template v-else-if="selectedNode.type === 'send_message'">
                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Tipo</label>
                            <select v-model="selectedNode.data.mode" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-800 dark:bg-zinc-950">
                                <option value="text">Texto</option>
                                <option value="media">Mídia</option>
                                <option value="interactive">Interativo</option>
                            </select>
                        </div>

                        <div class="space-y-1">
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Mensagem</label>
                            <textarea
                                v-model="selectedNode.data.text"
                                rows="7"
                                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-800 dark:bg-zinc-950"
                                placeholder="Ex.: Olá {{customer.name}}! Seu pagamento foi aprovado."
                            />
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                Use variáveis como <span class="font-mono" v-text="'{{customer.name}}'" />, <span class="font-mono" v-text="'{{order.product.name}}'" />, <span class="font-mono" v-text="'{{checkout_link}}'" />.
                            </div>
                        </div>

                        <div v-if="selectedNode.data.mode === 'media'" class="space-y-2">
                            <div class="space-y-1">
                                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">URL da mídia</label>
                                <input v-model="selectedNode.data.media_url" type="url" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-800 dark:bg-zinc-950" />
                            </div>
                            <div class="space-y-1">
                                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">MIME type</label>
                                <input v-model="selectedNode.data.mime_type" type="text" placeholder="application/pdf" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-800 dark:bg-zinc-950" />
                            </div>
                        </div>
                    </template>

                    <template v-else-if="selectedNode.type === 'delay'">
                        <div class="space-y-1">
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Tempo de espera (segundos)</label>
                            <input v-model.number="selectedNode.data.seconds" type="number" min="0" max="86400" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-800 dark:bg-zinc-950" />
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">Dica: para “30 min”, use 1800.</div>
                        </div>
                    </template>

                    <template v-else-if="selectedNode.type === 'condition'">
                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Regra</label>
                            <select v-model="selectedNode.data.kind" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-800 dark:bg-zinc-950">
                                <option value="has_phone">Cliente tem telefone</option>
                                <option value="payment_method_is">Método de pagamento é…</option>
                                <option value="event_is">Evento é…</option>
                            </select>
                        </div>
                        <div v-if="selectedNode.data.kind === 'payment_method_is' || selectedNode.data.kind === 'event_is'" class="space-y-1">
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Valor</label>
                            <input v-model="selectedNode.data.value" type="text" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-800 dark:bg-zinc-950" />
                        </div>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            Para bifurcar, conecte 2 saídas e marque cada conexão como “SIM” ou “NÃO” na aba de conexão.
                        </p>
                    </template>
                </template>

                <template v-if="selectedEdge">
                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">
                        <div class="font-semibold text-zinc-900 dark:text-white">Conexão</div>
                        <div class="mt-1">
                            <span class="font-mono">{{ selectedEdge.source }}</span> → <span class="font-mono">{{ selectedEdge.target }}</span>
                        </div>
                    </div>
                    <div class="space-y-1">
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Saída da condição</label>
                        <select
                            v-model="selectedEdge.data.condition"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-800 dark:bg-zinc-950"
                            @change="
                                () => {
                                    const c = selectedEdge.data?.condition;
                                    selectedEdge.label = c === 'true' ? 'SIM' : c === 'false' ? 'NÃO' : undefined;
                                }
                            "
                        >
                            <option :value="undefined">Sem filtro (padrão)</option>
                            <option value="true">SIM</option>
                            <option value="false">NÃO</option>
                        </select>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">
                            Use isso quando a conexão sair de um nó “Condição”.
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

<style>
.autozap-flow {
    --vf-node-bg: transparent;
}
.vue-flow__edge-path {
    stroke: rgba(120, 120, 120, 0.65);
    stroke-width: 2.25px;
}
.vue-flow__edge.selected .vue-flow__edge-path {
    stroke: rgba(14, 165, 233, 0.95);
}
.autozap-edge--selected .vue-flow__edge-path {
    stroke: rgba(14, 165, 233, 0.95);
}
.vue-flow__handle {
    width: 10px;
    height: 10px;
    border: 2px solid rgba(14, 165, 233, 0.9);
    background: white;
}
.autozap-handle {
    border-radius: 999px;
}
.autozap-handle--target {
    left: -6px;
}
.autozap-handle--source {
    right: -6px;
}
.dark .vue-flow__handle {
    background: #09090b;
}
.autozap-node {
    min-width: 220px;
    max-width: 260px;
    border-radius: 16px;
    border: 1px solid rgba(228, 228, 231, 1);
    background: rgba(255, 255, 255, 0.95);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
    padding: 12px;
    position: relative;
}
.dark .autozap-node {
    border-color: rgba(39, 39, 42, 1);
    background: rgba(9, 9, 11, 0.9);
}
.autozap-node--selected {
    border-color: rgba(14, 165, 233, 1);
    box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.18), 0 10px 28px rgba(0, 0, 0, 0.12);
}
.autozap-node__title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    font-weight: 700;
    color: rgb(24, 24, 27);
    font-size: 13px;
    line-height: 1.2;
}
.dark .autozap-node__title {
    color: rgb(250, 250, 250);
}
.autozap-node__badge {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.06em;
    padding: 4px 8px;
    border-radius: 999px;
    background: rgba(14, 165, 233, 0.12);
    color: rgba(14, 165, 233, 1);
}
.autozap-node__subtitle {
    margin-top: 6px;
    font-size: 11px;
    color: rgba(113, 113, 122, 1);
}
.dark .autozap-node__subtitle {
    color: rgba(161, 161, 170, 1);
}
.autozap-node__meta {
    margin-top: 10px;
    display: grid;
    grid-template-columns: 60px 1fr;
    gap: 8px;
    font-size: 11px;
}
.autozap-node__k {
    color: rgba(113, 113, 122, 1);
}
.dark .autozap-node__k {
    color: rgba(161, 161, 170, 1);
}
.autozap-node__v {
    color: rgba(24, 24, 27, 1);
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
    font-size: 10.5px;
    word-break: break-all;
}
.dark .autozap-node__v {
    color: rgba(250, 250, 250, 1);
}
.autozap-node__help {
    margin-top: 8px;
    font-size: 11px;
    color: rgba(113, 113, 122, 1);
}
.dark .autozap-node__help {
    color: rgba(161, 161, 170, 1);
}

.autozap-node-trash {
    position: absolute;
    top: 8px;
    right: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 10px;
    color: rgba(113, 113, 122, 1);
    background: rgba(0, 0, 0, 0);
    transition: background 0.15s ease, color 0.15s ease;
}
.autozap-node-trash:hover {
    background: rgba(244, 63, 94, 0.12);
    color: rgba(244, 63, 94, 1);
}
.dark .autozap-node-trash {
    color: rgba(161, 161, 170, 1);
}

.autozap-edge-trash {
    position: absolute;
    pointer-events: all;
}
.autozap-edge-trashBtn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.92);
    border: 1px solid rgba(228, 228, 231, 1);
    color: rgba(113, 113, 122, 1);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
    transition: transform 0.12s ease, background 0.12s ease, color 0.12s ease, border-color 0.12s ease;
}
.autozap-edge-trashBtn:hover {
    transform: scale(1.06);
    background: rgba(255, 255, 255, 1);
    border-color: rgba(244, 63, 94, 0.35);
    color: rgba(244, 63, 94, 1);
}
.dark .autozap-edge-trashBtn {
    background: rgba(9, 9, 11, 0.92);
    border-color: rgba(39, 39, 42, 1);
    color: rgba(161, 161, 170, 1);
}
</style>
