<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import VisualEmailEditor from '@/components/email/VisualEmailEditor.vue';
import axios from 'axios';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    campaign: {
        type: Object,
        required: true,
    },
    email_configured: { type: Boolean, default: false },
    products: { type: Array, default: () => [] },
    default_body_html: { type: String, default: '' },
});

const form = useForm({
    name: props.campaign.name,
    subject: props.campaign.subject,
    body_html: props.campaign.body_html || '',
    filter_config: props.campaign.filter_config || { all_customers: true, product_ids: [] },
});

const recipientCount = ref(null);
const recipientSample = ref([]);
const loadingRecipients = ref(false);
const campaignPlaceholders = [
    { value: '{nome}', label: 'Nome do cliente' },
    { value: '{email}', label: 'E-mail do cliente' },
];

function useDefaultTemplate() {
    form.body_html = props.default_body_html || '';
}

async function previewRecipients() {
    loadingRecipients.value = true;
    recipientCount.value = null;
    recipientSample.value = [];
    try {
        const res = await axios.post(
            '/email-marketing/preview-recipients',
            { filter_config: form.filter_config },
            { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } }
        );
        if (res && res.data) {
            recipientCount.value = res.data.count;
            recipientSample.value = res.data.sample || [];
        }
    } catch (_) {
        recipientCount.value = 0;
    } finally {
        loadingRecipients.value = false;
    }
}

function confirmSend() {
    if (!props.email_configured) return;
    if (!confirm('Disparar esta campanha? Os e-mails serão enviados em lotes de 30 por minuto.')) return;
    router.post(`/email-marketing/${props.campaign.id}/send`);
}
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Editar campanha</h1>
            <Link href="/email-marketing" class="text-sm text-zinc-600 hover:underline dark:text-zinc-400">Voltar</Link>
        </div>

        <div
            v-if="!email_configured"
            class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30"
        >
            <p class="text-sm font-medium text-amber-800 dark:text-amber-200">
                Configure o e-mail em <Link href="/configuracoes" class="underline">Configurações &gt; E-mail</Link> antes
                de disparar.
            </p>
        </div>

        <form
            class="panel-card-lg space-y-6"
            @submit.prevent="form.put(`/email-marketing/${campaign.id}`)"
        >
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nome da campanha</label>
                <input
                    v-model="form.name"
                    type="text"
                    required
                    class="mt-1 block w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2"
                />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Assunto do e-mail</label>
                <input
                    v-model="form.subject"
                    type="text"
                    required
                    class="mt-1 block w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2"
                />
                <p v-if="form.errors.subject" class="mt-1 text-sm text-red-600">{{ form.errors.subject }}</p>
            </div>
            <div>
                <div class="flex items-center justify-between">
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Corpo do e-mail</label>
                    <Button type="button" variant="secondary" size="sm" @click="useDefaultTemplate">Usar template padrão</Button>
                </div>
                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Use {nome} e {email} para personalizar.</p>
                <VisualEmailEditor
                    v-model="form.body_html"
                    class="mt-2"
                    :placeholders="campaignPlaceholders"
                    required
                />
                <p v-if="form.errors.body_html" class="mt-1 text-sm text-red-600">{{ form.errors.body_html }}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Destinatários</label>
                <div class="mt-2 space-y-2">
                    <label class="flex items-center gap-2">
                        <input v-model="form.filter_config.all_customers" type="radio" :value="true" class="h-4 w-4" />
                        <span class="text-sm">Todos os compradores</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input v-model="form.filter_config.all_customers" type="radio" :value="false" class="h-4 w-4" />
                        <span class="text-sm">Compradores de produto(s) específico(s)</span>
                    </label>
                    <div v-if="form.filter_config.all_customers === false" class="ml-6 mt-2">
                        <select
                            v-model="form.filter_config.product_ids"
                            multiple
                            class="block w-full max-w-md rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2 text-sm"
                        >
                            <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                    </div>
                </div>
                <div class="mt-3">
                    <Button type="button" variant="secondary" size="sm" :disabled="loadingRecipients" @click="previewRecipients">
                        {{ loadingRecipients ? 'Carregando...' : 'Ver destinatários' }}
                    </Button>
                    <span v-if="recipientCount !== null" class="ml-2 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ recipientCount }} destinatário(s)
                    </span>
                    <ul v-if="recipientSample.length" class="mt-2 list-inside list-disc text-xs text-zinc-500 dark:text-zinc-400">
                        <li v-for="(r, i) in recipientSample" :key="i">{{ r.email }} ({{ r.name }})</li>
                    </ul>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <Button type="submit" variant="primary" :disabled="form.processing">Salvar</Button>
                <Button
                    v-if="email_configured"
                    type="button"
                    variant="primary"
                    :disabled="form.processing"
                    @click="confirmSend"
                >
                    Disparar campanha
                </Button>
                <Link href="/email-marketing">
                    <Button type="button" variant="outline">Cancelar</Button>
                </Link>
            </div>
        </form>
    </div>
</template>
