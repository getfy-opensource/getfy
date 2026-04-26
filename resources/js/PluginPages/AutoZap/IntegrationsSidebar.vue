<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';

const emit = defineEmits(['saved', 'close']);

const loading = ref(true);
const saving = ref(false);
const error = ref('');
const ok = ref('');

const provider = ref('zapi');
const isActive = ref(true);
const secretStatusByProvider = ref(null);

const secretStatus = computed(() => {
    const map = secretStatusByProvider.value;
    if (!map || typeof map !== 'object') return null;
    return map[provider.value] || null;
});

const zapi = ref({
    base_url: 'https://api.z-api.io',
    instance_id: '',
    token: '',
    client_token: '',
});

const evolution = ref({
    base_url: '',
    apikey: '',
    instance: '',
});

const menuia = ref({
    appkey: '',
    authkey: '',
    device: '',
});

const credentials = computed(() => {
    if (provider.value === 'zapi') return zapi.value;
    if (provider.value === 'evolution') return evolution.value;
    return menuia.value;
});

async function load() {
    loading.value = true;
    error.value = '';
    ok.value = '';
    try {
        const { data } = await axios.get('/autozap/connection');
        if (data?.provider) provider.value = data.provider;
        if (typeof data?.connected === 'boolean') {
            isActive.value = data.connected;
        }
        const safeBy = data?.safe_credentials_by_provider || null;
        secretStatusByProvider.value = safeBy;

        const safeZ = safeBy?.zapi || null;
        if (safeZ) {
            if (safeZ.base_url) zapi.value.base_url = safeZ.base_url;
            if (safeZ.instance_id) zapi.value.instance_id = safeZ.instance_id;
        }
        const safeE = safeBy?.evolution || null;
        if (safeE) {
            if (safeE.base_url) evolution.value.base_url = safeE.base_url;
            if (safeE.instance) evolution.value.instance = safeE.instance;
        }
        const safeM = safeBy?.menuia || null;
        if (safeM) {
            if (safeM.device) menuia.value.device = safeM.device;
        }
    } catch (e) {
        error.value = e.response?.data?.message || 'Não foi possível carregar a configuração.';
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;
    error.value = '';
    ok.value = '';
    try {
        await axios.post('/autozap/connection', {
            provider: provider.value,
            is_active: isActive.value,
            credentials: credentials.value,
        });
        ok.value = 'Configuração salva.';
        emit('saved');
    } catch (e) {
        error.value = e.response?.data?.message || 'Erro ao salvar.';
    } finally {
        saving.value = false;
    }
}

load();
</script>

<template>
    <div class="space-y-4">
        <div>
            <h3 class="text-base font-semibold text-zinc-900 dark:text-white">Configurar AutoZap</h3>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Conecte uma API de WhatsApp para disparar mensagens automaticamente por eventos.
            </p>
        </div>

        <div v-if="loading" class="text-sm text-zinc-500 dark:text-zinc-400">Carregando…</div>

        <div v-else class="space-y-4">
            <div class="flex items-center justify-between rounded-xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                <div>
                    <div class="font-medium text-zinc-900 dark:text-white">Ativar AutoZap</div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">Quando ativo, os fluxos podem disparar.</div>
                </div>
                <input v-model="isActive" type="checkbox" class="h-4 w-4" />
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Provedor</label>
                <select v-model="provider" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <option value="zapi">Z-API</option>
                    <option value="evolution">Evolution API</option>
                    <option value="menuia">MenuIA</option>
                </select>
            </div>

            <div v-if="provider === 'zapi'" class="space-y-3">
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Base URL</label>
                    <input v-model="zapi.base_url" type="url" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900" />
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Instance ID</label>
                        <input v-model="zapi.instance_id" type="text" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Token</label>
                        <input
                            v-model="zapi.token"
                            type="password"
                            :placeholder="secretStatus?.token_masked ? `Salvo: ${secretStatus.token_masked}` : ''"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm placeholder:text-zinc-400 dark:border-zinc-700 dark:bg-zinc-900 dark:placeholder:text-zinc-500"
                        />
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Client-Token (opcional)</label>
                    <input
                        v-model="zapi.client_token"
                        type="password"
                        :placeholder="secretStatus?.client_token_masked ? `Salvo: ${secretStatus.client_token_masked}` : ''"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm placeholder:text-zinc-400 dark:border-zinc-700 dark:bg-zinc-900 dark:placeholder:text-zinc-500"
                    />
                </div>
            </div>

            <div v-else-if="provider === 'evolution'" class="space-y-3">
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Base URL</label>
                    <input v-model="evolution.base_url" type="url" placeholder="https://seu-evolution-api.com" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900" />
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">API Key</label>
                        <input
                            v-model="evolution.apikey"
                            type="password"
                            :placeholder="secretStatus?.apikey_masked ? `Salva: ${secretStatus.apikey_masked}` : ''"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm placeholder:text-zinc-400 dark:border-zinc-700 dark:bg-zinc-900 dark:placeholder:text-zinc-500"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Instance</label>
                        <input v-model="evolution.instance" type="text" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900" />
                    </div>
                </div>
            </div>

            <div v-else class="space-y-3">
                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-300">
                    A MenuIA usa <span class="font-mono">AppKey</span> (dispositivo/instância) + <span class="font-mono">AuthKey</span> (usuário).
                    O teste de conexão valida o status do dispositivo.
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">AppKey</label>
                        <input
                            v-model="menuia.appkey"
                            type="password"
                            :placeholder="secretStatus?.appkey_masked ? `Salva: ${secretStatus.appkey_masked}` : ''"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm placeholder:text-zinc-400 dark:border-zinc-700 dark:bg-zinc-900 dark:placeholder:text-zinc-500"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">AuthKey</label>
                        <input
                            v-model="menuia.authkey"
                            type="password"
                            :placeholder="secretStatus?.authkey_masked ? `Salva: ${secretStatus.authkey_masked}` : ''"
                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm placeholder:text-zinc-400 dark:border-zinc-700 dark:bg-zinc-900 dark:placeholder:text-zinc-500"
                        />
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">ID ou nome do dispositivo</label>
                    <input v-model="menuia.device" type="text" placeholder="Ex.: Dispositivo 1" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900" />
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        Usado no teste de conexão (status do dispositivo).
                    </p>
                </div>
            </div>

            <div class="flex gap-2">
                <Button type="button" :disabled="saving" class="flex-1" @click="save">Salvar</Button>
            </div>

            <p v-if="error" class="rounded-lg bg-red-100 px-3 py-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">
                {{ error }}
            </p>
            <p v-else-if="ok" class="rounded-lg bg-emerald-100 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200">
                {{ ok }}
            </p>
        </div>
    </div>
</template>

