<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';
import { X, Loader2, ExternalLink } from 'lucide-vue-next';

const props = defineProps({
    open: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'saved']);

const form = ref({ api_key: '', is_active: true });
const connection = ref({
    configured: false,
    is_active: false,
    has_token: false,
    last_tested_at: null,
    last_error: null,
});
const loading = ref(false);
const saving = ref(false);
const testing = ref(false);
const errorMessage = ref(null);
const successMessage = ref(null);

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            loadConnection();
        }
    },
    { immediate: true },
);

async function loadConnection() {
    loading.value = true;
    errorMessage.value = null;
    try {
        const { data } = await axios.get('/ai-member/connection');
        connection.value = data.connection ?? connection.value;
        form.value.is_active = connection.value.is_active ?? true;
        form.value.api_key = '';
    } catch (e) {
        errorMessage.value = e?.response?.data?.message || 'Não foi possível carregar a conexão.';
    } finally {
        loading.value = false;
    }
}

async function save() {
    errorMessage.value = null;
    successMessage.value = null;
    if (!form.value.api_key?.trim() && !connection.value.configured) {
        errorMessage.value = 'Informe a API key do OpenRouter.';
        return;
    }
    saving.value = true;
    try {
        const { data } = await axios.put('/ai-member/connection', {
            api_key: form.value.api_key?.trim() || undefined,
            is_active: form.value.is_active,
        });
        connection.value = data.connection ?? connection.value;
        form.value.api_key = '';
        successMessage.value = 'Conexão salva com sucesso.';
        emit('saved');
    } catch (e) {
        errorMessage.value = e?.response?.data?.message || 'Falha ao salvar.';
    } finally {
        saving.value = false;
    }
}

async function testConnection() {
    errorMessage.value = null;
    successMessage.value = null;
    testing.value = true;
    try {
        const { data } = await axios.post('/ai-member/connection/test');
        if (data?.success) {
            successMessage.value = data.message || 'Conexão OK.';
            await loadConnection();
            emit('saved');
        } else {
            errorMessage.value = data?.message || 'Falha no teste.';
        }
    } catch (e) {
        errorMessage.value = e?.response?.data?.message || 'Falha no teste.';
    } finally {
        testing.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[200] flex justify-end">
            <div class="absolute inset-0 bg-black/40" @click="emit('close')" />
            <aside class="relative flex h-full w-full max-w-md flex-col bg-white shadow-xl dark:bg-zinc-900">
                <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">OpenRouter</h2>
                        <p class="text-sm text-zinc-500">Credenciais para o AI Member</p>
                    </div>
                    <button type="button" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" @click="emit('close')">
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto p-5 space-y-5">
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">
                        Obtenha sua API key em
                        <a href="https://openrouter.ai/keys" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-indigo-600 hover:underline">
                            openrouter.ai/keys
                            <ExternalLink class="h-3.5 w-3.5" />
                        </a>
                    </p>

                    <div v-if="loading" class="flex justify-center py-8">
                        <Loader2 class="h-6 w-6 animate-spin text-zinc-400" />
                    </div>

                    <template v-else>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">API Key</label>
                            <input
                                v-model="form.api_key"
                                type="password"
                                class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                                :placeholder="connection.has_token ? '•••••••• (deixe em branco para manter)' : 'sk-or-...'"
                            />
                        </div>

                        <label class="flex items-center gap-3 cursor-pointer">
                            <input v-model="form.is_active" type="checkbox" class="rounded border-zinc-300" />
                            <span class="text-sm text-zinc-700 dark:text-zinc-300">Integração ativa</span>
                        </label>

                        <div v-if="connection.last_tested_at" class="text-xs text-zinc-500">
                            Último teste: {{ new Date(connection.last_tested_at).toLocaleString('pt-BR') }}
                        </div>
                        <div v-if="connection.last_error" class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">
                            {{ connection.last_error }}
                        </div>
                        <div v-if="errorMessage" class="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">
                            {{ errorMessage }}
                        </div>
                        <div v-if="successMessage" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                            {{ successMessage }}
                        </div>
                    </template>
                </div>

                <div class="flex gap-2 border-t border-zinc-200 p-5 dark:border-zinc-700">
                    <button
                        type="button"
                        class="flex-1 rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium dark:border-zinc-600"
                        :disabled="testing || !connection.configured"
                        @click="testConnection"
                    >
                        <Loader2 v-if="testing" class="mx-auto h-4 w-4 animate-spin" />
                        <span v-else>Testar</span>
                    </button>
                    <button
                        type="button"
                        class="flex-1 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                        :disabled="saving"
                        @click="save"
                    >
                        <Loader2 v-if="saving" class="mx-auto h-4 w-4 animate-spin" />
                        <span v-else>Salvar</span>
                    </button>
                </div>
            </aside>
        </div>
    </Teleport>
</template>
