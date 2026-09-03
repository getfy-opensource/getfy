<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import { Bot, Key, BookOpen, Loader2, CheckCircle, AlertCircle, ExternalLink } from 'lucide-vue-next';

const connection = ref({
    configured: false,
    is_active: false,
    has_token: false,
    last_tested_at: null,
    last_error: null,
});
const form = ref({ api_key: '', is_active: true });
const loading = ref(true);
const saving = ref(false);
const testing = ref(false);
const message = ref('');
const error = ref('');

async function loadConnection() {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await axios.get('/ai-member/connection');
        connection.value = data.connection ?? connection.value;
        form.value.is_active = connection.value.is_active ?? true;
    } catch (e) {
        error.value = e?.response?.data?.message || 'Não foi possível carregar a conexão.';
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;
    message.value = '';
    error.value = '';
    try {
        const { data } = await axios.put('/ai-member/connection', {
            api_key: form.value.api_key?.trim() || undefined,
            is_active: form.value.is_active,
        });
        connection.value = data.connection ?? connection.value;
        form.value.api_key = '';
        message.value = 'Conexão salva com sucesso.';
    } catch (e) {
        error.value = e?.response?.data?.message || 'Falha ao salvar.';
    } finally {
        saving.value = false;
    }
}

async function testConnection() {
    testing.value = true;
    message.value = '';
    error.value = '';
    try {
        const { data } = await axios.post('/ai-member/connection/test');
        if (data?.success) {
            message.value = data.message || 'Conexão OK.';
            await loadConnection();
        } else {
            error.value = data?.message || 'Falha no teste.';
        }
    } catch (e) {
        error.value = e?.response?.data?.message || 'Falha no teste.';
    } finally {
        testing.value = false;
    }
}

onMounted(loadConnection);

const inputClass = 'w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800';
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-8">
        <div class="flex items-start gap-4">
            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-indigo-100 dark:bg-indigo-950">
                <Bot class="h-8 w-8 text-indigo-600" />
            </div>
            <div>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">AI Member</h1>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    Chat de suporte com IA na área de membros. Configure o OpenRouter aqui e o agente em cada produto.
                </p>
            </div>
        </div>

        <!-- Passo 1: OpenRouter -->
        <section class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900/40">
            <div class="mb-4 flex items-center gap-2">
                <Key class="h-5 w-5 text-indigo-600" />
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">1. OpenRouter</h2>
                <span
                    v-if="connection.configured && connection.is_active"
                    class="ml-auto inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
                >
                    <CheckCircle class="h-3.5 w-3.5" /> Conectado
                </span>
                <span
                    v-else
                    class="ml-auto inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-300"
                >
                    <AlertCircle class="h-3.5 w-3.5" /> Pendente
                </span>
            </div>

            <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
                Obtenha sua API key em
                <a href="https://openrouter.ai/keys" target="_blank" rel="noopener" class="inline-flex items-center gap-0.5 text-indigo-600 hover:underline">
                    openrouter.ai/keys
                    <ExternalLink class="h-3.5 w-3.5" />
                </a>
            </p>

            <div v-if="loading" class="flex justify-center py-6">
                <Loader2 class="h-6 w-6 animate-spin text-zinc-400" />
            </div>

            <div v-else class="space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">API Key</label>
                    <input
                        v-model="form.api_key"
                        type="password"
                        :class="inputClass"
                        :placeholder="connection.has_token ? '•••••••• (deixe em branco para manter)' : 'sk-or-...'"
                    />
                </div>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input v-model="form.is_active" type="checkbox" class="rounded" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300">Integração ativa</span>
                </label>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium dark:border-zinc-600"
                        :disabled="testing || !connection.configured"
                        @click="testConnection"
                    >
                        <Loader2 v-if="testing" class="inline h-4 w-4 animate-spin" />
                        <span v-else>Testar conexão</span>
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                        :disabled="saving"
                        @click="save"
                    >
                        <Loader2 v-if="saving" class="inline h-4 w-4 animate-spin" />
                        <span v-else>Salvar</span>
                    </button>
                </div>
            </div>
        </section>

        <!-- Passo 2: Produtos -->
        <section class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900/40">
            <div class="mb-4 flex items-center gap-2">
                <BookOpen class="h-5 w-5 text-indigo-600" />
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">2. Agente por produto</h2>
            </div>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Abra a edição de um produto do tipo <strong>área de membros</strong> e vá na aba
                <strong>AI Member</strong> para ativar o widget, personalizar o agente e sincronizar o conhecimento das aulas.
            </p>
            <a
                href="/produtos"
                class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:underline"
            >
                Ir para Produtos
                <ExternalLink class="h-3.5 w-3.5" />
            </a>
        </section>

        <div v-if="message" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-950/40">{{ message }}</div>
        <div v-if="error" class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/40">{{ error }}</div>
    </div>
</template>
