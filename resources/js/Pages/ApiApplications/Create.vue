<script setup>
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import GatewayRedundancySidebar from '@/components/produtos/GatewayRedundancySidebar.vue';
import { ChevronDown, Settings2, Palette } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    gateways_by_method: { type: Object, default: () => ({}) },
    default_payment_gateways: { type: Object, default: () => ({}) },
});

const pg = props.default_payment_gateways || {};
const form = useForm({
    name: '',
    payment_gateways: {
        pix: pg.pix ?? '',
        pix_redundancy: Array.isArray(pg.pix_redundancy) ? pg.pix_redundancy : [],
        card: pg.card ?? '',
        card_redundancy: Array.isArray(pg.card_redundancy) ? pg.card_redundancy : [],
        boleto: pg.boleto ?? '',
        boleto_redundancy: Array.isArray(pg.boleto_redundancy) ? pg.boleto_redundancy : [],
        pix_auto: pg.pix_auto ?? '',
        pix_auto_redundancy: Array.isArray(pg.pix_auto_redundancy) ? pg.pix_auto_redundancy : [],
        crypto: pg.crypto ?? '',
        crypto_redundancy: Array.isArray(pg.crypto_redundancy) ? pg.crypto_redundancy : [],
    },
    webhook_url: '',
    default_return_url: '',
    webhook_secret: '',
    allowed_ips: '',
    is_active: true,
    checkout_sidebar_bg: '',
});

function gatewayOptions(method) {
    const list = props.gateways_by_method?.[method] ?? [];
    return [
        { value: '', label: 'Nenhum' },
        ...list.map((g) => ({ value: g.slug, label: g.name })),
    ];
}

const METHOD_LABELS = { pix: 'PIX', card: 'Cartão', boleto: 'Boleto', pix_auto: 'PIX automático', crypto: 'Criptomoeda' };
const redundancySidebarOpen = ref(false);
const redundancySidebarMethod = ref(null);

function openRedundancySidebar(method) {
    redundancySidebarMethod.value = method;
    redundancySidebarOpen.value = true;
}

function canShowRedundancy(slug) {
    return slug !== '' && slug != null;
}

function submit() {
    form.post('/aplicacoes-api');
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Nova aplicação API</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Configure os gateways que esta aplicação poderá usar para processar pagamentos.
            </p>
        </div>

        <form class="max-w-2xl space-y-6" @submit.prevent="submit">
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nome</label>
                <input v-model="form.name" type="text" required class="mt-1 block w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2" placeholder="Ex.: Loja XYZ" />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
            </div>

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50/50 dark:bg-zinc-800/50 p-4">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                    <Palette class="h-4 w-4" />
                    Cor de fundo do checkout
                </h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Cor da coluna esquerda (resumo) no Checkout Pro.</p>
                <div class="mt-4 flex flex-wrap items-center gap-4">
                    <input
                        :value="form.checkout_sidebar_bg || '#18181b'"
                        type="color"
                        class="h-10 w-14 cursor-pointer rounded border border-zinc-300 bg-white p-1 dark:border-zinc-600"
                        :title="form.checkout_sidebar_bg || '#18181b'"
                        @input="form.checkout_sidebar_bg = $event.target.value"
                    />
                    <input
                        v-model="form.checkout_sidebar_bg"
                        type="text"
                        class="rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2 font-mono text-sm w-28"
                        placeholder="#18181b"
                        maxlength="7"
                    />
                    <Button type="button" variant="outline" size="sm" @click="form.checkout_sidebar_bg = ''">
                        Restaurar padrão
                    </Button>
                </div>
                <p v-if="form.errors.checkout_sidebar_bg" class="mt-2 text-sm text-red-600">{{ form.errors.checkout_sidebar_bg }}</p>
            </div>

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50/50 dark:bg-zinc-800/50 p-4">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                    <Settings2 class="h-4 w-4" />
                    Gateways por método de pagamento
                </h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Selecione o gateway principal e a ordem de redundância para cada método.
                </p>
                <div class="mt-4 space-y-3">
                    <template v-for="method in ['pix', 'card', 'boleto', 'pix_auto', 'crypto']" :key="method">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="w-24 text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ METHOD_LABELS[method] || method }}</span>
                            <select
                                v-model="form.payment_gateways[method]"
                                class="rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2 text-sm min-w-[160px]"
                            >
                                <option v-for="opt in gatewayOptions(method)" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>
                            <Button
                                v-if="canShowRedundancy(form.payment_gateways[method])"
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="openRedundancySidebar(method)"
                            >
                                Redundância
                            </Button>
                        </div>
                    </template>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">URL do webhook (opcional)</label>
                <input v-model="form.webhook_url" type="url" class="mt-1 block w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2" placeholder="https://..." />
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Receberá notificações de pagamento (order.completed, etc.).</p>
                <p v-if="form.errors.webhook_url" class="mt-1 text-sm text-red-600">{{ form.errors.webhook_url }}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">URL de retorno padrão (opcional)</label>
                <input v-model="form.default_return_url" type="url" class="mt-1 block w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2" placeholder="https://..." />
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Usada no Checkout Pro quando a sessão não enviar <span class="font-mono">return_url</span>.
                </p>
                <p v-if="form.errors.default_return_url" class="mt-1 text-sm text-red-600">{{ form.errors.default_return_url }}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Webhook secret (opcional)</label>
                <input v-model="form.webhook_secret" type="password" autocomplete="off" class="mt-1 block w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2" placeholder="Secret para validar assinatura HMAC" />
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Usado para assinar o body do webhook (X-Getfy-Signature). Recomendado para produção.</p>
                <p v-if="form.errors.webhook_secret" class="mt-1 text-sm text-red-600">{{ form.errors.webhook_secret }}</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">IPs permitidos (opcional)</label>
                <textarea v-model="form.allowed_ips" rows="3" class="mt-1 block w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2" placeholder="Um IP por linha ou separados por vírgula. Vazio = todos permitidos."></textarea>
                <p v-if="form.errors.allowed_ips" class="mt-1 text-sm text-red-600">{{ form.errors.allowed_ips }}</p>
            </div>

            <div class="flex items-center gap-2">
                <input v-model="form.is_active" type="checkbox" id="is_active" class="h-4 w-4 rounded border-zinc-300" />
                <label for="is_active" class="text-sm text-zinc-700 dark:text-zinc-300">Aplicação ativa</label>
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing">Criar aplicação</Button>
                <Button as="a" href="/aplicacoes-api" variant="outline">Cancelar</Button>
            </div>
        </form>

        <GatewayRedundancySidebar
            :open="redundancySidebarOpen"
            :method="redundancySidebarMethod"
            :method-label="METHOD_LABELS[redundancySidebarMethod] || redundancySidebarMethod"
            :primary-slug="redundancySidebarMethod ? (form.payment_gateways[redundancySidebarMethod] || '') : ''"
            :gateways="gateways_by_method[redundancySidebarMethod] || []"
            :model-value="redundancySidebarMethod ? (form.payment_gateways[redundancySidebarMethod + '_redundancy'] || []) : []"
            @update:model-value="(val) => redundancySidebarMethod && (form.payment_gateways[redundancySidebarMethod + '_redundancy'] = val)"
            @save="(val) => { if (redundancySidebarMethod) { form.payment_gateways[redundancySidebarMethod + '_redundancy'] = val; } redundancySidebarOpen = false; }"
            @close="redundancySidebarOpen = false"
        />
    </div>
</template>
