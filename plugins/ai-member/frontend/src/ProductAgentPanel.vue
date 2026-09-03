<script setup>
import { ref, onMounted, computed } from 'vue';
import axios from 'axios';
import {
    Loader2,
    RefreshCw,
    Plus,
    Trash2,
    Bot,
    Sparkles,
    MessageCircle,
    Image as ImageIcon,
    Mic,
    BookOpen,
    Settings2,
    CheckCircle2,
    AlertCircle,
    ExternalLink,
} from 'lucide-vue-next';

const props = defineProps({
    produto: { type: Object, required: true },
});

const loading = ref(true);
const saving = ref(false);
const reindexing = ref(false);
const error = ref('');
const success = ref('');
const openrouterConfigured = ref(false);
const documents = ref([]);

const agent = ref({
    enabled: false,
    name: 'Assistente',
    gender: 'neutral',
    personality: '',
    temperature: 0.7,
    max_tokens: 800,
    system_instructions: '',
    welcome_message: 'Olá! Sou seu assistente. Como posso ajudar?',
    intro_headline: 'Como posso ajudar você hoje?',
    widget_icon: '',
    widget_color: '#6366f1',
    widget_color_source: 'theme',
    allow_image: true,
    allow_audio: true,
    knowledge_indexed_at: null,
    knowledge_chunks_count: 0,
});

const newDoc = ref({ title: '', content: '' });

const themePrimary = computed(() => props.produto?.member_area_config?.theme?.primary || '#0ea5e9');
const previewColor = computed(() =>
    agent.value.widget_color_source === 'custom'
        ? (agent.value.widget_color || '#6366f1')
        : themePrimary.value,
);
const iconUploading = ref(false);
const iconFileInput = ref(null);

const productId = computed(() => props.produto?.id);

const indexedAtLabel = computed(() => {
    if (!agent.value.knowledge_indexed_at) return null;
    return new Date(agent.value.knowledge_indexed_at).toLocaleString('pt-BR');
});

const statusLabel = computed(() => {
    if (!openrouterConfigured.value) return 'OpenRouter pendente';
    if (agent.value.enabled) return 'Agente ativo';
    return 'Pronto para ativar';
});

const statusTone = computed(() => {
    if (!openrouterConfigured.value) return 'amber';
    if (agent.value.enabled) return 'emerald';
    return 'indigo';
});

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await axios.get(`/ai-member/agents/${productId.value}`);
        agent.value = { ...agent.value, ...data.agent };
        documents.value = data.documents ?? [];
        openrouterConfigured.value = !!data.openrouter_configured;
    } catch (e) {
        error.value = e?.response?.data?.message || 'Erro ao carregar configurações.';
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;
    error.value = '';
    success.value = '';
    try {
        const { data } = await axios.put(`/ai-member/agents/${productId.value}`, agent.value);
        agent.value = { ...agent.value, ...data.agent };
        success.value = 'Configurações salvas com sucesso.';
    } catch (e) {
        error.value = e?.response?.data?.message || 'Erro ao salvar.';
    } finally {
        saving.value = false;
    }
}

async function reindex() {
    reindexing.value = true;
    error.value = '';
    success.value = '';
    try {
        const { data } = await axios.post(`/ai-member/agents/${productId.value}/reindex`);
        if (data.agent) {
            agent.value = { ...agent.value, ...data.agent };
        }
        success.value = data.message || 'Indexação concluída.';
    } catch (e) {
        error.value = e?.response?.data?.message || 'Erro na indexação.';
    } finally {
        reindexing.value = false;
    }
}

async function addDocument() {
    if (!newDoc.value.title.trim() || !newDoc.value.content.trim()) {
        return;
    }
    try {
        const { data } = await axios.post(`/ai-member/agents/${productId.value}/documents`, newDoc.value);
        documents.value.push(data.document);
        newDoc.value = { title: '', content: '' };
        success.value = 'Documento adicionado e indexação agendada.';
    } catch (e) {
        error.value = e?.response?.data?.message || 'Erro ao adicionar documento.';
    }
}

async function removeDocument(id) {
    try {
        await axios.delete(`/ai-member/agents/${productId.value}/documents/${id}`);
        documents.value = documents.value.filter((d) => d.id !== id);
    } catch (e) {
        error.value = e?.response?.data?.message || 'Erro ao remover.';
    }
}

async function onIconChange(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
        error.value = 'O ícone deve ter no máximo 2 MB.';
        event.target.value = '';
        return;
    }
    iconUploading.value = true;
    error.value = '';
    success.value = '';
    try {
        const form = new FormData();
        form.append('file', file);
        const { data } = await axios.post(`/ai-member/agents/${productId.value}/widget-icon`, form, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (data.agent) {
            agent.value = { ...agent.value, ...data.agent };
        } else if (data.url) {
            agent.value.widget_icon = data.url;
        }
        success.value = 'Ícone atualizado.';
    } catch (e) {
        error.value = e?.response?.data?.message || 'Erro ao enviar ícone.';
    } finally {
        iconUploading.value = false;
        event.target.value = '';
    }
}

async function removeIcon() {
    iconUploading.value = true;
    error.value = '';
    try {
        const { data } = await axios.delete(`/ai-member/agents/${productId.value}/widget-icon`);
        if (data.agent) {
            agent.value = { ...agent.value, ...data.agent };
        } else {
            agent.value.widget_icon = '';
        }
        success.value = 'Ícone removido.';
    } catch (e) {
        error.value = e?.response?.data?.message || 'Erro ao remover ícone.';
    } finally {
        iconUploading.value = false;
    }
}

onMounted(load);

const inputClass =
    'w-full rounded-xl border border-zinc-200/80 bg-white px-3.5 py-2.5 text-sm text-zinc-900 shadow-sm transition placeholder:text-zinc-400 focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-zinc-700 dark:bg-zinc-900/80 dark:text-zinc-100 dark:placeholder:text-zinc-500';
const labelClass = 'mb-1.5 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400';
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-6 pb-8">
        <!-- Hero -->
        <div
            class="relative overflow-hidden rounded-2xl border border-zinc-200/80 bg-gradient-to-br from-indigo-50 via-white to-violet-50 p-5 shadow-sm dark:border-zinc-800 dark:from-indigo-950/40 dark:via-zinc-900 dark:to-violet-950/30 sm:p-6"
        >
            <div class="pointer-events-none absolute -right-8 -top-8 h-32 w-32 rounded-full bg-indigo-400/10 blur-2xl dark:bg-indigo-500/20" />
            <div class="relative flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex items-start gap-4">
                    <div
                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-indigo-600 text-white shadow-lg shadow-indigo-600/25"
                    >
                        <Bot class="h-6 w-6" />
                    </div>
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold tracking-tight text-zinc-900 dark:text-white">
                                Agente de suporte IA
                            </h2>
                            <span
                                class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="{
                                    'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300': statusTone === 'amber',
                                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300': statusTone === 'emerald',
                                    'bg-indigo-100 text-indigo-800 dark:bg-indigo-950/60 dark:text-indigo-300': statusTone === 'indigo',
                                }"
                            >
                                <AlertCircle v-if="statusTone === 'amber'" class="h-3 w-3" />
                                <CheckCircle2 v-else class="h-3 w-3" />
                                {{ statusLabel }}
                            </span>
                        </div>
                        <p class="mt-1 max-w-xl text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                            Personalize o assistente flutuante que seus alunos verão na área de membros de
                            <strong class="font-medium text-zinc-800 dark:text-zinc-200">{{ produto.name }}</strong>.
                        </p>
                        <p
                            v-if="!openrouterConfigured"
                            class="mt-3 inline-flex flex-wrap items-center gap-1 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                        >
                            <AlertCircle class="h-4 w-4 shrink-0" />
                            Configure o OpenRouter em
                            <a href="/ai-member" class="inline-flex items-center gap-0.5 font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                                AI Member
                                <ExternalLink class="h-3.5 w-3.5" />
                            </a>
                            antes de ativar.
                        </p>
                    </div>
                </div>

                <!-- Mini preview -->
                <div
                    class="hidden shrink-0 rounded-2xl border border-zinc-200/80 bg-zinc-950 p-4 shadow-inner dark:border-zinc-700 sm:block"
                >
                    <p class="mb-3 text-[10px] font-medium uppercase tracking-wider text-zinc-500">Preview</p>
                    <div class="relative h-24 w-36 overflow-hidden rounded-xl bg-zinc-900">
                        <div class="absolute inset-x-3 top-3 h-2 rounded-full bg-zinc-800" />
                        <div class="absolute inset-x-3 top-7 h-16 rounded-lg bg-zinc-800/80" />
                        <div
                            class="absolute bottom-2 right-2 flex h-9 w-9 items-center justify-center rounded-full shadow-lg"
                            :style="{ backgroundColor: previewColor }"
                        >
                            <img
                                v-if="agent.widget_icon"
                                :src="agent.widget_icon"
                                alt=""
                                class="h-5 w-5 rounded-full object-cover"
                            />
                            <MessageCircle v-else class="h-4 w-4 text-white" />
                        </div>
                    </div>
                    <p class="mt-2 truncate text-center text-xs font-medium text-zinc-300">{{ agent.name || 'Assistente' }}</p>
                </div>
            </div>
        </div>

        <div v-if="loading" class="flex justify-center py-20">
            <Loader2 class="h-8 w-8 animate-spin text-indigo-500" />
        </div>

        <template v-else>
            <!-- Toggle principal -->
            <div
                class="flex items-center justify-between gap-4 rounded-2xl border border-zinc-200/80 bg-white px-4 py-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60 sm:px-5"
            >
                <div>
                    <p class="font-medium text-zinc-900 dark:text-white">Ativar agente nesta área</p>
                    <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                        Exibe o chat flutuante para alunos com acesso ao produto.
                    </p>
                </div>
                <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                    <input
                        v-model="agent.enabled"
                        type="checkbox"
                        class="peer sr-only"
                        :disabled="!openrouterConfigured"
                    />
                    <span
                        class="h-7 w-12 rounded-full bg-zinc-200 transition peer-checked:bg-indigo-600 peer-disabled:opacity-40 dark:bg-zinc-700"
                    />
                    <span
                        class="absolute left-0.5 top-0.5 h-6 w-6 rounded-full bg-white shadow transition peer-checked:translate-x-5"
                    />
                </label>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Identidade -->
                <section class="space-y-4 rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center gap-2">
                        <Sparkles class="h-4 w-4 text-indigo-500" />
                        <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Identidade</h3>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label :class="labelClass">Nome do agente</label>
                            <input v-model="agent.name" type="text" :class="inputClass" placeholder="Ex.: Ana, Suporte..." />
                        </div>
                        <div>
                            <label :class="labelClass">Gênero / voz</label>
                            <select v-model="agent.gender" :class="inputClass">
                                <option value="neutral">Neutro</option>
                                <option value="male">Masculino</option>
                                <option value="female">Feminino</option>
                            </select>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-4">
                                <div>
                                    <label :class="labelClass">Cor do widget</label>
                                    <select v-model="agent.widget_color_source" :class="inputClass">
                                        <option value="theme">Usar cor primária da área de membros</option>
                                        <option value="custom">Cor personalizada</option>
                                    </select>
                                    <p class="mt-1 text-xs text-zinc-500">
                                        Tema atual: {{ themePrimary }}
                                    </p>
                                </div>
                                <div v-if="agent.widget_color_source === 'custom'">
                                    <label :class="labelClass">Cor personalizada</label>
                                    <input
                                        v-model="agent.widget_color"
                                        type="color"
                                        class="h-11 w-14 cursor-pointer rounded-xl border border-zinc-200 dark:border-zinc-700"
                                    />
                                </div>
                                <div v-else>
                                    <label :class="labelClass">Cor do tema</label>
                                    <div
                                        class="h-11 w-14 rounded-xl border border-zinc-200 dark:border-zinc-700"
                                        :style="{ backgroundColor: themePrimary }"
                                    />
                                </div>
                            </div>
                            <div>
                                <label :class="labelClass">Ícone do widget</label>
                                <input
                                    ref="iconFileInput"
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp,image/gif"
                                    class="hidden"
                                    @change="onIconChange"
                                />
                                <div class="flex flex-wrap items-center gap-3">
                                    <div
                                        class="flex h-14 w-14 items-center justify-center overflow-hidden rounded-2xl border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/60"
                                        :style="!agent.widget_icon ? { backgroundColor: previewColor } : {}"
                                    >
                                        <img
                                            v-if="agent.widget_icon"
                                            :src="agent.widget_icon"
                                            alt=""
                                            class="h-full w-full object-cover"
                                        />
                                        <Bot v-else class="h-6 w-6 text-white" />
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm font-medium text-zinc-700 shadow-sm transition hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                                            :disabled="iconUploading"
                                            @click="iconFileInput?.click()"
                                        >
                                            <Loader2 v-if="iconUploading" class="inline h-4 w-4 animate-spin" />
                                            <span v-else>{{ agent.widget_icon ? 'Trocar ícone' : 'Enviar ícone' }}</span>
                                        </button>
                                        <button
                                            v-if="agent.widget_icon"
                                            type="button"
                                            class="rounded-xl px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 disabled:opacity-50 dark:hover:bg-red-950/30"
                                            :disabled="iconUploading"
                                            @click="removeIcon"
                                        >
                                            Remover
                                        </button>
                                    </div>
                                </div>
                                <p class="mt-1.5 text-xs text-zinc-500">PNG, JPG ou WebP · máx. 2 MB · ideal 256×256 px</p>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Capacidades -->
                <section class="space-y-4 rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center gap-2">
                        <Settings2 class="h-4 w-4 text-indigo-500" />
                        <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Capacidades</h3>
                    </div>
                    <div class="space-y-3">
                        <label
                            class="flex cursor-pointer items-center gap-3 rounded-xl border border-zinc-200/80 px-3 py-3 transition hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800/50"
                        >
                            <input v-model="agent.allow_image" type="checkbox" class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500/30" />
                            <ImageIcon class="h-4 w-4 text-zinc-400" />
                            <div>
                                <p class="text-sm font-medium text-zinc-900 dark:text-white">Enviar imagens</p>
                                <p class="text-xs text-zinc-500">Aluno pode mandar prints e fotos no chat.</p>
                            </div>
                        </label>
                        <label
                            class="flex cursor-pointer items-center gap-3 rounded-xl border border-zinc-200/80 px-3 py-3 transition hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800/50"
                        >
                            <input v-model="agent.allow_audio" type="checkbox" class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500/30" />
                            <Mic class="h-4 w-4 text-zinc-400" />
                            <div>
                                <p class="text-sm font-medium text-zinc-900 dark:text-white">Enviar áudio</p>
                                <p class="text-xs text-zinc-500">Mensagens de voz gravadas no navegador.</p>
                            </div>
                        </label>
                    </div>
                    <div class="grid grid-cols-2 gap-3 pt-1">
                        <div class="rounded-xl bg-zinc-50 px-3 py-2.5 dark:bg-zinc-800/50">
                            <p class="text-[10px] font-medium uppercase tracking-wide text-zinc-500">Temperatura</p>
                            <p class="mt-0.5 text-lg font-semibold tabular-nums text-zinc-900 dark:text-white">{{ agent.temperature }}</p>
                        </div>
                        <div class="rounded-xl bg-zinc-50 px-3 py-2.5 dark:bg-zinc-800/50">
                            <p class="text-[10px] font-medium uppercase tracking-wide text-zinc-500">Máx. tokens</p>
                            <p class="mt-0.5 text-lg font-semibold tabular-nums text-zinc-900 dark:text-white">{{ agent.max_tokens }}</p>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Comportamento -->
            <section class="space-y-4 rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60">
                <div class="flex items-center gap-2">
                    <MessageCircle class="h-4 w-4 text-indigo-500" />
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Comportamento</h3>
                </div>
                <div class="grid gap-4 lg:grid-cols-2">
                    <div>
                        <label :class="labelClass">Temperatura · {{ agent.temperature }}</label>
                        <input
                            v-model.number="agent.temperature"
                            type="range"
                            min="0"
                            max="2"
                            step="0.1"
                            class="h-2 w-full cursor-pointer appearance-none rounded-full bg-zinc-200 accent-indigo-600 dark:bg-zinc-700"
                        />
                        <p class="mt-1 text-xs text-zinc-500">Mais baixo = respostas objetivas. Mais alto = mais criativo.</p>
                    </div>
                    <div>
                        <label :class="labelClass">Máx. tokens por resposta</label>
                        <input v-model.number="agent.max_tokens" type="number" min="100" max="4000" :class="inputClass" />
                    </div>
                </div>
                <div class="grid gap-4 lg:grid-cols-2">
                    <div>
                        <label :class="labelClass">Personalidade / tom</label>
                        <textarea
                            v-model="agent.personality"
                            rows="3"
                            :class="inputClass"
                            placeholder="Amigável, paciente, didático..."
                        />
                    </div>
                    <div>
                        <label :class="labelClass">Título de boas-vindas</label>
                        <input
                            v-model="agent.intro_headline"
                            type="text"
                            :class="inputClass"
                            placeholder="Ex.: Como posso ajudar você hoje?"
                        />
                    </div>
                    <div>
                        <label :class="labelClass">Mensagem de apresentação</label>
                        <textarea v-model="agent.welcome_message" rows="3" :class="inputClass" placeholder="Texto exibido na tela inicial do chat..." />
                    </div>
                </div>
                <div>
                    <label :class="labelClass">Instruções customizadas</label>
                    <textarea
                        v-model="agent.system_instructions"
                        rows="4"
                        :class="inputClass"
                        placeholder="Regras de suporte, políticas do produto, o que o agente pode ou não fazer..."
                    />
                </div>
            </section>

            <!-- Base de conhecimento -->
            <section class="space-y-4 rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/60">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <BookOpen class="h-4 w-4 text-indigo-500" />
                        <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Base de conhecimento</h3>
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl border border-zinc-200 bg-white px-3.5 py-2 text-sm font-medium text-zinc-700 shadow-sm transition hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-750"
                        :disabled="reindexing"
                        @click="reindex"
                    >
                        <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': reindexing }" />
                        Sincronizar aulas
                    </button>
                </div>

                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex items-center rounded-full bg-indigo-50 px-3 py-1 text-xs font-medium text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300">
                        {{ agent.knowledge_chunks_count }} trechos indexados
                    </span>
                    <span
                        v-if="indexedAtLabel"
                        class="inline-flex items-center rounded-full bg-zinc-100 px-3 py-1 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400"
                    >
                        Atualizado {{ indexedAtLabel }}
                    </span>
                    <span
                        v-if="documents.length"
                        class="inline-flex items-center rounded-full bg-zinc-100 px-3 py-1 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400"
                    >
                        {{ documents.length }} FAQ{{ documents.length === 1 ? '' : 's' }} extra
                    </span>
                </div>

                <div v-if="documents.length" class="space-y-2">
                    <div
                        v-for="doc in documents"
                        :key="doc.id"
                        class="group flex items-start justify-between gap-3 rounded-xl border border-zinc-200/80 bg-zinc-50/80 px-3 py-3 dark:border-zinc-700 dark:bg-zinc-800/40"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ doc.title }}</p>
                            <p class="mt-0.5 line-clamp-2 text-xs leading-relaxed text-zinc-500">{{ doc.content }}</p>
                        </div>
                        <button
                            type="button"
                            class="shrink-0 rounded-lg p-1.5 text-zinc-400 opacity-0 transition hover:bg-red-50 hover:text-red-600 group-hover:opacity-100 dark:hover:bg-red-950/30"
                            title="Remover"
                            @click="removeDocument(doc.id)"
                        >
                            <Trash2 class="h-4 w-4" />
                        </button>
                    </div>
                </div>
                <p v-else class="text-sm text-zinc-500 dark:text-zinc-400">
                    Nenhum FAQ extra. As aulas do produto são indexadas automaticamente ao sincronizar.
                </p>

                <div class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-900/30">
                    <p class="mb-3 text-xs font-medium uppercase tracking-wide text-zinc-500">Adicionar FAQ</p>
                    <div class="space-y-3">
                        <input v-model="newDoc.title" type="text" :class="inputClass" placeholder="Título (ex.: Como acessar as aulas?)" />
                        <textarea
                            v-model="newDoc.content"
                            rows="3"
                            :class="inputClass"
                            placeholder="Resposta ou conteúdo de treinamento..."
                        />
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 hover:text-indigo-700 dark:text-indigo-400"
                            @click="addDocument"
                        >
                            <Plus class="h-4 w-4" />
                            Adicionar documento
                        </button>
                    </div>
                </div>
            </section>

            <!-- Feedback + salvar -->
            <div
                v-if="error || success"
                class="rounded-xl px-4 py-3 text-sm"
                :class="error
                    ? 'border border-red-200 bg-red-50 text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-200'
                    : 'border border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-200'"
            >
                {{ error || success }}
            </div>

            <div class="flex justify-end border-t border-zinc-200/80 pt-4 dark:border-zinc-800">
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-indigo-600/20 transition hover:bg-indigo-700 disabled:opacity-50"
                    :disabled="saving"
                    @click="save"
                >
                    <Loader2 v-if="saving" class="h-4 w-4 animate-spin" />
                    <span>{{ saving ? 'Salvando…' : 'Salvar agente' }}</span>
                </button>
            </div>
        </template>
    </div>
</template>
