<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';
import FlowCanvasEditor from './FlowCanvasEditor.vue';

const props = defineProps({
    produto: { type: Object, required: true },
});

const loading = ref(true);
const connected = ref(false);
const provider = ref(null);
const error = ref('');
const flowsLoading = ref(false);
const flowsError = ref('');
const flows = ref([]);
const editorOpen = ref(false);
const editorFlow = ref(null);
const editorSaving = ref(false);
const editorError = ref('');
const editorFullscreen = ref(false);

const productId = computed(() => props.produto?.id);

const EVENTS = [
    { id: 'order_pending', label: 'Pedido pendente', eventClass: 'App\\Events\\OrderPending' },
    { id: 'pix_generated', label: 'PIX gerado', eventClass: 'App\\Events\\PixGenerated' },
    { id: 'boleto_generated', label: 'Boleto gerado', eventClass: 'App\\Events\\BoletoGenerated' },
    { id: 'order_completed', label: 'Venda aprovada', eventClass: 'App\\Events\\OrderCompleted' },
    { id: 'access_delivery', label: 'Envio de acesso (WhatsApp)', eventClass: 'App\\Events\\AccessDeliveryReady' },
    { id: 'order_rejected', label: 'Pagamento recusado', eventClass: 'App\\Events\\OrderRejected' },
    { id: 'order_cancelled', label: 'Pedido cancelado', eventClass: 'App\\Events\\OrderCancelled' },
    { id: 'order_refunded', label: 'Pedido reembolsado', eventClass: 'App\\Events\\OrderRefunded' },
    { id: 'cart_abandoned', label: 'Carrinho abandonado', eventClass: 'App\\Events\\CartAbandoned' },
    { id: 'subscription_created', label: 'Assinatura criada', eventClass: 'App\\Events\\SubscriptionCreated' },
    { id: 'subscription_renewed', label: 'Assinatura renovada', eventClass: 'App\\Events\\SubscriptionRenewed' },
    { id: 'subscription_cancelled', label: 'Assinatura cancelada', eventClass: 'App\\Events\\SubscriptionCancelled' },
    { id: 'subscription_past_due', label: 'Assinatura em atraso', eventClass: 'App\\Events\\SubscriptionPastDue' },
];

const flowByEvent = computed(() => {
    const map = new Map();
    for (const f of flows.value || []) {
        map.set(f.trigger_event, f);
    }
    return map;
});

async function loadStatus() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await axios.get('/autozap/connection');
        connected.value = !!data?.connected;
        provider.value = data?.provider || null;
    } catch (e) {
        error.value = e.response?.data?.message || 'Não foi possível verificar o status do AutoZap.';
    } finally {
        loading.value = false;
    }
}

async function loadFlows() {
    flowsLoading.value = true;
    flowsError.value = '';
    try {
        const { data } = await axios.get('/autozap/flows', {
            params: { product_id: productId.value },
        });
        flows.value = Array.isArray(data?.flows) ? data.flows : [];
    } catch (e) {
        flowsError.value = e.response?.data?.message || 'Não foi possível carregar os fluxos deste produto.';
    } finally {
        flowsLoading.value = false;
    }
}

function defaultGraphForEvent(eventClass) {
    if (eventClass === 'App\\Events\\AccessDeliveryReady') {
        return {
            nodes: [
                { id: 'trigger', type: 'trigger', x: 80, y: 200, data: { event_class: eventClass } },
                {
                    id: 'send1',
                    type: 'send_message',
                    x: 360,
                    y: 200,
                    data: {
                        mode: 'text',
                        text:
                            'Olá {{customer.name}}! Seu pagamento foi aprovado.\\n\\nAqui está seu acesso ao produto *{{order.product.name}}*:\\n\\n🔗 Link: {{access.link}}\\n👤 Login: {{access.email}}\\n🔑 Senha: {{access.password}}\\n\\nSe a senha estiver vazia, é porque você já tinha cadastro (ou o acesso é por link).',
                    },
                },
                { id: 'end', type: 'end', x: 680, y: 200, data: {} },
            ],
            edges: [
                { from: 'trigger', to: 'send1' },
                { from: 'send1', to: 'end' },
            ],
        };
    }
    return {
        nodes: [
            { id: 'trigger', type: 'trigger', x: 80, y: 200, data: { event_class: eventClass } },
            {
                id: 'send1',
                type: 'send_message',
                x: 360,
                y: 200,
                data: {
                    mode: 'text',
                    text:
                        'Olá {{customer.name}}!\\n\\nRecebemos um evento: {{event_class}}\\n\\nProduto: {{order.product.name}}\\nLink: {{checkout_link}}',
                },
            },
            { id: 'end', type: 'end', x: 680, y: 200, data: {} },
        ],
        edges: [
            { from: 'trigger', to: 'send1' },
            { from: 'send1', to: 'end' },
        ],
    };
}

async function createFlowForEvent(ev) {
    flowsError.value = '';
    try {
        await axios.post('/autozap/flows', {
            name: `${ev.label} (${props.produto?.name || 'Produto'})`,
            product_id: productId.value,
            trigger_event: ev.eventClass,
            is_active: true,
            graph_json: defaultGraphForEvent(ev.eventClass),
        });
        await loadFlows();
    } catch (e) {
        flowsError.value = e.response?.data?.message || 'Erro ao criar fluxo.';
    }
}

async function toggleFlow(flow, enabled) {
    flowsError.value = '';
    try {
        await axios.put(`/autozap/flows/${flow.id}`, { is_active: !!enabled });
        await loadFlows();
    } catch (e) {
        flowsError.value = e.response?.data?.message || 'Erro ao atualizar fluxo.';
    }
}

function openEditor(flow) {
    editorFlow.value = flow;
    editorError.value = '';
    editorOpen.value = true;
    editorFullscreen.value = true;
}

async function saveGraph(graphJson) {
    if (!editorFlow.value?.id) return;
    editorSaving.value = true;
    editorError.value = '';
    try {
        await axios.put(`/autozap/flows/${editorFlow.value.id}`, { graph_json: graphJson });
        editorOpen.value = false;
        editorFlow.value = null;
        await loadFlows();
    } catch (e) {
        editorError.value = e.response?.data?.message || 'Erro ao salvar fluxo.';
    } finally {
        editorSaving.value = false;
    }
}

loadStatus();
loadFlows();
</script>

<template>
    <div class="space-y-3">
        <div v-if="loading" class="text-sm text-zinc-500 dark:text-zinc-400">Verificando integração…</div>
        <div v-else class="space-y-3">
            <div
                v-if="!connected"
                class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900/40 dark:bg-amber-900/20 dark:text-amber-200"
            >
                O AutoZap não está conectado. Para ativar os fluxos deste produto, conecte em
                <Link href="/integracoes" class="font-medium underline">Integrações</Link>.
            </div>
            <div v-else class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900 dark:border-emerald-900/40 dark:bg-emerald-900/20 dark:text-emerald-200">
                AutoZap conectado (<span class="font-medium">{{ provider }}</span>).
            </div>

            <div class="rounded-xl border border-zinc-200/80 bg-white p-4 dark:border-zinc-700/80 dark:bg-zinc-900/30">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <div>
                        <div class="font-medium text-zinc-900 dark:text-white">Gatilhos deste produto</div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">
                            Crie um fluxo por evento. Depois você personaliza no editor (canvas) quando estiver pronto.
                        </div>
                    </div>
                    <Link href="/integracoes" class="text-xs text-[var(--color-primary)] hover:underline">Integrações</Link>
                </div>

                <div v-if="flowsLoading" class="text-sm text-zinc-500 dark:text-zinc-400">Carregando fluxos…</div>
                <div v-else class="space-y-2">
                    <div
                        v-for="ev in EVENTS"
                        :key="ev.id"
                        class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-200/80 bg-zinc-50 px-3 py-2 dark:border-zinc-700/80 dark:bg-zinc-800/40"
                    >
                        <div class="min-w-[200px]">
                            <div class="text-sm font-medium text-zinc-900 dark:text-white">{{ ev.label }}</div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ ev.eventClass }}</div>
                        </div>

                        <div class="flex items-center gap-2">
                            <template v-if="flowByEvent.get(ev.eventClass)">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        flowByEvent.get(ev.eventClass).is_active
                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'
                                            : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300'
                                    "
                                >
                                    {{ flowByEvent.get(ev.eventClass).is_active ? 'Ativo' : 'Pausado' }}
                                </span>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    :disabled="!connected"
                                    @click="toggleFlow(flowByEvent.get(ev.eventClass), !flowByEvent.get(ev.eventClass).is_active)"
                                >
                                    {{ flowByEvent.get(ev.eventClass).is_active ? 'Pausar' : 'Ativar' }}
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    :disabled="!connected"
                                    @click="openEditor(flowByEvent.get(ev.eventClass))"
                                >
                                    Editar
                                </Button>
                            </template>
                            <template v-else>
                                <Button type="button" size="sm" variant="outline" :disabled="!connected" @click="createFlowForEvent(ev)">
                                    Criar fluxo
                                </Button>
                            </template>
                        </div>
                    </div>

                    <p v-if="flowsError" class="text-sm text-red-600 dark:text-red-400">{{ flowsError }}</p>
                </div>
            </div>

            <p v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>
        </div>
    </div>

    <Teleport to="body">
        <Transition enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0" enter-to-class="opacity-100" leave-active-class="transition-opacity duration-200" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="editorOpen" class="fixed inset-0 z-[100000] bg-black/40" @click="editorOpen = false" />
        </Transition>
        <Transition enter-active-class="transition-transform duration-300 ease-out" enter-from-class="scale-95 opacity-0" enter-to-class="scale-100 opacity-100" leave-active-class="transition-transform duration-200 ease-in" leave-from-class="scale-100 opacity-100" leave-to-class="scale-95 opacity-0">
            <div v-if="editorOpen" class="fixed inset-0 z-[100001] flex items-center justify-center p-4" @click.stop>
                <div
                    class="w-full overflow-hidden bg-white shadow-2xl dark:bg-zinc-950"
                    :class="editorFullscreen ? 'h-full max-w-none rounded-none' : 'max-w-6xl rounded-2xl'"
                >
                    <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                        <div class="text-sm font-semibold text-zinc-900 dark:text-white">
                            Editor de fluxo — {{ editorFlow?.name || 'AutoZap' }}
                        </div>
                        <div class="flex items-center gap-2">
                            <span v-if="editorSaving" class="text-xs text-zinc-500 dark:text-zinc-400">Salvando…</span>
                            <button
                                type="button"
                                class="rounded-lg px-2 py-1 text-sm text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-900"
                                @click="editorFullscreen = !editorFullscreen"
                            >
                                {{ editorFullscreen ? 'Sair da tela cheia' : 'Tela cheia' }}
                            </button>
                            <button type="button" class="rounded-lg px-2 py-1 text-sm text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-900" @click="editorOpen = false">
                                Fechar
                            </button>
                        </div>
                    </div>
                    <div :class="editorFullscreen ? 'p-3' : 'p-4'">
                        <FlowCanvasEditor
                            v-if="editorFlow"
                            :flow="editorFlow"
                            @close="editorOpen = false"
                            @save="saveGraph"
                        />
                        <p v-if="editorError" class="mt-3 text-sm text-red-600 dark:text-red-400">{{ editorError }}</p>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

