<script setup>
import { ref, onMounted, nextTick } from 'vue';
import LayoutDoc from '@/Layouts/LayoutDoc.vue';
import DocSection from '@/components/docs/DocSection.vue';
import DocEndpoint from '@/components/docs/DocEndpoint.vue';
import DocCode from '@/components/docs/DocCode.vue';
import DocTable from '@/components/docs/DocTable.vue';
import DocCallout from '@/components/docs/DocCallout.vue';
import {
    BookOpen,
    Key,
    Layers,
    ShoppingCart,
    CreditCard,
    Webhook,
    FileCode,
    Menu,
    X,
    ChevronRight,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutDoc });

const props = defineProps({
    baseUrl: { type: String, default: '' },
});

const SIDEBAR_GROUPS = [
    {
        title: 'Introdução',
        icon: BookOpen,
        items: [
            { title: 'Início rápido', id: 'inicio-rapido' },
            { title: 'Visão geral', id: 'visao-geral' },
            { title: 'Quando usar cada um', id: 'quando-usar' },
        ],
    },
    {
        title: 'Autenticação',
        icon: Key,
        items: [
            { title: 'Envio da API key', id: 'envio-api-key' },
            { title: 'Obtenção da API key', id: 'obtencao-api-key' },
            { title: 'Segurança', id: 'seguranca' },
        ],
    },
    {
        title: 'Aplicações',
        icon: Layers,
        items: [
            { title: 'O que é uma aplicação', id: 'o-que-e-aplicacao' },
            { title: 'Configuração', id: 'configuracao' },
        ],
    },
    {
        title: 'Checkout Pro',
        icon: ShoppingCart,
        items: [
            { title: 'POST /checkout/sessions', id: 'post-checkout-sessions' },
        ],
    },
    {
        title: 'Checkout Transparente',
        icon: CreditCard,
        items: [
            { title: 'Dados comuns (customer)', id: 'dados-comuns-customer' },
            { title: 'POST /payments/pix', id: 'post-payments-pix' },
            { title: 'POST /payments/card', id: 'post-payments-card' },
            { title: 'POST /payments/boleto', id: 'post-payments-boleto' },
            { title: 'GET /payments/{order_id}', id: 'get-payments-order-id' },
            { title: 'Idempotência', id: 'idempotencia' },
        ],
    },
    {
        title: 'Webhooks',
        icon: Webhook,
        items: [
            { title: 'Eventos', id: 'webhooks-eventos' },
            { title: 'Formato do payload', id: 'webhooks-formato' },
            { title: 'Assinatura (X-Getfy-Signature)', id: 'webhooks-assinatura' },
            { title: 'Boas práticas', id: 'webhooks-boas-praticas' },
        ],
    },
    {
        title: 'Referência',
        icon: FileCode,
        items: [
            { title: 'Códigos de erro', id: 'codigos-de-erro' },
            { title: 'Boas práticas gerais', id: 'boas-praticas' },
            { title: 'Resumo de endpoints', id: 'resumo-endpoints' },
        ],
    },
];

const contentRef = ref(null);
const activeId = ref('');
const sidebarOpen = ref(false);

function scrollToId(id) {
    const el = document.getElementById(id);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    sidebarOpen.value = false;
}

function setActiveFromScroll() {
    if (!contentRef.value) return;
    const sections = contentRef.value.querySelectorAll('[id]');
    const ids = [...sections].map((el) => el.id).filter(Boolean);
    const observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (!entry.isIntersecting) continue;
                const id = entry.target.id;
                if (id) activeId.value = id;
            }
        },
        { rootMargin: '-80px 0px -70% 0px', threshold: 0 }
    );
    sections.forEach((el) => el.id && observer.observe(el));
}

onMounted(() => {
    nextTick(setActiveFromScroll);
});

const apiBase = props.baseUrl ? `${props.baseUrl.replace(/\/$/, '')}/api/v1` : '/api/v1';
const hostForExample = props.baseUrl ? props.baseUrl.replace(/^https?:\/\//, '').replace(/\/$/, '') : 'sua-instancia.getfy.com';
const checkoutBaseUrl = props.baseUrl ? props.baseUrl.replace(/\/$/, '') : 'https://sua-instancia.getfy.com';
const endpointSummaryRows = [
    { method: 'POST', endpoint: '/api/v1/checkout/sessions', desc: 'Criar sessão Checkout Pro (retorna link)' },
    { method: 'POST', endpoint: '/api/v1/payments/pix', desc: 'Criar pagamento PIX' },
    { method: 'POST', endpoint: '/api/v1/payments/card', desc: 'Criar pagamento com cartão' },
    { method: 'POST', endpoint: '/api/v1/payments/boleto', desc: 'Criar pagamento com boleto' },
    { method: 'GET', endpoint: '/api/v1/payments/{order_id}', desc: 'Consultar status do pedido' },
];
const quandoUsarRows = [
    { cenario: 'Redirecionar o cliente para uma página de pagamento da Getfy', sugestao: 'Checkout Pro' },
    { cenario: 'Manter checkout no seu site (iframe, SPA, app)', sugestao: 'Checkout Transparente' },
    { cenario: 'Apenas processar pagamento (sem produto Getfy)', sugestao: 'Ambos suportam amount/currency sem product_id' },
];
const checkoutSessionsBodyColumns = [
    { key: 'field', label: 'Campo' },
    { key: 'type', label: 'Tipo' },
    { key: 'required', label: 'Obrigatório' },
    { key: 'desc', label: 'Descrição' },
];
const checkoutSessionsBodyRows = [
    { field: 'customer', type: 'objeto', required: 'Sim', desc: 'Dados do cliente' },
    { field: 'customer.email', type: 'string', required: 'Sim', desc: 'E-mail' },
    { field: 'customer.name', type: 'string', required: 'Não', desc: 'Nome (default: email)' },
    { field: 'customer.cpf', type: 'string', required: 'Não', desc: 'CPF' },
    { field: 'customer.phone', type: 'string', required: 'Não', desc: 'Telefone' },
    { field: 'amount', type: 'number', required: 'Sim', desc: 'Valor (ex.: 97.90)' },
    { field: 'currency', type: 'string', required: 'Não', desc: 'BRL, USD ou EUR (default: BRL)' },
    { field: 'product_id', type: 'string (UUID)', required: 'Não', desc: 'ID do produto Getfy; se informado, o pedido fica vinculado e o acesso é concedido ao concluir' },
    { field: 'product_offer_id', type: 'integer', required: 'Não', desc: 'ID da oferta do produto' },
    { field: 'subscription_plan_id', type: 'integer', required: 'Não', desc: 'ID do plano de assinatura' },
    { field: 'metadata', type: 'objeto', required: 'Não', desc: 'Dados livres (ex.: external_id) para uso no webhook' },
    { field: 'return_url', type: 'string', required: 'Não', desc: 'URL final para voltar ao seu site após concluir; se omitida, usa a URL de retorno padrão da aplicação' },
    { field: 'expires_in', type: 'integer', required: 'Não', desc: 'Minutos até expirar a sessão (5–1440; default: 30)' },
];
const customerColumns = [
    { key: 'field', label: 'Campo' },
    { key: 'type', label: 'Tipo' },
    { key: 'required', label: 'Obrigatório' },
    { key: 'desc', label: 'Descrição' },
];
const customerRows = [
    { field: 'customer', type: 'objeto', required: 'Sim', desc: 'Dados do cliente' },
    { field: 'customer.email', type: 'string', required: 'Sim', desc: 'E-mail' },
    { field: 'customer.name', type: 'string', required: 'Não', desc: 'Nome' },
    { field: 'customer.cpf', type: 'string', required: 'Não', desc: 'CPF' },
    { field: 'customer.phone', type: 'string', required: 'Não', desc: 'Telefone' },
];
const errorCodesColumns = [{ key: 'code', label: 'Código' }, { key: 'meaning', label: 'Significado' }];
const errorCodesRows = [
    { code: '401', meaning: 'Chave de API ausente ou inválida (header Bearer / X-API-Key).' },
    { code: '403', meaning: 'Aplicação inativa ou IP não permitido.' },
    { code: '404', meaning: 'Recurso não encontrado (ex.: pedido que não pertence à aplicação).' },
    { code: '422', meaning: 'Validação falhou (dados inválidos, produto não encontrado, etc.). O body pode incluir message e detalhes.' },
    { code: '429', meaning: 'Muitas requisições (rate limit). Tente novamente após o tempo indicado nos headers de resposta.' },
    { code: '500', meaning: 'Erro interno do servidor.' },
];
const webhookEventsColumns = [{ key: 'event', label: 'Evento' }, { key: 'desc', label: 'Descrição' }];
const webhookEventsRows = [
    { event: 'order.completed', desc: 'Pagamento concluído (pedido pago)' },
    { event: 'order.pending', desc: 'Pedido criado ou aguardando pagamento (ex.: PIX/boleto gerados)' },
    { event: 'order.refunded', desc: 'Pedido estornado' },
];
</script>

<template>
    <div class="api-docs relative flex min-h-0 flex-col lg:flex-row lg:gap-12">
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-40 bg-black/60 backdrop-blur-sm lg:hidden"
            aria-hidden="true"
            @click="sidebarOpen = false"
        />

        <aside
            class="api-docs-sidebar fixed left-0 top-0 z-50 h-full w-72 shrink-0 border-r border-white/5 bg-zinc-900/98 shadow-2xl transition-transform duration-200 lg:static lg:z-auto lg:h-auto lg:w-64 lg:translate-x-0 lg:border-r lg:border-white/5 lg:bg-transparent lg:shadow-none"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
        >
            <div class="flex h-14 items-center justify-between border-b border-white/5 px-4 lg:hidden">
                <span class="text-sm font-semibold text-white">Menu</span>
                <button type="button" class="rounded-lg p-2 text-zinc-400 hover:bg-white/5 hover:text-white" aria-label="Fechar menu" @click="sidebarOpen = false">
                    <X class="h-5 w-5" />
                </button>
            </div>
            <nav class="overflow-y-auto py-6 pl-4 pr-3 lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)]">
                <div class="space-y-8">
                    <div v-for="group in SIDEBAR_GROUPS" :key="group.title" class="space-y-2">
                        <div class="flex items-center gap-2 px-2 pb-1">
                            <component :is="group.icon" class="h-4 w-4 shrink-0 text-teal-400/90" />
                            <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500">
                                {{ group.title }}
                            </span>
                        </div>
                        <ul class="space-y-0.5">
                            <li v-for="item in group.items" :key="item.id">
                                <a
                                    :href="`#${item.id}`"
                                    class="doc-nav-link flex cursor-pointer items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition"
                                    :class="activeId === item.id ? 'bg-teal-500/15 text-teal-300' : 'text-zinc-300 hover:bg-white/5 hover:text-white'"
                                    @click.prevent="scrollToId(item.id)"
                                >
                                    {{ item.title }}
                                    <ChevronRight class="h-3.5 w-3.5 shrink-0 opacity-70" />
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </nav>
        </aside>

        <div ref="contentRef" class="min-w-0 flex-1 lg:pl-0">
            <div class="sticky top-14 z-30 flex items-center justify-between border-b border-white/5 bg-zinc-900/95 px-4 py-3 backdrop-blur-sm lg:hidden">
                <button type="button" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-zinc-300 hover:bg-white/5 hover:text-white" @click="sidebarOpen = true">
                    <Menu class="h-5 w-5" />
                    Menu
                </button>
            </div>

            <div class="border-b border-white/5 px-4 pb-8 pt-6 lg:px-0 lg:pt-2">
                <div class="mx-auto max-w-3xl">
                    <h1 class="text-2xl font-bold tracking-tight text-white sm:text-3xl">
                        API de Pagamentos
                    </h1>
                    <p class="mt-2 text-base leading-relaxed text-zinc-400">
                        Integre pagamentos (PIX, cartão, boleto) na sua plataforma usando os gateways configurados na Getfy.
                        Checkout hospedado ou transparente - você escolhe.
                    </p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <span class="rounded-full bg-teal-500/20 px-3 py-1 text-xs font-medium text-teal-300">REST</span>
                        <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-medium text-zinc-400">Bearer / X-API-Key</span>
                        <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-medium text-zinc-400">JSON</span>
                    </div>
                </div>
            </div>

            <div class="mx-auto max-w-3xl px-4 pt-8 lg:px-0">
                <div class="rounded-xl border border-teal-500/30 bg-teal-500/10 px-4 py-4">
                    <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                        <span class="text-xs font-semibold uppercase tracking-wider text-teal-400/90">Base URL</span>
                        <code class="text-sm text-zinc-200">{{ apiBase }}</code>
                    </div>
                    <p class="mt-2 text-sm text-zinc-400">
                        Autenticação: <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">Authorization: Bearer &lt;sua_api_key&gt;</code>
                        ou header <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">X-API-Key</code>.
                    </p>
                    <div class="mt-2 flex flex-wrap gap-4">
                        <button type="button" class="text-left text-sm font-medium text-teal-400 hover:text-teal-300" @click="scrollToId('envio-api-key')">
                            Ver detalhes da autenticação →
                        </button>
                    </div>
                </div>
            </div>

            <article class="api-docs-content mx-auto max-w-3xl px-4 pb-24 pt-8 lg:px-6">
                <!-- Introdução -->
                <DocSection id="inicio-rapido" title="Início rápido">
                    <p class="text-zinc-400 leading-relaxed mb-4">
                        <strong class="text-zinc-200">Base URL:</strong> Todas as rotas estão sob <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">/api/v1</code>.
                    </p>
                    <p class="text-zinc-400 leading-relaxed mb-4">
                        <strong class="text-zinc-200">Autenticação:</strong> Envie a API key no header <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">Authorization: Bearer &lt;sua_api_key&gt;</code> ou <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">X-API-Key: &lt;sua_api_key&gt;</code>.
                    </p>
                    <h3 class="doc-h3">Resumo dos endpoints</h3>
                    <DocTable :columns="[{ key: 'method', label: 'Método' }, { key: 'endpoint', label: 'Endpoint' }, { key: 'desc', label: 'Descrição' }]" :rows="endpointSummaryRows" />
                </DocSection>

                <DocSection id="visao-geral" title="Visão geral">
                    <h3 class="doc-h3">Checkout Pro (hospedado)</h3>
                    <ul class="doc-ul">
                        <li>Sua plataforma envia os dados do cliente e do valor para a API.</li>
                        <li>A API devolve um <strong>link de checkout</strong> que o usuário final abre no navegador.</li>
                        <li>Na Getfy o usuário <strong>só escolhe o método</strong> (PIX, boleto) e conclui o pagamento; não preenche nome, e-mail, CPF (já vêm da sessão).</li>
                        <li>Ideal quando você quer delegar toda a tela de pagamento à Getfy.</li>
                    </ul>
                    <h3 class="doc-h3">Checkout Transparente</h3>
                    <ul class="doc-ul">
                        <li>Sua plataforma mantém a <strong>própria UI</strong> (formulário de cartão, exibição de PIX, etc.).</li>
                        <li>Você chama a API para <strong>criar a cobrança</strong> e recebe os dados (QR code PIX, link do boleto, resultado do cartão, etc.).</li>
                        <li>Pode consultar o status do pagamento via <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">GET /api/v1/payments/{order_id}</code>.</li>
                        <li>Ideal quando o fluxo de compra e a identidade visual ficam no seu site/app.</li>
                    </ul>
                </DocSection>

                <DocSection id="quando-usar" title="Quando usar cada um">
                    <DocTable :columns="[{ key: 'cenario', label: 'Cenário' }, { key: 'sugestao', label: 'Sugestão' }]" :rows="quandoUsarRows" />
                </DocSection>

                <!-- Autenticação -->
                <DocSection id="envio-api-key" title="Envio da API key">
                    <p class="text-zinc-400 leading-relaxed mb-4">
                        Todas as requisições à API devem incluir a <strong class="text-zinc-200">API key</strong> da aplicação.
                    </p>
                    <ul class="doc-ul mb-6">
                        <li><strong class="text-zinc-200">Header preferido:</strong> <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">Authorization: Bearer &lt;sua_api_key&gt;</code></li>
                        <li><strong class="text-zinc-200">Alternativa:</strong> <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">X-API-Key: &lt;sua_api_key&gt;</code></li>
                    </ul>
                    <p class="text-zinc-400 text-sm mb-2">Exemplo:</p>
                    <DocCode label="http">
POST /api/v1/checkout/sessions HTTP/1.1
Host: {{ hostForExample }}
Authorization: Bearer getfy_xxxxxxxx_yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy
Content-Type: application/json
                    </DocCode>
                </DocSection>

                <DocSection id="obtencao-api-key" title="Obtenção da API key">
                    <ol class="doc-ol">
                        <li>No painel Getfy, acesse <strong>API Pagamentos</strong> (ou <strong>Aplicações</strong>).</li>
                        <li>Crie uma nova aplicação ou edite uma existente.</li>
                        <li>Na criação, a API key é exibida <strong>uma única vez</strong>; copie e guarde em local seguro.</li>
                        <li>Em edição, use <strong>Gerar nova API key</strong> se precisar de uma nova (a anterior deixa de funcionar).</li>
                    </ol>
                </DocSection>

                <DocSection id="seguranca" title="Segurança">
                    <ul class="doc-ul">
                        <li><strong class="text-zinc-200">Nunca</strong> exponha a API key em frontend público (JavaScript, apps móveis sem proteção). Use sempre um backend seu para chamar a API.</li>
                        <li>Em produção, utilize <strong class="text-zinc-200">HTTPS</strong> em todas as requisições.</li>
                        <li>A API key é armazenada apenas como hash no servidor; não é possível recuperá-la depois. Se perder, gere uma nova.</li>
                    </ul>
                </DocSection>

                <!-- Aplicações -->
                <DocSection id="o-que-e-aplicacao" title="O que é uma aplicação">
                    <p class="text-zinc-400 leading-relaxed">
                        Cada <strong class="text-zinc-200">Aplicação</strong> representa um cliente da API (ex.: sua loja, seu SaaS). A API atua como roteador de pagamentos: você autentica com uma aplicação (API key), escolhe o modo de checkout e os pagamentos são processados com a ordem e redundância de gateways definidas por aplicação.
                    </p>
                </DocSection>

                <DocSection id="configuracao" title="Configuração">
                    <p class="text-zinc-400 leading-relaxed mb-4">Na aplicação você configura:</p>
                    <ul class="doc-ul">
                        <li><strong class="text-zinc-200">Nome</strong> e identificação (slug).</li>
                        <li><strong class="text-zinc-200">Gateways por método:</strong> PIX, cartão, boleto (e opcionalmente PIX automático, cripto), com <strong>redundância</strong> (ordem de fallback).</li>
                        <li><strong class="text-zinc-200">Webhook URL</strong> (opcional): URL que receberá notificações de pagamento (order.completed, order.pending, order.refunded).</li>
                        <li><strong class="text-zinc-200">URL de retorno padrão</strong> (opcional): usada no Checkout Pro quando a sessão não enviar <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">return_url</code>.</li>
                        <li><strong class="text-zinc-200">Webhook secret</strong> (opcional): usado para assinar o body do webhook (header <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">X-Getfy-Signature</code>). Recomendado em produção.</li>
                        <li><strong class="text-zinc-200">IPs permitidos</strong> (opcional): lista de IPs que podem usar a API key; vazio = todos permitidos.</li>
                        <li><strong class="text-zinc-200">Ativo:</strong> aplicações inativas retornam 403.</li>
                    </ul>
                    <p class="text-zinc-400 leading-relaxed mt-4">
                        A ordem e redundância dos gateways são aplicadas a todos os pagamentos criados por essa aplicação (Checkout Pro e Transparente).
                    </p>
                </DocSection>

                <!-- Checkout Pro -->
                <DocSection id="post-checkout-sessions">
                    <DocEndpoint method="POST" path="/api/v1/checkout/sessions" description="Cria uma sessão e retorna a URL para o usuário final concluir o pagamento.">
                        <h4 class="doc-h4">Body (JSON)</h4>
                        <DocTable :columns="checkoutSessionsBodyColumns" :rows="checkoutSessionsBodyRows" />
                        <h4 class="doc-h4">Resposta 201</h4>
                        <DocCode label="json">{{ `{
  "session_id": "123",
  "checkout_url": "${checkoutBaseUrl}/api-checkout/xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
  "expires_at": "2026-03-09T12:30:00.000000Z"
}` }}</DocCode>
                        <p class="text-zinc-400 text-sm mt-4">
                            <strong class="text-zinc-200">checkout_url:</strong> link que o usuário final deve abrir. Na página, ele verá valor e método de pagamento (PIX, boleto); não preenche dados de cliente. A sessão expira no horário indicado em <code class="rounded bg-white/10 px-1 py-0.5">expires_at</code>.
                        </p>
                        <h4 class="doc-h4">Fluxo do usuário final</h4>
                        <ol class="doc-ol">
                            <li>Sua plataforma redireciona o cliente para <code class="rounded bg-white/10 px-1 py-0.5">checkout_url</code> ou abre em nova aba.</li>
                            <li>Na Getfy o cliente vê valor, produto (se houver) e escolhe PIX ou boleto.</li>
                            <li>Após a confirmação do pagamento, a Getfy exibe uma página de confirmação e redireciona o cliente de volta para <code class="rounded bg-white/10 px-1 py-0.5">return_url</code> (ou para a URL de retorno padrão da aplicação).</li>
                        </ol>
                    </DocEndpoint>
                </DocSection>

                <!-- Checkout Transparente -->
                <DocSection id="dados-comuns-customer" title="Dados comuns (customer)">
                    <p class="text-zinc-400 leading-relaxed mb-4">
                        Em todos os endpoints de criação de pagamento, use o objeto <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">customer</code>. Campos opcionais comuns em todos: <code class="rounded bg-white/10 px-1 py-0.5">amount</code>, <code class="rounded bg-white/10 px-1 py-0.5">currency</code>, <code class="rounded bg-white/10 px-1 py-0.5">product_id</code>, <code class="rounded bg-white/10 px-1 py-0.5">product_offer_id</code>, <code class="rounded bg-white/10 px-1 py-0.5">subscription_plan_id</code>, <code class="rounded bg-white/10 px-1 py-0.5">metadata</code>, <code class="rounded bg-white/10 px-1 py-0.5">idempotency_key</code> (ou header <code class="rounded bg-white/10 px-1 py-0.5">Idempotency-Key</code>).
                    </p>
                    <DocTable :columns="customerColumns" :rows="customerRows" />
                </DocSection>

                <DocSection id="post-payments-pix">
                    <DocEndpoint method="POST" path="/api/v1/payments/pix" description="Cria um pedido e uma cobrança PIX. Retorna QR code e copia e cola. Os campos de cliente seguem os dados comuns (customer).">
                        <h4 class="doc-h4">Body (JSON)</h4>
                        <DocTable :columns="[{ key: 'field', label: 'Campo' }, { key: 'type', label: 'Tipo' }, { key: 'required', label: 'Obrigatório' }, { key: 'desc', label: 'Descrição' }]" :rows="[{ field: 'customer', type: 'objeto', required: 'Sim', desc: 'Ver dados comuns acima' }, { field: 'amount', type: 'number', required: 'Sim', desc: 'Valor' }, { field: 'currency', type: 'string', required: 'Não', desc: 'BRL, USD ou EUR (default: BRL)' }, { field: 'product_id', type: 'string (UUID)', required: 'Não', desc: 'ID do produto Getfy' }, { field: 'product_offer_id', type: 'integer', required: 'Não', desc: 'ID da oferta' }, { field: 'subscription_plan_id', type: 'integer', required: 'Não', desc: 'ID do plano de assinatura' }, { field: 'metadata', type: 'objeto', required: 'Não', desc: 'Dados livres para webhook' }, { field: 'idempotency_key', type: 'string', required: 'Não', desc: 'Ou header Idempotency-Key (até 128 caracteres)' }]" />
                        <h4 class="doc-h4">Resposta 201</h4>
                        <DocCode label="json">{
  "order_id": 456,
  "transaction_id": "abc123",
  "qrcode": "data:image/png;base64,...",
  "copy_paste": "00020126...",
  "status": "pending"
}</DocCode>
                        <p class="text-zinc-400 text-sm mt-4">
                            Use <code class="rounded bg-white/10 px-1 py-0.5">qrcode</code> (imagem) ou <code class="rounded bg-white/10 px-1 py-0.5">copy_paste</code> (código PIX) na sua UI. O status pode ser consultado em <strong>GET /api/v1/payments/{order_id}</strong> ou via webhook.
                        </p>
                    </DocEndpoint>
                </DocSection>

                <DocSection id="post-payments-card">
                    <DocEndpoint method="POST" path="/api/v1/payments/card" description="Cria um pedido e processa o pagamento com cartão. Campos de cliente e valor seguem os dados comuns (customer).">
                        <h4 class="doc-h4">Body (JSON)</h4>
                        <DocTable :columns="[{ key: 'field', label: 'Campo' }, { key: 'type', label: 'Tipo' }, { key: 'required', label: 'Obrigatório' }, { key: 'desc', label: 'Descrição' }]" :rows="[{ field: 'customer', type: 'objeto', required: 'Sim', desc: 'Ver dados comuns acima' }, { field: 'amount', type: 'number', required: 'Sim', desc: 'Valor' }, { field: 'currency', type: 'string', required: 'Não', desc: 'Default BRL' }, { field: 'product_id, product_offer_id, subscription_plan_id, metadata', type: '—', required: 'Não', desc: 'Opcionais' }, { field: 'card', type: 'objeto', required: 'Sim', desc: 'Dados do cartão' }, { field: 'card.payment_token', type: 'string', required: 'Sim', desc: 'Token do cartão (gateway/JS Getfy ou sua tokenização)' }, { field: 'card.card_mask', type: 'string', required: 'Não', desc: 'Máscara (ex.: **** 1234)' }, { field: 'idempotency_key', type: 'string', required: 'Não', desc: 'Ou header Idempotency-Key' }]" />
                        <h4 class="doc-h4">Resposta 201</h4>
                        <DocCode label="json">{
  "order_id": 456,
  "transaction_id": "xyz",
  "status": "paid",
  "client_secret": "..."
}</DocCode>
                        <p class="text-zinc-400 text-sm mt-4">
                            <code class="rounded bg-white/10 px-1 py-0.5">status</code> pode ser <code class="rounded bg-white/10 px-1 py-0.5">pending</code>, <code class="rounded bg-white/10 px-1 py-0.5">paid</code>, <code class="rounded bg-white/10 px-1 py-0.5">approved</code>, <code class="rounded bg-white/10 px-1 py-0.5">completed</code> ou outro conforme o gateway. <code class="rounded bg-white/10 px-1 py-0.5">client_secret</code> aparece quando o gateway exige (ex.: 3DS).
                        </p>
                    </DocEndpoint>
                </DocSection>

                <DocSection id="post-payments-boleto">
                    <DocEndpoint method="POST" path="/api/v1/payments/boleto" description="Cria um pedido e gera um boleto. Campos de cliente seguem os dados comuns (customer).">
                        <h4 class="doc-h4">Body (JSON)</h4>
                        <DocTable :columns="[{ key: 'field', label: 'Campo' }, { key: 'type', label: 'Tipo' }, { key: 'required', label: 'Obrigatório' }, { key: 'desc', label: 'Descrição' }]" :rows="[{ field: 'customer', type: 'objeto', required: 'Sim', desc: 'Ver dados comuns acima' }, { field: 'amount', type: 'number', required: 'Sim', desc: 'Valor' }, { field: 'currency', type: 'string', required: 'Não', desc: 'Default BRL' }, { field: 'product_id, product_offer_id, subscription_plan_id, metadata', type: '—', required: 'Não', desc: 'Opcionais' }, { field: 'idempotency_key', type: 'string', required: 'Não', desc: 'Ou header Idempotency-Key' }]" />
                        <h4 class="doc-h4">Resposta 201</h4>
                        <DocCode label="json">{
  "order_id": 456,
  "transaction_id": "bol_xxx",
  "barcode": "12345.67890 12345.678901 12345.678901 1 12340012345678",
  "pdf_url": "https://...",
  "expire_at": "2026-03-12",
  "amount": 97.90,
  "status": "pending"
}</DocCode>
                        <p class="text-zinc-400 text-sm mt-4">
                            Exiba <code class="rounded bg-white/10 px-1 py-0.5">barcode</code>, <code class="rounded bg-white/10 px-1 py-0.5">pdf_url</code> e <code class="rounded bg-white/10 px-1 py-0.5">expire_at</code> na sua UI. O cliente paga e o status é atualizado (consulta ou webhook).
                        </p>
                    </DocEndpoint>
                </DocSection>

                <DocSection id="get-payments-order-id">
                    <DocEndpoint method="GET" path="/api/v1/payments/{order_id}" description="Consulta o status de um pedido criado pela sua aplicação.">
                        <h4 class="doc-h4">Resposta 200</h4>
                        <DocCode label="json">{
  "order_id": 456,
  "status": "completed",
  "amount": 97.90,
  "email": "cliente@email.com",
  "gateway": "efi",
  "gateway_id": "tx_xxx",
  "metadata": {},
  "created_at": "2026-03-09T10:00:00.000000Z",
  "updated_at": "2026-03-09T10:05:00.000000Z"
}</DocCode>
                        <p class="text-zinc-400 text-sm mt-4">
                            Se o pedido não existir ou não pertencer à aplicação autenticada: <strong>404</strong>.
                        </p>
                    </DocEndpoint>
                </DocSection>

                <DocSection id="idempotencia" title="Idempotência">
                    <p class="text-zinc-400 leading-relaxed mb-4">
                        Para evitar criar o mesmo pagamento duas vezes (ex.: retry após timeout), use <strong class="text-zinc-200">idempotency key</strong>:
                    </p>
                    <ul class="doc-ul">
                        <li>Envie no body: <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">"idempotency_key": "seu-uuid-ou-string-unica"</code> ou no header: <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">Idempotency-Key: seu-uuid-ou-string-unica</code></li>
                        <li>Máximo 128 caracteres.</li>
                        <li>Para a <strong>mesma aplicação</strong> e <strong>mesma chave</strong>, a API retorna a mesma resposta (cache por até 24h) sem criar novo pedido.</li>
                    </ul>
                    <DocCallout type="tip" title="Recomendado">
                        Use idempotency key em todos os endpoints de criação de pagamento (PIX, cartão, boleto).
                    </DocCallout>
                </DocSection>

                <!-- Webhooks -->
                <DocSection id="webhooks-eventos" title="Eventos">
                    <p class="text-zinc-400 leading-relaxed mb-4">
                        Se a aplicação tiver <strong class="text-zinc-200">webhook_url</strong> configurada, a Getfy envia um POST para essa URL quando certos eventos ocorrem em pedidos criados por essa aplicação.
                    </p>
                    <DocTable :columns="webhookEventsColumns" :rows="webhookEventsRows" />
                </DocSection>

                <DocSection id="webhooks-formato" title="Formato do payload">
                    <p class="text-zinc-400 leading-relaxed mb-4">O body é JSON, por exemplo:</p>
                    <DocCode label="json">{
  "event": "order.completed",
  "order_id": 456,
  "amount": 97.90,
  "status": "completed",
  "email": "cliente@email.com",
  "metadata": { "external_id": "ref-123" },
  "created_at": "2026-03-09T10:00:00.000000Z",
  "updated_at": "2026-03-09T10:05:00.000000Z"
}</DocCode>
                </DocSection>

                <DocSection id="webhooks-assinatura" title="Assinatura (X-Getfy-Signature)">
                    <p class="text-zinc-400 leading-relaxed mb-4">
                        Se a aplicação tiver <strong class="text-zinc-200">webhook secret</strong> configurado, cada POST inclui o header <strong>X-Getfy-Signature</strong>: HMAC-SHA256 do <strong>body bruto</strong> (string JSON) usando o webhook secret como chave.
                    </p>
                    <p class="text-zinc-400 text-sm font-semibold mb-2">Como validar no seu servidor:</p>
                    <ol class="doc-ol">
                        <li>Ler o body bruto da requisição (antes de parsear JSON).</li>
                        <li>Calcular <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">HMAC-SHA256(body_bruto, webhook_secret)</code>.</li>
                        <li>Comparar com o valor do header <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">X-Getfy-Signature</code> (comparação constante para evitar timing attacks).</li>
                    </ol>
                    <DocCallout type="warning" title="Produção">
                        Se não houver webhook secret configurado, o header não é enviado. Em produção, é recomendado configurar o secret e validar a assinatura.
                    </DocCallout>
                </DocSection>

                <DocSection id="webhooks-boas-praticas" title="Boas práticas (webhooks)">
                    <ul class="doc-ul">
                        <li>Responder com <strong>2xx</strong> rapidamente; processar o evento de forma assíncrona se necessário.</li>
                        <li>Não confiar no conteúdo sem validar a assinatura quando o secret estiver configurado.</li>
                        <li>Tratar eventos duplicados (mesmo <code class="rounded bg-white/10 px-1 py-0.5">order_id</code>/evento pode ser reenviado em retentativas).</li>
                    </ul>
                </DocSection>

                <!-- Referência -->
                <DocSection id="codigos-de-erro" title="Códigos de erro">
                    <DocTable :columns="errorCodesColumns" :rows="errorCodesRows" />
                    <p class="text-zinc-400 text-sm mt-4">
                        Respostas de erro costumam ser JSON, por exemplo: <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">{ "message": "Missing or invalid API key." }</code>
                    </p>
                </DocSection>

                <DocSection id="boas-praticas" title="Boas práticas gerais">
                    <ol class="doc-ol">
                        <li><strong class="text-zinc-200">Idempotency key:</strong> use em todas as criações de pagamento (PIX, cartão, boleto) para evitar cobranças duplicadas em retentativas.</li>
                        <li><strong class="text-zinc-200">Webhook:</strong> configure <code class="rounded bg-white/10 px-1 py-0.5">webhook_url</code> e <code class="rounded bg-white/10 px-1 py-0.5">webhook_secret</code>; valide sempre o header <code class="rounded bg-white/10 px-1 py-0.5">X-Getfy-Signature</code> quando o secret estiver definido.</li>
                        <li><strong class="text-zinc-200">API key:</strong> nunca inclua a API key em código frontend ou em repositórios; use variáveis de ambiente no backend.</li>
                        <li><strong class="text-zinc-200">HTTPS:</strong> em produção, utilize apenas HTTPS.</li>
                        <li><strong class="text-zinc-200">Tratamento de erros:</strong> trate 4xx e 5xx e implemente retry com backoff para 5xx e 429 (respeitando o rate limit).</li>
                        <li><strong class="text-zinc-200">Logs:</strong> não registre a API key em logs; use apenas identificadores da aplicação (ex.: id ou nome).</li>
                    </ol>
                </DocSection>

                <DocSection id="resumo-endpoints" title="Resumo de endpoints">
                    <DocTable :columns="[{ key: 'method', label: 'Método' }, { key: 'endpoint', label: 'Endpoint' }, { key: 'desc', label: 'Descrição' }]" :rows="[{ method: 'POST', endpoint: '/api/v1/checkout/sessions', desc: 'Criar sessão Checkout Pro (retorna link)' }, { method: 'POST', endpoint: '/api/v1/payments/pix', desc: 'Criar pagamento PIX (transparente)' }, { method: 'POST', endpoint: '/api/v1/payments/card', desc: 'Criar pagamento com cartão' }, { method: 'POST', endpoint: '/api/v1/payments/boleto', desc: 'Criar pagamento com boleto' }, { method: 'GET', endpoint: '/api/v1/payments/{order_id}', desc: 'Consultar status do pedido' }]" />
                    <p class="text-zinc-400 text-sm mt-4">
                        Base URL: a raiz da sua instalação Getfy. Todas as rotas da API estão sob o prefixo <code class="rounded bg-white/10 px-1.5 py-0.5 text-teal-300">/api/v1</code>.
                    </p>
                </DocSection>
            </article>
        </div>
    </div>
</template>

<style scoped>
@reference "../../../css/app.css";

.api-docs {
    font-family: 'DM Sans', ui-sans-serif, system-ui, sans-serif;
}
.doc-nav-link {
    pointer-events: auto;
}
.doc-h3 {
    @apply mb-4 mt-12 scroll-mt-28 text-base font-semibold text-zinc-200;
}
.doc-h4 {
    @apply mb-3 mt-8 scroll-mt-28 text-sm font-medium text-zinc-300;
}
.doc-ul {
    @apply my-6 list-disc space-y-2 pl-6 text-zinc-400 leading-relaxed;
}
.doc-ol {
    @apply my-6 list-decimal space-y-2 pl-6 text-zinc-400 leading-relaxed;
}
</style>
