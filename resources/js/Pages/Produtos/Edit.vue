<script setup>
import { computed, reactive, ref, onMounted, watch } from 'vue';
import { useForm, Link, router, usePage } from '@inertiajs/vue3';
import { useSidebar } from '@/composables/useSidebar';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import Toggle from '@/components/ui/Toggle.vue';
import Checkbox from '@/components/ui/Checkbox.vue';
import GatewaySelect from '@/components/ui/GatewaySelect.vue';
import GatewayRedundancySidebar from '@/components/produtos/GatewayRedundancySidebar.vue';
import {
    LayoutDashboard,
    Settings,
    Package,
    ArrowUpDown,
    ShoppingCart,
    Link2,
    Users,
    Handshake,
    Copy,
    Check,
    Smartphone,
    CreditCard,
    LayoutGrid,
    Mail,
    Upload,
    Loader2,
    ImageIcon,
    X,
    Plus,
    Pencil,
    Trash2,
    Layers,
    MapPin,
} from 'lucide-vue-next';
import axios from 'axios';
import EmailTemplatePreview from '@/components/produtos/EmailTemplatePreview.vue';

function getCsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    if (match) {
        try {
            return decodeURIComponent(match[1]);
        } catch (_) {}
    }
    return '';
}

/** UUID para chaves de lista; fallback quando `crypto.randomUUID` não existe (ex.: página em HTTP). */
function randomClientId() {
    const c = typeof globalThis !== 'undefined' ? globalThis.crypto : undefined;
    if (c && typeof c.randomUUID === 'function') {
        return c.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (ch) => {
        const r = (Math.random() * 16) | 0;
        const v = ch === 'x' ? r : (r & 0x3) | 0x8;
        return v.toString(16);
    });
}

defineOptions({ layout: LayoutInfoprodutor });

const DEFAULT_EMAIL_TEMPLATE = {
    logo_url: '',
    from_name: '',
    subject: 'Seu acesso a {nome_produto}',
    body_html: '<p>Olá, {nome_cliente}!</p><p>Obrigado por adquirir <strong>{nome_produto}</strong>.</p><p>Use o link abaixo para acessar seu conteúdo:</p><p><a href="{link_acesso}" style="display:inline-block;padding:12px 24px;background:#0ea5e9;color:#fff;text-decoration:none;border-radius:8px;">Acessar agora</a></p><p>Ou copie e cole no navegador: {link_acesso}</p><p>Qualquer dúvida, responda este e-mail.</p>',
};

const ENTRY_FLAGS = {
    fire_purchase_on_pix: true,
    fire_purchase_on_boleto: true,
    disable_order_bump_events: false,
};

function newMetaEntry() {
    return { id: randomClientId(), pixel_id: '', access_token: '', ...ENTRY_FLAGS };
}
function newTiktokEntry() {
    return { id: randomClientId(), pixel_id: '', access_token: '', ...ENTRY_FLAGS };
}
function newGoogleAdsEntry() {
    return { id: randomClientId(), conversion_id: '', conversion_label: '', ...ENTRY_FLAGS };
}
function newGaEntry() {
    return { id: randomClientId(), measurement_id: '', ...ENTRY_FLAGS };
}

const DEFAULT_CONVERSION_PIXELS = {
    meta: { enabled: false, entries: [] },
    tiktok: { enabled: false, entries: [] },
    google_ads: { enabled: false, entries: [] },
    google_analytics: { enabled: false, entries: [] },
    custom_script: [],
};

function mergeConversionPixels(raw) {
    if (!raw || typeof raw !== 'object') return JSON.parse(JSON.stringify(DEFAULT_CONVERSION_PIXELS));
    const out = JSON.parse(JSON.stringify(DEFAULT_CONVERSION_PIXELS));

    function normalizeMetaLike(block, newEntryFn) {
        const enabled = !!block?.enabled;
        if (Array.isArray(block?.entries)) {
            return {
                enabled,
                entries: block.entries
                    .filter((e) => e && typeof e === 'object')
                    .map((e) => ({ ...newEntryFn(), ...e, id: e.id || randomClientId() })),
            };
        }
        if (block?.pixel_id != null || block?.access_token != null) {
            const pixel_id = String(block.pixel_id ?? '').trim();
            const access_token = String(block.access_token ?? '').trim();
            if (pixel_id || access_token) {
                return {
                    enabled,
                    entries: [
                        {
                            id: randomClientId(),
                            pixel_id,
                            access_token,
                            fire_purchase_on_pix: block.fire_purchase_on_pix !== false,
                            fire_purchase_on_boleto: block.fire_purchase_on_boleto !== false,
                            disable_order_bump_events: !!block.disable_order_bump_events,
                        },
                    ],
                };
            }
        }
        return { enabled, entries: [] };
    }

    function normalizeGoogleAdsBlock(block) {
        const enabled = !!block?.enabled;
        if (Array.isArray(block?.entries)) {
            return {
                enabled,
                entries: block.entries
                    .filter((e) => e && typeof e === 'object')
                    .map((e) => ({ ...newGoogleAdsEntry(), ...e, id: e.id || randomClientId() })),
            };
        }
        const conversion_id = String(block?.conversion_id ?? '').trim();
        if (conversion_id) {
            return {
                enabled,
                entries: [
                    {
                        id: randomClientId(),
                        conversion_id,
                        conversion_label: String(block.conversion_label ?? '').trim(),
                        fire_purchase_on_pix: block.fire_purchase_on_pix !== false,
                        fire_purchase_on_boleto: block.fire_purchase_on_boleto !== false,
                        disable_order_bump_events: !!block.disable_order_bump_events,
                    },
                ],
            };
        }
        return { enabled, entries: [] };
    }

    function normalizeGaBlock(block) {
        const enabled = !!block?.enabled;
        if (Array.isArray(block?.entries)) {
            return {
                enabled,
                entries: block.entries
                    .filter((e) => e && typeof e === 'object')
                    .map((e) => ({ ...newGaEntry(), ...e, id: e.id || randomClientId() })),
            };
        }
        const measurement_id = String(block?.measurement_id ?? '').trim();
        if (measurement_id) {
            return {
                enabled,
                entries: [
                    {
                        id: randomClientId(),
                        measurement_id,
                        fire_purchase_on_pix: block.fire_purchase_on_pix !== false,
                        fire_purchase_on_boleto: block.fire_purchase_on_boleto !== false,
                        disable_order_bump_events: !!block.disable_order_bump_events,
                    },
                ],
            };
        }
        return { enabled, entries: [] };
    }

    if (raw.meta && typeof raw.meta === 'object') {
        out.meta = normalizeMetaLike(raw.meta, newMetaEntry);
    }
    if (raw.tiktok && typeof raw.tiktok === 'object') {
        out.tiktok = normalizeMetaLike(raw.tiktok, newTiktokEntry);
    }
    if (raw.google_ads && typeof raw.google_ads === 'object') {
        out.google_ads = normalizeGoogleAdsBlock(raw.google_ads);
    }
    if (raw.google_analytics && typeof raw.google_analytics === 'object') {
        out.google_analytics = normalizeGaBlock(raw.google_analytics);
    }
    out.custom_script = Array.isArray(raw.custom_script) ? raw.custom_script.filter((s) => s && typeof s === 'object').map((s) => ({ id: s.id || randomClientId(), name: s.name || '', script: s.script || '' })) : [];
    return out;
}

const PIXEL_TABS = [
    { id: 'meta', label: 'Meta Ads', image: '/images/pixels/meta.png' },
    { id: 'tiktok', label: 'TikTok Ads', image: '/images/pixels/tiktok.png' },
    { id: 'google_ads', label: 'Google Ads', image: '/images/pixels/googleads.png' },
    { id: 'google_analytics', label: 'Google Analytics', image: '/images/pixels/google-analytics.png' },
    { id: 'custom_script', label: 'Script personalizado', image: '/images/pixels/script.png' },
];

const TABS = [
    { id: 'geral', label: 'Geral', icon: LayoutDashboard },
    { id: 'configuracoes', label: 'Configurações', icon: Settings },
    { id: 'email', label: 'E-mail', icon: Mail },
    { id: 'order_bump', label: 'Order Bump', icon: Package },
    { id: 'upsell_downsell', label: 'Upsell / Downsell', icon: ArrowUpDown },
    { id: 'checkout', label: 'Checkout', icon: ShoppingCart },
    { id: 'links', label: 'Links', icon: Link2 },
    { id: 'coproducao', label: 'Co-produção', icon: Handshake },
    { id: 'afiliados', label: 'Afiliados', icon: Users },
    { id: 'member_builder', label: 'Member Builder', icon: LayoutGrid, linkOnly: true },
];

const props = defineProps({
    produto: { type: Object, required: true },
    productTypes: { type: Array, default: () => [] },
    billingTypes: { type: Array, default: () => [] },
    exchange_rates: { type: Object, default: () => ({ brl_eur: 0.16, brl_usd: 0.18 }) },
    cademi_integrations: { type: Array, default: () => [] },
    gateways_by_method: {
        type: Object,
        default: () => ({ pix: [], card: [], boleto: [], pix_auto: [], crypto: [] }),
    },
});

const page = usePage();
const currentTab = computed(() => {
    const url = page.url;
    const idx = url.indexOf('?');
    const search = idx !== -1 ? url.slice(idx) : '';
    const q = new URLSearchParams(search);
    const t = q.get('tab');
    return TABS.some((tab) => tab.id === t) ? t : 'geral';
});

function setTab(tabId) {
    router.get(`/produtos/${props.produto.id}/edit?tab=${tabId}`, {}, { preserveState: true });
}

function goToMemberBuilder() {
    window.location.href = `/produtos/${props.produto.id}/member-builder`;
}

const { setExpanded } = useSidebar();
onMounted(() => {
    setExpanded(false);
});

const activeTabRef = ref(null);
watch(currentTab, () => {
    setTimeout(() => {
        activeTabRef.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
    }, 50);
});

const pg = props.produto.checkout_config?.payment_gateways ?? {};
const et = props.produto.checkout_config?.email_template ?? {};
const ci = props.produto.checkout_config?.card_installments ?? { enabled: false, max: 1 };
const PAGARME_BILLING_DEFAULT = {
    mode: 'customer',
    company_address: {
        zipcode: '',
        street: '',
        number: '',
        neighborhood: '',
        city: '',
        state: '',
    },
};
const rawPb = props.produto.checkout_config?.pagarme_billing;
const pagarmeBillingInitial = {
    ...PAGARME_BILLING_DEFAULT,
    ...(rawPb && typeof rawPb === 'object' ? rawPb : {}),
    company_address: {
        ...PAGARME_BILLING_DEFAULT.company_address,
        ...(rawPb?.company_address && typeof rawPb.company_address === 'object' ? rawPb.company_address : {}),
    },
};
const stripeLinkEnabled = props.produto.checkout_config?.stripe_link_enabled;
const form = useForm({
    name: props.produto.name,
    slug: props.produto.slug,
    description: props.produto.description ?? '',
    type: props.produto.type,
    billing_type: props.produto.billing_type ?? 'one_time',
    price: props.produto.price_brl ?? props.produto.price,
    base_interval: props.produto.base_interval ?? (props.produto.subscription_plans?.sort((a, b) => (a.position ?? 0) - (b.position ?? 0))[0]?.interval) ?? 'monthly',
    currency: props.produto.currency ?? 'BRL',
    is_active: props.produto.is_active,
    image: null,
    conversion_pixels: mergeConversionPixels(props.produto.conversion_pixels),
    deliverable_link: props.produto.checkout_config?.deliverable_link ?? '',
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
    card_installments: {
        enabled: Boolean(ci.enabled),
        max: Math.min(12, Math.max(1, parseInt(ci.max, 10) || 1)),
    },
    stripe_link_enabled: stripeLinkEnabled !== false && stripeLinkEnabled !== '0',
    email_template: {
        logo_url: et.logo_url ?? DEFAULT_EMAIL_TEMPLATE.logo_url,
        from_name: et.from_name ?? DEFAULT_EMAIL_TEMPLATE.from_name,
        subject: et.subject ?? DEFAULT_EMAIL_TEMPLATE.subject,
        body_html: et.body_html ?? DEFAULT_EMAIL_TEMPLATE.body_html,
    },
    pagarme_billing: pagarmeBillingInitial,
});

const priceNum = computed(() => parseFloat(form.price) || 0);
const priceEur = computed(() => (priceNum.value * (props.exchange_rates.brl_eur ?? 0.16)).toFixed(2));
const priceUsd = computed(() => (priceNum.value * (props.exchange_rates.brl_usd ?? 0.18)).toFixed(2));

/** Valor mínimo por parcela (R$) para Efí/Asaas — parcelas abaixo disso costumam ser recusadas. */
const MIN_PARCELA_BRL = 5;
/** Máximo de parcelas permitido pelo preço atual (1–12). */
const maxAllowedInstallments = computed(() => {
    const p = priceNum.value;
    if (!p || p < MIN_PARCELA_BRL) return 1;
    return Math.min(12, Math.max(1, Math.floor(p / MIN_PARCELA_BRL)));
});

watch(maxAllowedInstallments, (maxAllowed) => {
    if (form.card_installments.max > maxAllowed) {
        form.card_installments.max = maxAllowed;
    }
}, { immediate: true });

const currentImageUrl = computed(() => {
    if (form.image && typeof form.image === 'object' && form.image instanceof File) {
        return URL.createObjectURL(form.image);
    }
    return props.produto.image_url ?? null;
});

const selectedPixelTab = ref('meta');
const logoUploading = ref(false);
const logoError = ref('');
const logoInputRef = ref(null);
const deliverableLinkSidebarOpen = ref(false);
const pagarmeBillingSidebarOpen = ref(false);
const pagarmeCompanyCepLoading = ref(false);
const pagarmeCompanyCepError = ref('');
const cademiSaving = ref(false);
const cademiError = ref('');
const cademiTagsLoading = ref(false);
const cademiTagsError = ref('');
const cademiTags = ref([]);
const cademiTagQuery = ref('');
const cademiConfig = ref({
    integration_id: props.produto?.external_member_area?.integration_id ?? '',
    cademi_tag_id: props.produto?.external_member_area?.cademi_tag_id ?? '',
    cademi_produto_ids: Array.isArray(props.produto?.external_member_area?.cademi_produto_ids)
        ? props.produto.external_member_area.cademi_produto_ids.map((v) => String(v))
        : props.produto?.external_member_area?.cademi_produto_id
          ? [String(props.produto.external_member_area.cademi_produto_id)]
          : [''],
});

const filteredCademiTags = computed(() => {
    const q = (cademiTagQuery.value || '').trim().toLowerCase();
    if (!q) return cademiTags.value;
    return (cademiTags.value || []).filter((t) => String(t.nome || '').toLowerCase().includes(q));
});

async function loadCademiTags() {
    cademiTagsError.value = '';
    cademiTags.value = [];
    const integrationId = cademiConfig.value.integration_id;
    if (!integrationId) return;
    cademiTagsLoading.value = true;
    try {
        const { data } = await axios.get(`/integracoes/cademi/${integrationId}/tags`);
        cademiTags.value = Array.isArray(data?.tags) ? data.tags : [];
    } catch (err) {
        cademiTagsError.value = err.response?.data?.message || 'Não foi possível listar as TAGs da Cademí.';
    } finally {
        cademiTagsLoading.value = false;
    }
}

watch(
    () => cademiConfig.value.integration_id,
    () => {
        cademiTagQuery.value = '';
        loadCademiTags();
    }
);

async function saveCademiProductMapping() {
    cademiError.value = '';
    const ids = (cademiConfig.value.cademi_produto_ids || [])
        .map((v) => String(v || '').trim())
        .filter((v) => v !== '');
    const parsedIds = ids
        .map((v) => parseInt(v, 10))
        .filter((n) => Number.isFinite(n) && n > 0);

    if (cademiConfig.value.integration_id && parsedIds.length === 0) {
        cademiError.value = 'Informe ao menos 1 Produto ID da Cademí.';
        return;
    }
    cademiSaving.value = true;
    try {
        await axios.put(`/produtos/${props.produto.id}/external-member-area`, {
            cademi_integration_id: cademiConfig.value.integration_id || null,
            cademi_tag_id: cademiConfig.value.cademi_tag_id ? parseInt(cademiConfig.value.cademi_tag_id, 10) : null,
            cademi_produto_ids: parsedIds,
        });
        router.reload({ only: ['produto', 'cademi_integrations'] });
    } catch (err) {
        cademiError.value = err.response?.data?.message || 'Erro ao salvar configuração da Cademí.';
    } finally {
        cademiSaving.value = false;
    }
}

async function uploadEmailLogo(file) {
    if (!file || !file.type.startsWith('image/')) return;
    logoError.value = '';
    logoUploading.value = true;
    try {
        const fd = new FormData();
        fd.append('logo', file);
        const { data } = await axios.post(
            `/produtos/${props.produto.id}/email-template-logo`,
            fd,
            {
                headers: {
                    'X-XSRF-TOKEN': getCsrfToken(),
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                withCredentials: true,
            }
        );
        form.email_template.logo_url = data.logo_url ?? '';
    } catch (err) {
        const msg = err.response?.data?.message || err.response?.data?.errors?.logo?.[0] || 'Falha no envio. Use uma imagem (PNG, JPG) até 2 MB.';
        logoError.value = msg;
    } finally {
        logoUploading.value = false;
        if (logoInputRef.value) logoInputRef.value.value = '';
    }
}

function onLogoFileChange(e) {
    const file = e.target?.files?.[0];
    if (file) uploadEmailLogo(file);
}

function checkoutUrl(slug) {
    if (typeof window === 'undefined' || !slug) return '';
    return `${window.location.origin}/c/${slug}`;
}
const mainCheckoutUrl = computed(() => checkoutUrl(props.produto.checkout_slug));
const hasCheckoutLink = computed(() => !!props.produto.checkout_slug);

const INTERVAL_LABELS = {
    weekly: 'Semanal',
    monthly: 'Mensal',
    quarterly: 'Trimestral',
    semi_annual: 'Semestral',
    annual: 'Anual',
    lifetime: 'Vitalício',
};
function intervalLabel(interval) {
    return INTERVAL_LABELS[interval] || interval;
}

const offerFormVisible = ref(false);
const editingOffer = ref(null);
const offerForm = useForm({
    name: '',
    price: '',
    currency: 'BRL',
});
function openNewOffer() {
    editingOffer.value = null;
    offerForm.reset();
    offerForm.name = '';
    offerForm.price = '';
    offerForm.currency = props.produto.currency || 'BRL';
    offerFormVisible.value = true;
}
function openEditOffer(offer) {
    editingOffer.value = offer;
    offerForm.name = offer.name;
    offerForm.price = offer.price;
    offerForm.currency = offer.currency || 'BRL';
    offerFormVisible.value = true;
}
function closeOfferForm() {
    offerFormVisible.value = false;
    editingOffer.value = null;
    offerForm.reset();
}
function submitOffer() {
    if (editingOffer.value) {
        offerForm.put(`/produtos/${props.produto.id}/offers/${editingOffer.value.id}`, {
            preserveScroll: true,
            onSuccess: () => { closeOfferForm(); router.reload(); },
        });
    } else {
        offerForm.post(`/produtos/${props.produto.id}/offers`, {
            preserveScroll: true,
            onSuccess: () => { closeOfferForm(); router.reload(); },
        });
    }
}
function confirmDestroyOffer(offer) {
    if (!window.confirm(`Remover a oferta "${offer.name}"?`)) return;
    router.delete(`/produtos/${props.produto.id}/offers/${offer.id}`, { preserveScroll: true, onSuccess: () => router.reload() });
}

const planFormVisible = ref(false);
const editingPlan = ref(null);
const planForm = useForm({
    name: '',
    price: '',
    currency: 'BRL',
    interval: 'monthly',
});
function openNewPlan() {
    editingPlan.value = null;
    planForm.reset();
    planForm.name = '';
    planForm.price = '';
    planForm.currency = props.produto.currency || 'BRL';
    planForm.interval = 'monthly';
    planFormVisible.value = true;
}
function openEditPlan(plan) {
    editingPlan.value = plan;
    planForm.name = plan.name;
    planForm.price = plan.price;
    planForm.currency = plan.currency || 'BRL';
    planForm.interval = plan.interval;
    planFormVisible.value = true;
}
function closePlanForm() {
    planFormVisible.value = false;
    editingPlan.value = null;
    planForm.reset();
}
function submitPlan() {
    if (editingPlan.value) {
        planForm.put(`/produtos/${props.produto.id}/subscription-plans/${editingPlan.value.id}`, {
            preserveScroll: true,
            onSuccess: () => { closePlanForm(); router.reload(); },
        });
    } else {
        planForm.post(`/produtos/${props.produto.id}/subscription-plans`, {
            preserveScroll: true,
            onSuccess: () => { closePlanForm(); router.reload(); },
        });
    }
}
function confirmDestroyPlan(plan) {
    if (!window.confirm(`Remover o plano "${plan.name}"?`)) return;
    router.delete(`/produtos/${props.produto.id}/subscription-plans/${plan.id}`, { preserveScroll: true, onSuccess: () => router.reload() });
}

// Order Bump: modal e formulário
const showOrderBumpModal = ref(false);
const editingBump = ref(null);
const bumpForm = useForm({
    target_product_id: '',
    target_product_offer_id: '',
    title: '',
    description: '',
    price_override: '',
    cta_title: 'Sim, quero esta oferta!',
});
const selectedBumpProduct = computed(() => {
    const id = bumpForm.target_product_id;
    if (!id) return null;
    return (props.produto.available_products_for_bump || []).find((p) => p.id === id);
});
function openNewOrderBump() {
    editingBump.value = null;
    bumpForm.reset();
    bumpForm.target_product_id = '';
    bumpForm.target_product_offer_id = '';
    bumpForm.title = '';
    bumpForm.description = '';
    bumpForm.price_override = '';
    bumpForm.cta_title = 'Sim, quero esta oferta!';
    showOrderBumpModal.value = true;
}
function openEditOrderBump(bump) {
    editingBump.value = bump;
    bumpForm.target_product_id = bump.target_product_id;
    bumpForm.target_product_offer_id = bump.target_product_offer_id != null ? String(bump.target_product_offer_id) : '';
    bumpForm.title = bump.title;
    bumpForm.description = bump.description ?? '';
    bumpForm.price_override = bump.price_override != null ? String(bump.price_override) : '';
    bumpForm.cta_title = bump.cta_title;
    showOrderBumpModal.value = true;
}
function closeOrderBumpModal() {
    showOrderBumpModal.value = false;
    editingBump.value = null;
    bumpForm.reset();
}
function submitOrderBump() {
    const payload = {
        target_product_id: bumpForm.target_product_id,
        target_product_offer_id: bumpForm.target_product_offer_id || null,
        title: bumpForm.title,
        description: bumpForm.description || null,
        price_override: bumpForm.price_override ? parseFloat(bumpForm.price_override) : null,
        cta_title: bumpForm.cta_title,
    };
    if (editingBump.value) {
        bumpForm.transform(() => payload).put(`/produtos/${props.produto.id}/order-bumps/${editingBump.value.id}`, {
            preserveScroll: true,
            onSuccess: () => { closeOrderBumpModal(); router.reload(); },
        });
    } else {
        bumpForm.transform(() => payload).post(`/produtos/${props.produto.id}/order-bumps`, {
            preserveScroll: true,
            onSuccess: () => { closeOrderBumpModal(); router.reload(); },
        });
    }
}
function confirmDestroyOrderBump(bump) {
    if (!window.confirm(`Remover o order bump "${bump.title}"?`)) return;
    router.delete(`/produtos/${props.produto.id}/order-bumps/${bump.id}`, { preserveScroll: true, onSuccess: () => router.reload() });
}

const defaultUpsellPage = {
    headline: 'Quer levar isso também?',
    subheadline: 'Oferta especial só para você',
    body_text: '',
    hero_image: null,
    hero_video_url: null,
    background_color: '#f3f4f6',
    background_image: null,
    show_product_just_bought: true,
};
const defaultDownsellPage = {
    headline: 'Última chance com desconto',
    subheadline: 'Uma oferta que não pode ficar de fora',
    body_text: '',
    hero_image: null,
    hero_video_url: null,
    background_color: '#f3f4f6',
    background_image: null,
    show_product_just_bought: true,
};

// Upsell / Downsell: estado e persistência no checkout_config do produto (preserva page e overrides por oferta)
function getInitialUpsellDownsell() {
    const c = props.produto.checkout_config || {};
    const u = c.upsell || {};
    const d = c.downsell || {};
    return {
        upsell: {
            enabled: !!u.enabled,
            products: Array.isArray(u.products)
                ? u.products.map((p) => ({
                    product_id: p.product_id != null ? String(p.product_id) : null,
                    product_offer_id: p.product_offer_id ?? null,
                    title_override: p.title_override ?? '',
                    description: p.description ?? '',
                    image_url: p.image_url ?? '',
                    video_url: p.video_url ?? '',
                  }))
                : [],
            page: { ...defaultUpsellPage, ...(u.page || {}) },
            appearance: {
                title: u.appearance?.title ?? 'Quer levar isso também?',
                subtitle: u.appearance?.subtitle ?? 'Oferta especial só para você',
                primary_color: u.appearance?.primary_color ?? '#0ea5e9',
                button_accept: u.appearance?.button_accept ?? 'Sim, quero aproveitar',
                button_decline: u.appearance?.button_decline ?? 'Não, obrigado',
            },
        },
        downsell: {
            enabled: !!u.enabled && !!d.enabled,
            product_id: d.product_id != null ? String(d.product_id) : null,
            product_offer_id: d.product_offer_id ?? null,
            title_override: d.title_override ?? '',
            description: d.description ?? '',
            image_url: d.image_url ?? '',
            video_url: d.video_url ?? '',
            page: { ...defaultDownsellPage, ...(d.page || {}) },
            appearance: {
                title: d.appearance?.title ?? 'Última chance com desconto',
                subtitle: d.appearance?.subtitle ?? 'Uma oferta que não pode ficar de fora',
                primary_color: d.appearance?.primary_color ?? '#0ea5e9',
                button_accept: d.appearance?.button_accept ?? 'Aceitar oferta',
                button_decline: d.appearance?.button_decline ?? 'Não, obrigado',
            },
        },
    };
}
const upsellDownsellForm = reactive(getInitialUpsellDownsell());
const savingUpsellDownsell = ref(false);

watch(
    () => upsellDownsellForm.upsell.enabled,
    (enabled) => {
        if (!enabled) upsellDownsellForm.downsell.enabled = false;
    }
);
async function saveUpsellDownsell() {
    savingUpsellDownsell.value = true;
    const current = props.produto.checkout_config || {};
    const config = {
        ...current,
        upsell: {
            ...(current.upsell || {}),
            enabled: upsellDownsellForm.upsell.enabled,
            products: upsellDownsellForm.upsell.products,
        },
        downsell: {
            ...(current.downsell || {}),
            enabled: upsellDownsellForm.downsell.enabled,
            product_id: upsellDownsellForm.downsell.product_id ?? null,
            product_offer_id: upsellDownsellForm.downsell.product_offer_id ?? null,
        },
    };
    try {
        await axios.put(`/produtos/${props.produto.id}/checkout-config`, { config, offer_id: null, plan_id: null }, {
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        router.reload({ preserveScroll: true });
    } finally {
        savingUpsellDownsell.value = false;
    }
}

const copied = ref(false);
const copiedSlug = ref(null);

function copyToClipboard(text) {
    if (!text || typeof text !== 'string') return Promise.resolve(false);
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        return navigator.clipboard.writeText(text).then(() => true).catch(() => fallbackCopy(text));
    }
    return Promise.resolve(fallbackCopy(text));
}

function fallbackCopy(text) {
    try {
        const el = document.createElement('textarea');
        el.value = text;
        el.setAttribute('readonly', '');
        el.style.position = 'fixed';
        el.style.top = '0';
        el.style.left = '0';
        el.style.width = '2em';
        el.style.height = '2em';
        el.style.padding = '0';
        el.style.border = 'none';
        el.style.outline = 'none';
        el.style.boxShadow = 'none';
        el.style.background = 'transparent';
        el.style.opacity = '0';
        document.body.appendChild(el);
        el.focus();
        el.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(el);
        return ok;
    } catch (_) {
        return false;
    }
}

function copyCheckoutLink() {
    const url = mainCheckoutUrl.value;
    if (!url) return;
    copyToClipboard(url).then((ok) => {
        if (ok) {
            copied.value = true;
            setTimeout(() => { copied.value = false; }, 2000);
        }
    });
}
function copyLink(slug) {
    const url = checkoutUrl(slug);
    if (!url) return;
    copyToClipboard(url).then((ok) => {
        if (ok) {
            copiedSlug.value = slug;
            setTimeout(() => { copiedSlug.value = null; }, 2000);
        }
    });
}

function copyLinkForItem(item) {
    const url = getCheckoutLinkUrl(item);
    if (!url) return;
    copyToClipboard(url).then((ok) => {
        if (ok) {
            copiedSlug.value = item.id;
            setTimeout(() => { copiedSlug.value = null; }, 2000);
        }
    });
}
/** Lista apenas checkouts que existem: principal + ofertas/planos que têm checkout exclusivo (slug). Ofertas/planos sem slug usam o principal e não aparecem aqui. */
const checkoutItems = computed(() => {
    const billingType = props.produto.billing_type ?? form.billing_type;
    const items = [];
    items.push({
        id: 'main',
        label: 'Produto (preço base)',
        type: 'main',
        slug: props.produto.checkout_slug || null,
        offerId: null,
        planId: null,
    });
    if (billingType === 'one_time') {
        (props.produto.offers || []).forEach((o) => {
            if (o.checkout_slug) {
                items.push({
                    id: `offer-${o.id}`,
                    label: o.name,
                    type: 'offer',
                    slug: o.checkout_slug,
                    offerId: o.id,
                    planId: null,
                });
            }
        });
    }
    if (billingType === 'subscription') {
        (props.produto.subscription_plans || []).forEach((p) => {
            if (p.checkout_slug) {
                items.push({
                    id: `plan-${p.id}`,
                    label: p.name,
                    type: 'plan',
                    slug: p.checkout_slug,
                    offerId: null,
                    planId: p.id,
                });
            }
        });
    }
    return items;
});

/** Ofertas e planos que ainda não têm checkout exclusivo (para o modal "Criar novo checkout"). Por padrão todos usam o principal. */
const offerPlanItemsWithoutExclusiveCheckout = computed(() => {
    const billingType = props.produto.billing_type ?? form.billing_type;
    const list = [];
    if (billingType === 'one_time') {
        (props.produto.offers || []).forEach((o) => {
            if (!o.checkout_slug) {
                list.push({ id: `offer-${o.id}`, label: o.name, type: 'offer', offerId: o.id, planId: null });
            }
        });
    }
    if (billingType === 'subscription') {
        (props.produto.subscription_plans || []).forEach((p) => {
            if (!p.checkout_slug) {
                list.push({ id: `plan-${p.id}`, label: p.name, type: 'plan', offerId: null, planId: p.id });
            }
        });
    }
    return list;
});

/** Lista para a aba Links: principal + todas as ofertas e planos. Cada oferta/plano tem link único (exclusivo ou checkout principal + ?offer_id/?plan_id). */
const allCheckoutLinks = computed(() => {
    const billingType = props.produto.billing_type ?? form.billing_type;
    const mainSlug = props.produto.checkout_slug || null;
    const items = [];
    if (mainSlug) {
        items.push({ id: 'main', label: 'Preço base', slug: mainSlug, type: 'main', offer_id: null, plan_id: null });
    }
    if (billingType === 'one_time') {
        (props.produto.offers || []).forEach((o) => {
            const slug = o.checkout_slug || mainSlug;
            if (slug) {
                items.push({
                    id: `offer-${o.id}`,
                    label: o.name,
                    slug,
                    type: 'offer',
                    offer_id: o.checkout_slug ? null : o.id,
                    plan_id: null,
                });
            }
        });
    }
    if (billingType === 'subscription') {
        (props.produto.subscription_plans || []).forEach((p) => {
            const slug = p.checkout_slug || mainSlug;
            if (slug) {
                items.push({
                    id: `plan-${p.id}`,
                    label: p.name,
                    slug,
                    type: 'plan',
                    offer_id: null,
                    plan_id: p.checkout_slug ? null : p.id,
                });
            }
        });
    }
    return items;
});

/** URL completa do checkout para um item da lista de links (inclui ?offer_id ou ?plan_id quando usa o checkout principal). */
function getCheckoutLinkUrl(item) {
    const base = checkoutUrl(item.slug);
    if (!base) return '';
    if (item.offer_id) return `${base}?offer_id=${item.offer_id}`;
    if (item.plan_id) return `${base}?plan_id=${item.plan_id}`;
    return base;
}

/** URL do checkout para uma oferta na aba Geral: exclusivo ou principal + ?offer_id. */
function getOfferCheckoutUrl(offer) {
    if (offer.checkout_slug) return checkoutUrl(offer.checkout_slug);
    const main = props.produto.checkout_slug;
    return main ? `${checkoutUrl(main)}?offer_id=${offer.id}` : '';
}

/** URL do checkout para um plano na aba Geral: exclusivo ou principal + ?plan_id. */
function getPlanCheckoutUrl(plan) {
    if (plan.checkout_slug) return checkoutUrl(plan.checkout_slug);
    const main = props.produto.checkout_slug;
    return main ? `${checkoutUrl(main)}?plan_id=${plan.id}` : '';
}

function ensureCheckoutSlug(item) {
    const params = { type: item.type };
    if (item.type === 'offer' && item.offerId != null) params.offer_id = item.offerId;
    if (item.type === 'plan' && item.planId != null) params.plan_id = item.planId;
    router.post(`/produtos/${props.produto.id}/checkout/ensure-slug`, params);
}

function removeCheckoutSlug(item) {
    if (item.type === 'main') return;
    const label = item.type === 'offer' ? `Oferta: ${item.label}` : `Plano: ${item.label}`;
    if (!confirm(`Remover o checkout exclusivo de "${label}"? Ela passará a usar o checkout principal.`)) return;
    const data = { type: item.type };
    if (item.type === 'offer') data.offer_id = item.offerId;
    if (item.type === 'plan') data.plan_id = item.planId;
    router.delete(`/produtos/${props.produto.id}/checkout/remove-slug`, { data });
}

function editCheckoutUrl(item) {
    const base = `/produtos/${props.produto.id}/checkout/edit`;
    if (item.type === 'offer' && item.offerId != null) return `${base}?offer_id=${item.offerId}`;
    if (item.type === 'plan' && item.planId != null) return `${base}?plan_id=${item.planId}`;
    return base;
}

const showCreateCheckoutModal = ref(false);

function onFileChange(e) {
    const file = e.target.files?.[0];
    form.image = file || null;
}

const inputClass =
    'block w-full rounded-xl border-2 border-zinc-200 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500';

function gatewayOptions(method) {
    const list = props.gateways_by_method?.[method] ?? [];
    return [
        { value: '', label: 'Nenhum' },
        ...list.map((g) => ({ value: g.slug, label: g.name })),
    ];
}

const redundancySidebarOpen = ref(false);
const redundancySidebarMethod = ref(null);
const METHOD_LABELS = { pix: 'PIX', card: 'Cartão', boleto: 'Boleto', pix_auto: 'PIX automático', crypto: 'Criptomoeda' };
function openRedundancySidebar(method) {
    redundancySidebarMethod.value = method;
    redundancySidebarOpen.value = true;
}
function canShowRedundancy(slug) {
    return slug !== '' && slug !== null && slug !== undefined;
}

/** Cartão e boleto com Pagar.me ou Efí: permite configurar endereço de cobrança no produto. */
const BR_BILLING_GATEWAY_SLUGS = ['pagarme', 'efi'];
function isBrBillingGateway(slug) {
    return BR_BILLING_GATEWAY_SLUGS.includes(String(slug || '').toLowerCase());
}

function onPagarmeCompanyCepInput(e) {
    const digits = (e.target.value || '').replace(/\D/g, '').slice(0, 8);
    form.pagarme_billing.company_address.zipcode = digits.length > 5 ? `${digits.slice(0, 5)}-${digits.slice(5)}` : digits;
}

async function fetchPagarmeCompanyCep() {
    const cep = (form.pagarme_billing.company_address.zipcode || '').replace(/\D/g, '').slice(0, 8);
    if (cep.length < 8) return;
    pagarmeCompanyCepLoading.value = true;
    pagarmeCompanyCepError.value = '';
    try {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 8000);
        const res = await fetch(`https://viacep.com.br/ws/${cep}/json/`, { signal: controller.signal });
        clearTimeout(timeout);
        if (!res.ok) {
            pagarmeCompanyCepError.value = 'Não foi possível buscar o CEP.';
            return;
        }
        const data = await res.json().catch(() => null);
        if (!data || data.erro) {
            pagarmeCompanyCepError.value = 'CEP não encontrado.';
            return;
        }
        if (data.logradouro) form.pagarme_billing.company_address.street = data.logradouro;
        if (data.bairro) form.pagarme_billing.company_address.neighborhood = data.bairro;
        if (data.localidade) form.pagarme_billing.company_address.city = data.localidade;
        if (data.uf) form.pagarme_billing.company_address.state = data.uf;
    } catch (_) {
        pagarmeCompanyCepError.value = 'Não foi possível buscar o CEP agora.';
    } finally {
        pagarmeCompanyCepLoading.value = false;
    }
}
const typeIcons = {
    aplicativo: Smartphone,
    area_membros: Users,
    area_membros_externa: Users,
    link: Link2,
    link_pagamento: CreditCard,
};

function submit() {
    const baseUrl = `/produtos/${props.produto.id}`;
    const tab = currentTab.value && currentTab.value !== 'geral' ? `?tab=${currentTab.value}` : '';
    const url = baseUrl + tab;
    if (form.image) {
        const fd = new FormData();
        fd.append('name', form.name);
        fd.append('slug', form.slug);
        fd.append('description', form.description);
        fd.append('type', form.type);
        fd.append('billing_type', form.billing_type);
        fd.append('price', form.price);
        if (form.billing_type === 'subscription') {
            fd.append('base_interval', form.base_interval || 'monthly');
        }
        fd.append('currency', form.currency);
        fd.append('is_active', form.is_active ? '1' : '0');
        fd.append('conversion_pixels', JSON.stringify(form.conversion_pixels));
        if (form.payment_gateways) {
            fd.append('payment_gateways[pix]', form.payment_gateways.pix || '');
            (form.payment_gateways.pix_redundancy || []).forEach((s) => fd.append('payment_gateways[pix_redundancy][]', s));
            fd.append('payment_gateways[card]', form.payment_gateways.card || '');
            (form.payment_gateways.card_redundancy || []).forEach((s) => fd.append('payment_gateways[card_redundancy][]', s));
            fd.append('payment_gateways[boleto]', form.payment_gateways.boleto || '');
            (form.payment_gateways.boleto_redundancy || []).forEach((s) => fd.append('payment_gateways[boleto_redundancy][]', s));
            fd.append('payment_gateways[crypto]', form.payment_gateways.crypto || '');
            (form.payment_gateways.crypto_redundancy || []).forEach((s) => fd.append('payment_gateways[crypto_redundancy][]', s));
            if (form.billing_type === 'subscription') {
                fd.append('payment_gateways[pix_auto]', form.payment_gateways.pix_auto || '');
                (form.payment_gateways.pix_auto_redundancy || []).forEach((s) => fd.append('payment_gateways[pix_auto_redundancy][]', s));
            }
        }
        if (form.card_installments) {
            fd.append('card_installments[enabled]', form.card_installments.enabled ? '1' : '0');
            fd.append('card_installments[max]', String(Math.min(12, Math.max(1, form.card_installments.max || 1))));
        }
        if (typeof form.stripe_link_enabled === 'boolean') {
            fd.append('stripe_link_enabled', form.stripe_link_enabled ? '1' : '0');
        }
        if (form.email_template) {
            fd.append('email_template[logo_url]', form.email_template.logo_url || '');
            fd.append('email_template[from_name]', form.email_template.from_name || '');
            fd.append('email_template[subject]', form.email_template.subject || '');
            fd.append('email_template[body_html]', form.email_template.body_html || '');
        }
        fd.append('deliverable_link', form.deliverable_link || '');
        if (form.pagarme_billing) {
            fd.append('pagarme_billing[mode]', form.pagarme_billing.mode || 'customer');
            const ca = form.pagarme_billing.company_address || {};
            fd.append('pagarme_billing[company_address][zipcode]', ca.zipcode || '');
            fd.append('pagarme_billing[company_address][street]', ca.street || '');
            fd.append('pagarme_billing[company_address][number]', ca.number || '');
            fd.append('pagarme_billing[company_address][neighborhood]', ca.neighborhood || '');
            fd.append('pagarme_billing[company_address][city]', ca.city || '');
            fd.append('pagarme_billing[company_address][state]', String(ca.state || '').slice(0, 2));
        }
        fd.append('_method', 'PUT');
        fd.append('image', form.image);
        form.transform(() => fd).post(url, { forceFormData: true });
    } else {
        form.transform((data) => {
            if (data.billing_type === 'subscription') {
                data.base_interval = data.base_interval || 'monthly';
            }
            return data;
        }).put(url);
    }
}
</script>

<template>
    <div class="flex flex-col lg:flex-row lg:gap-6 space-y-6 lg:space-y-0 lg:pl-2">
        <!-- Desktop: sidebar vertical de abas (alinhado à esquerda junto ao sidebar principal) -->
        <aside
            class="hidden lg:flex lg:flex-col w-56 shrink-0 rounded-xl bg-zinc-50 dark:bg-zinc-800/50 p-2"
            aria-label="Menu de edição do produto"
        >
            <nav class="flex flex-col gap-0.5">
                <template v-for="tab in TABS" :key="tab.id">
                    <a
                        v-if="tab.linkOnly && tab.id === 'member_builder' && produto.type === 'area_membros'"
                        :href="`/produtos/${produto.id}/member-builder`"
                        :class="[
                            'flex items-center gap-2.5 rounded-lg px-3 py-2.5 text-sm font-medium transition-all duration-200',
                            'text-zinc-600 hover:bg-zinc-200/80 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-700/80 dark:hover:text-white',
                        ]"
                        @click.prevent="goToMemberBuilder"
                    >
                        <component :is="tab.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ tab.label }}
                    </a>
                    <button
                        v-else-if="!tab.linkOnly && (!tab.showWhen || produto.billing_type === tab.showWhen)"
                        type="button"
                        :class="[
                            'flex w-full items-center gap-2.5 rounded-lg px-3 py-2.5 text-left text-sm font-medium transition-all duration-200',
                            currentTab === tab.id
                                ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]'
                                : 'text-zinc-600 hover:bg-zinc-200/80 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-700/80 dark:hover:text-white',
                        ]"
                        :aria-current="currentTab === tab.id ? 'page' : undefined"
                        @click="setTab(tab.id)"
                    >
                        <component :is="tab.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ tab.label }}
                    </button>
                </template>
            </nav>
        </aside>

        <!-- Mobile: abas em carrossel horizontal -->
        <nav
            class="flex gap-2 overflow-x-auto pb-2 snap-x snap-mandatory no-scrollbar lg:hidden rounded-xl bg-zinc-100/80 p-1 dark:bg-zinc-800/80"
            aria-label="Abas de edição do produto"
        >
            <template v-for="tab in TABS" :key="tab.id">
                <a
                    v-if="tab.linkOnly && tab.id === 'member_builder' && produto.type === 'area_membros'"
                    :href="`/produtos/${produto.id}/member-builder`"
                    :class="[
                        'flex shrink-0 snap-center items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-all duration-200',
                        'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
                    ]"
                    @click.prevent="goToMemberBuilder"
                >
                    <component :is="tab.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ tab.label }}
                </a>
                <button
                    v-else-if="!tab.linkOnly && (!tab.showWhen || produto.billing_type === tab.showWhen)"
                    type="button"
                    :ref="currentTab === tab.id ? activeTabRef : undefined"
                    :class="[
                        'flex shrink-0 snap-center items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-all duration-200',
                        currentTab === tab.id
                            ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]'
                            : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
                    ]"
                    :aria-current="currentTab === tab.id ? 'page' : undefined"
                    @click="setTab(tab.id)"
                >
                    <component :is="tab.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ tab.label }}
                </button>
            </template>
        </nav>

        <!-- Conteúdo da aba -->
        <div class="flex-1 min-w-0 space-y-6">
        <!-- Aba Geral -->
        <template v-if="currentTab === 'geral'">
            <form class="mx-auto w-full max-w-3xl space-y-8 xl:max-w-6xl" @submit.prevent="submit">
                <div class="grid grid-cols-1 gap-8 xl:grid-cols-2">
                <!-- Informações básicas (nome, slug, descrição, imagem, status) -->
                <section class="overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-zinc-800/95">
                    <div class="border-b border-zinc-200/80 bg-zinc-50/80 px-6 py-4 dark:border-zinc-700/80 dark:bg-zinc-800/50">
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Informações básicas</h2>
                        <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Nome, identificador e imagem do produto.</p>
                    </div>
                    <div class="p-6">
                        <div class="grid gap-6 lg:grid-cols-[1fr,auto]">
                            <div class="space-y-5">
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nome do produto *</label>
                                    <input
                                        v-model="form.name"
                                        type="text"
                                        required
                                        placeholder="Ex: Curso Completo de X"
                                        :class="inputClass"
                                    />
                                    <p v-if="form.errors.name" class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ form.errors.name }}</p>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Slug (URL) *</label>
                                    <input
                                        v-model="form.slug"
                                        type="text"
                                        required
                                        placeholder="curso-completo-x"
                                        :class="inputClass"
                                    />
                                    <p v-if="form.errors.slug" class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ form.errors.slug }}</p>
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Usado em URLs e área de membros. Apenas letras minúsculas, números e hífens.</p>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Descrição</label>
                                    <textarea
                                        v-model="form.description"
                                        rows="3"
                                        placeholder="Breve descrição do produto..."
                                        :class="inputClass"
                                    />
                                </div>
                                <div class="flex flex-wrap items-center gap-4 pt-1">
                                    <Toggle v-model="form.is_active" label="Produto ativo" />
                                </div>
                            </div>
                            <div class="flex flex-col items-start lg:pt-0">
                                <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Imagem do produto</label>
                                <p class="mb-2 text-xs text-zinc-500 dark:text-zinc-400">1:1, ex.: 400×400 px.</p>
                                <label
                                    class="relative flex h-28 w-28 shrink-0 cursor-pointer flex-col items-center justify-center overflow-hidden rounded-xl border-2 border-dashed border-zinc-200 bg-zinc-50/80 transition hover:border-[var(--color-primary)]/50 hover:bg-[var(--color-primary)]/5 dark:border-zinc-600 dark:bg-zinc-800/80 dark:hover:border-[var(--color-primary)]/40 dark:hover:bg-[var(--color-primary)]/10"
                                >
                                    <template v-if="currentImageUrl">
                                        <img :src="currentImageUrl" alt="Preview" class="h-full w-full object-cover" />
                                        <span class="absolute inset-0 flex items-center justify-center bg-black/40 opacity-0 transition opacity hover:opacity-100">
                                            <span class="rounded-lg bg-white/90 px-2 py-1 text-xs font-medium text-zinc-800 dark:bg-zinc-900 dark:text-white">Trocar</span>
                                        </span>
                                    </template>
                                    <template v-else>
                                        <ImageIcon class="h-8 w-8 text-zinc-400 dark:text-zinc-500" />
                                        <span class="mt-1 text-center text-xs font-medium text-zinc-600 dark:text-zinc-400">{{ form.image?.name || 'Enviar' }}</span>
                                    </template>
                                    <input type="file" accept="image/*" class="hidden" @change="onFileChange" />
                                </label>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Preço e cobrança -->
                <section class="overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-zinc-800/95 xl:min-h-0">
                    <div class="border-b border-zinc-200/80 bg-zinc-50/80 px-6 py-4 dark:border-zinc-700/80 dark:bg-zinc-800/50">
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Preço e cobrança</h2>
                        <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Defina como o produto será cobrado e o valor base.</p>
                    </div>
                    <div class="p-6 space-y-6">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Tipo de cobrança *</label>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <button
                                    v-for="bt in billingTypes"
                                    :key="bt.value"
                                    type="button"
                                    :class="[
                                        'flex items-center justify-center rounded-xl border-2 px-4 py-3.5 text-sm font-medium transition-all duration-200',
                                        form.billing_type === bt.value
                                            ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10 text-[var(--color-primary)] shadow-sm dark:bg-[var(--color-primary)]/20 dark:text-[var(--color-primary)]'
                                            : 'border-zinc-200 bg-zinc-50/50 text-zinc-600 hover:border-zinc-300 hover:bg-zinc-100 dark:border-zinc-600 dark:bg-zinc-800/50 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:bg-zinc-700/50',
                                    ]"
                                    @click="form.billing_type = bt.value"
                                >
                                    {{ bt.label }}
                                </button>
                            </div>
                        </div>
                        <div class="max-w-xs space-y-4">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Preço base (BRL) *</label>
                                <input
                                    v-model="form.price"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    required
                                    placeholder="0,00"
                                    :class="inputClass"
                                />
                                <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">Aproximado: € {{ priceEur }} · US$ {{ priceUsd }}</p>
                                <p v-if="form.errors.price" class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ form.errors.price }}</p>
                            </div>
                            <div v-if="form.billing_type === 'subscription'">
                                <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Recorrência *</label>
                                <select v-model="form.base_interval" required :class="inputClass">
                                    <option value="weekly">Semanal</option>
                                    <option value="monthly">Mensal</option>
                                    <option value="quarterly">Trimestral</option>
                                    <option value="semi_annual">Semestral</option>
                                    <option value="annual">Anual</option>
                                    <option value="lifetime">Vitalício</option>
                                </select>
                                <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">Intervalo da cobrança recorrente do preço base.</p>
                            </div>
                        </div>

                        <!-- Ofertas (pagamento único) ou Planos (assinatura) -->
                        <div class="border-t border-zinc-200/80 pt-6 dark:border-zinc-600/80">
                            <p class="mb-3 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                {{ form.billing_type === 'one_time' ? 'Ofertas extras' : 'Planos de assinatura' }}
                            </p>
                            <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">
                                <template v-if="form.billing_type === 'one_time'">
                                    Múltiplas ofertas (preços). Cada uma tem seu próprio link de checkout.
                                </template>
                                <template v-else>
                                    Cadastre os planos (preço e periodicidade). Cada plano tem seu próprio link.
                                </template>
                            </p>

                            <!-- Lista de ofertas -->
                            <template v-if="form.billing_type === 'one_time'">
                                <ul class="mb-4 space-y-2">
                                    <li
                                        v-for="offer in (produto.offers || [])"
                                        :key="offer.id"
                                        class="flex flex-wrap items-center gap-3 rounded-lg border border-zinc-200/80 bg-zinc-50/50 px-3 py-2.5 dark:border-zinc-600/80 dark:bg-zinc-800/50"
                                    >
                                        <div class="min-w-0 flex-1">
                                            <span class="font-medium text-zinc-900 dark:text-white">{{ offer.name }}</span>
                                            <span class="ml-2 text-sm text-zinc-500 dark:text-zinc-400">{{ offer.currency }} {{ Number(offer.price).toFixed(2) }}</span>
                                            <a
                                                :href="getOfferCheckoutUrl(offer)"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="ml-2 inline-flex items-center gap-1 text-xs text-[var(--color-primary)] hover:underline"
                                            >
                                                <Link2 class="h-3 w-3" />
                                                Link
                                            </a>
                                        </div>
                                        <div class="flex gap-1.5">
                                            <Button size="sm" variant="outline" class="h-8 w-8 p-0" @click="openEditOffer(offer)">
                                                <Pencil class="h-3.5 w-3.5" />
                                            </Button>
                                            <Button size="sm" variant="outline" class="h-8 w-8 p-0 text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/30" @click="confirmDestroyOffer(offer)">
                                                <Trash2 class="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
                                    </li>
                                    <li v-if="!produto.offers || !produto.offers.length" class="rounded-lg border border-dashed border-zinc-200 py-4 text-center text-xs text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
                                        Nenhuma oferta. Adicione abaixo ou use apenas o preço base.
                                    </li>
                                </ul>
                                <form v-if="offerFormVisible" class="rounded-xl border border-zinc-200/80 bg-white p-4 dark:border-zinc-600/80 dark:bg-zinc-800/80" @submit.prevent="submitOffer">
                                    <p class="mb-3 text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ editingOffer ? 'Editar oferta' : 'Nova oferta' }}</p>
                                    <div class="grid gap-3 sm:grid-cols-[1fr,1fr,auto]">
                                        <input v-model="offerForm.name" type="text" required :class="inputClass" placeholder="Nome (ex: Básico)" />
                                        <input v-model="offerForm.price" type="number" step="0.01" min="0" required :class="inputClass" placeholder="Preço" />
                                        <select v-model="offerForm.currency" :class="inputClass + ' min-w-0'">
                                            <option value="BRL">BRL</option>
                                            <option value="EUR">EUR</option>
                                            <option value="USD">USD</option>
                                        </select>
                                    </div>
                                    <div class="mt-3 flex gap-2">
                                        <Button type="submit" size="sm" :disabled="offerForm.processing">{{ editingOffer ? 'Atualizar' : 'Adicionar' }}</Button>
                                        <Button type="button" size="sm" variant="outline" @click="closeOfferForm">Cancelar</Button>
                                    </div>
                                </form>
                                <Button v-else type="button" size="sm" variant="outline" class="mt-1" @click="openNewOffer">
                                    <Plus class="mr-1.5 h-3.5 w-3.5" />
                                    Adicionar oferta
                                </Button>
                            </template>

                            <!-- Lista de planos -->
                            <template v-else>
                                <ul class="mb-4 space-y-2">
                                    <li
                                        v-for="plan in (produto.subscription_plans || [])"
                                        :key="plan.id"
                                        class="flex flex-wrap items-center gap-3 rounded-lg border border-zinc-200/80 bg-zinc-50/50 px-3 py-2.5 dark:border-zinc-600/80 dark:bg-zinc-800/50"
                                    >
                                        <div class="min-w-0 flex-1">
                                            <span class="font-medium text-zinc-900 dark:text-white">{{ plan.name }}</span>
                                            <span class="ml-2 text-sm text-zinc-500 dark:text-zinc-400">{{ plan.currency }} {{ Number(plan.price).toFixed(2) }} · {{ intervalLabel(plan.interval) }}</span>
                                            <a
                                                :href="getPlanCheckoutUrl(plan)"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="ml-2 inline-flex items-center gap-1 text-xs text-[var(--color-primary)] hover:underline"
                                            >
                                                <Link2 class="h-3 w-3" />
                                                Link
                                            </a>
                                        </div>
                                        <div class="flex gap-1.5">
                                            <Button size="sm" variant="outline" class="h-8 w-8 p-0" @click="openEditPlan(plan)">
                                                <Pencil class="h-3.5 w-3.5" />
                                            </Button>
                                            <Button size="sm" variant="outline" class="h-8 w-8 p-0 text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/30" @click="confirmDestroyPlan(plan)">
                                                <Trash2 class="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
                                    </li>
                                    <li v-if="!produto.subscription_plans || !produto.subscription_plans.length" class="rounded-lg border border-dashed border-zinc-200 py-4 text-center text-xs text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
                                        Nenhum plano. Adicione abaixo.
                                    </li>
                                </ul>
                                <form v-if="planFormVisible" class="rounded-xl border border-zinc-200/80 bg-white p-4 dark:border-zinc-600/80 dark:bg-zinc-800/80" @submit.prevent="submitPlan">
                                    <p class="mb-3 text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ editingPlan ? 'Editar plano' : 'Novo plano' }}</p>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <input v-model="planForm.name" type="text" required :class="inputClass" placeholder="Nome (ex: Mensal)" />
                                        <input v-model="planForm.price" type="number" step="0.01" min="0" required :class="inputClass" placeholder="Preço" />
                                        <select v-model="planForm.currency" :class="inputClass">
                                            <option value="BRL">BRL</option>
                                            <option value="EUR">EUR</option>
                                            <option value="USD">USD</option>
                                        </select>
                                        <select v-model="planForm.interval" required :class="inputClass">
                                            <option value="weekly">Semanal</option>
                                            <option value="monthly">Mensal</option>
                                            <option value="quarterly">Trimestral</option>
                                            <option value="semi_annual">Semestral</option>
                                            <option value="annual">Anual</option>
                                            <option value="lifetime">Vitalício</option>
                                        </select>
                                    </div>
                                    <div class="mt-3 flex gap-2">
                                        <Button type="submit" size="sm" :disabled="planForm.processing">{{ editingPlan ? 'Atualizar' : 'Adicionar' }}</Button>
                                        <Button type="button" size="sm" variant="outline" @click="closePlanForm">Cancelar</Button>
                                    </div>
                                </form>
                                <Button v-else type="button" size="sm" variant="outline" class="mt-1" @click="openNewPlan">
                                    <Plus class="mr-1.5 h-3.5 w-3.5" />
                                    Adicionar plano
                                </Button>
                            </template>
                        </div>
                    </div>
                </section>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <Button type="submit" :disabled="form.processing">Salvar alterações</Button>
                    <Link
                        href="/produtos"
                        class="inline-flex items-center rounded-xl border border-zinc-200 px-4 py-2.5 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    >
                        Cancelar
                    </Link>
                </div>
            </form>
        </template>

        <!-- Aba Configurações -->
        <template v-if="currentTab === 'configuracoes'">
            <form class="w-full space-y-8" @submit.prevent="submit">
                <!-- Métodos de pagamento -->
                <section class="overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-zinc-800/95">
                    <div class="border-b border-zinc-200/80 px-6 py-4 dark:border-zinc-700/80">
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Métodos de pagamento</h2>
                        <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                            Selecione o gateway para cada forma de pagamento no checkout.
                        </p>
                    </div>
                    <div class="p-6">
                        <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                            <!-- PIX -->
                            <div class="rounded-xl border border-zinc-200/80 bg-zinc-50/50 p-5 dark:border-zinc-600/80 dark:bg-zinc-800/50">
                                <div class="flex flex-col gap-3">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white p-2 shadow-sm dark:bg-zinc-700/50">
                                            <img src="/images/payment-methods/pix.svg" alt="PIX" class="h-7 w-7 object-contain" @error="($e) => $e.target && ($e.target.style.display = 'none')" />
                                        </div>
                                        <div>
                                            <p class="font-semibold text-zinc-900 dark:text-white">PIX</p>
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400">Pagamento instantâneo</p>
                                        </div>
                                    </div>
                                    <GatewaySelect
                                        v-model="form.payment_gateways.pix"
                                        :options="gatewayOptions('pix')"
                                        placeholder="Nenhum"
                                        label="Gateway PIX"
                                    />
                                    <button
                                        v-if="canShowRedundancy(form.payment_gateways.pix)"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-2 text-sm font-medium text-zinc-700 transition hover:border-[var(--color-primary)] hover:bg-[var(--color-primary)]/10 hover:text-[var(--color-primary)] dark:border-zinc-600 dark:text-zinc-300 dark:hover:border-[var(--color-primary)] dark:hover:bg-[var(--color-primary)]/20"
                                        @click="openRedundancySidebar('pix')"
                                    >
                                        <Layers class="h-4 w-4" />
                                        Redundância
                                    </button>
                                    <p v-if="gateways_by_method.pix.length === 0" class="text-xs text-zinc-500 dark:text-zinc-400">
                                        <Link href="/integracoes?tab=gateways" class="text-[var(--color-primary)] hover:underline">Conectar gateway</Link>
                                    </p>
                                </div>
                            </div>
                            <!-- Cartão -->
                            <div class="rounded-xl border border-zinc-200/80 bg-zinc-50/50 p-5 dark:border-zinc-600/80 dark:bg-zinc-800/50">
                                <div class="flex flex-col gap-3">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white p-2 shadow-sm dark:bg-zinc-700/50">
                                            <img src="/images/payment-methods/card.png" alt="Cartão" class="h-7 w-7 object-contain" @error="($e) => $e.target && ($e.target.style.display = 'none')" />
                                        </div>
                                        <div>
                                            <p class="font-semibold text-zinc-900 dark:text-white">Cartão</p>
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400">Crédito e débito</p>
                                        </div>
                                    </div>
                                    <GatewaySelect
                                        v-model="form.payment_gateways.card"
                                        :options="gatewayOptions('card')"
                                        placeholder="Nenhum"
                                        label="Gateway Cartão"
                                    />
                                    <button
                                        v-if="canShowRedundancy(form.payment_gateways.card)"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-2 text-sm font-medium text-zinc-700 transition hover:border-[var(--color-primary)] hover:bg-[var(--color-primary)]/10 hover:text-[var(--color-primary)] dark:border-zinc-600 dark:text-zinc-300 dark:hover:border-[var(--color-primary)] dark:hover:bg-[var(--color-primary)]/20"
                                        @click="openRedundancySidebar('card')"
                                    >
                                        <Layers class="h-4 w-4" />
                                        Redundância
                                    </button>
                                    <button
                                        v-if="isBrBillingGateway(form.payment_gateways.card)"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-2 text-sm font-medium text-zinc-700 transition hover:border-[var(--color-primary)] hover:bg-[var(--color-primary)]/10 hover:text-[var(--color-primary)] dark:border-zinc-600 dark:text-zinc-300 dark:hover:border-[var(--color-primary)] dark:hover:bg-[var(--color-primary)]/20"
                                        @click="pagarmeBillingSidebarOpen = true"
                                    >
                                        <MapPin class="h-4 w-4" />
                                        Configurar endereço (checkout)
                                    </button>
                                    <p v-if="gateways_by_method.card.length === 0" class="text-xs text-zinc-500 dark:text-zinc-400">
                                        <Link href="/integracoes?tab=gateways" class="text-[var(--color-primary)] hover:underline">Conectar gateway</Link>
                                    </p>
                                    <template v-if="form.payment_gateways.card === 'efi' || form.payment_gateways.card === 'asaas'">
                                        <div class="mt-3 space-y-3 border-t border-zinc-200/80 pt-3 dark:border-zinc-600/80">
                                            <div class="flex items-center justify-between rounded-xl border border-zinc-100 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                                                <div class="min-w-0">
                                                    <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Permitir parcelamento</p>
                                                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Cliente poderá parcelar no cartão de crédito ({{ form.payment_gateways.card === 'efi' ? 'Efí' : 'Asaas' }})</p>
                                                </div>
                                                <Toggle v-model="form.card_installments.enabled" class="shrink-0" />
                                            </div>
                                            <div v-if="form.card_installments.enabled" class="rounded-xl border border-zinc-100 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                                                <label for="card-installments-max" class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Até quantas parcelas</label>
                                                <select
                                                    id="card-installments-max"
                                                    v-model.number="form.card_installments.max"
                                                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100"
                                                >
                                                    <option v-for="n in maxAllowedInstallments" :key="n" :value="n">{{ n }}x</option>
                                                </select>
                                                <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">Com o preço de R$ {{ priceNum.toFixed(2) }}, até {{ maxAllowedInstallments }}x (mín. R$ {{ MIN_PARCELA_BRL }},00 por parcela).</p>
                                            </div>
                                        </div>
                                    </template>
                                    <template v-if="form.payment_gateways.card === 'stripe'">
                                        <div class="mt-3 space-y-3 border-t border-zinc-200/80 pt-3 dark:border-zinc-600/80">
                                            <div class="flex items-center justify-between rounded-xl border border-zinc-100 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                                                <div class="min-w-0">
                                                    <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Usar Stripe Link</p>
                                                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Permitir que o cliente use Link para preencher dados do cartão (Stripe)</p>
                                                </div>
                                                <Toggle v-model="form.stripe_link_enabled" class="shrink-0" />
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                            <!-- Boleto -->
                            <div class="rounded-xl border border-zinc-200/80 bg-zinc-50/50 p-5 dark:border-zinc-600/80 dark:bg-zinc-800/50">
                                <div class="flex flex-col gap-3">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white p-2 shadow-sm dark:bg-zinc-700/50">
                                            <img src="/images/payment-methods/boleto.png" alt="Boleto" class="h-7 w-7 object-contain" @error="($e) => $e.target && ($e.target.style.display = 'none')" />
                                        </div>
                                        <div>
                                            <p class="font-semibold text-zinc-900 dark:text-white">Boleto</p>
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400">Pagamento bancário</p>
                                        </div>
                                    </div>
                                    <GatewaySelect
                                        v-model="form.payment_gateways.boleto"
                                        :options="gatewayOptions('boleto')"
                                        placeholder="Nenhum"
                                        label="Gateway Boleto"
                                    />
                                    <button
                                        v-if="canShowRedundancy(form.payment_gateways.boleto)"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-2 text-sm font-medium text-zinc-700 transition hover:border-[var(--color-primary)] hover:bg-[var(--color-primary)]/10 hover:text-[var(--color-primary)] dark:border-zinc-600 dark:text-zinc-300 dark:hover:border-[var(--color-primary)] dark:hover:bg-[var(--color-primary)]/20"
                                        @click="openRedundancySidebar('boleto')"
                                    >
                                        <Layers class="h-4 w-4" />
                                        Redundância
                                    </button>
                                    <button
                                        v-if="isBrBillingGateway(form.payment_gateways.boleto)"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-2 text-sm font-medium text-zinc-700 transition hover:border-[var(--color-primary)] hover:bg-[var(--color-primary)]/10 hover:text-[var(--color-primary)] dark:border-zinc-600 dark:text-zinc-300 dark:hover:border-[var(--color-primary)] dark:hover:bg-[var(--color-primary)]/20"
                                        @click="pagarmeBillingSidebarOpen = true"
                                    >
                                        <MapPin class="h-4 w-4" />
                                        Configurar endereço (checkout)
                                    </button>
                                    <p v-if="gateways_by_method.boleto.length === 0" class="text-xs text-zinc-500 dark:text-zinc-400">
                                        <Link href="/integracoes?tab=gateways" class="text-[var(--color-primary)] hover:underline">Conectar gateway</Link>
                                    </p>
                                </div>
                            </div>
                            <!-- PIX automático (somente Assinatura) -->
                            <div
                                v-if="form.billing_type === 'subscription'"
                                class="rounded-xl border border-zinc-200/80 bg-zinc-50/50 p-5 dark:border-zinc-600/80 dark:bg-zinc-800/50"
                            >
                                <div class="flex flex-col gap-3">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white p-2 shadow-sm dark:bg-zinc-700/50">
                                            <img src="/images/gateways/pix-automatico.png" alt="PIX automático" class="h-7 w-7 object-contain" @error="($e) => $e.target && ($e.target.style.display = 'none')" />
                                        </div>
                                        <div>
                                            <p class="font-semibold text-zinc-900 dark:text-white">PIX automático</p>
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400">Renovação automática (assinaturas)</p>
                                        </div>
                                    </div>
                                    <GatewaySelect
                                        v-model="form.payment_gateways.pix_auto"
                                        :options="gatewayOptions('pix_auto')"
                                        placeholder="Nenhum"
                                        label="Gateway PIX automático"
                                    />
                                    <button
                                        v-if="canShowRedundancy(form.payment_gateways.pix_auto)"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-2 text-sm font-medium text-zinc-700 transition hover:border-[var(--color-primary)] hover:bg-[var(--color-primary)]/10 hover:text-[var(--color-primary)] dark:border-zinc-600 dark:text-zinc-300 dark:hover:border-[var(--color-primary)] dark:hover:bg-[var(--color-primary)]/20"
                                        @click="openRedundancySidebar('pix_auto')"
                                    >
                                        <Layers class="h-4 w-4" />
                                        Redundância
                                    </button>
                                    <p v-if="(gateways_by_method.pix_auto || []).length === 0" class="text-xs text-zinc-500 dark:text-zinc-400">
                                        <Link href="/integracoes?tab=gateways" class="text-[var(--color-primary)] hover:underline">Conectar gateway Efí</Link>
                                    </p>
                                </div>
                            </div>
                            <!-- Criptomoeda -->
                            <div class="rounded-xl border border-zinc-200/80 bg-zinc-50/50 p-5 dark:border-zinc-600/80 dark:bg-zinc-800/50">
                                <div class="flex flex-col gap-3">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white p-2 shadow-sm dark:bg-zinc-700/50">
                                            <img src="/images/payment-methods/cripto.png" alt="Criptomoeda" class="h-7 w-7 object-contain" @error="($e) => $e.target && ($e.target.style.display = 'none')" />
                                        </div>
                                        <div>
                                            <p class="font-semibold text-zinc-900 dark:text-white">Criptomoeda</p>
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400">Bitcoin e outras</p>
                                        </div>
                                    </div>
                                    <GatewaySelect
                                        v-model="form.payment_gateways.crypto"
                                        :options="gatewayOptions('crypto')"
                                        placeholder="Nenhum"
                                        label="Gateway Criptomoeda"
                                    />
                                    <button
                                        v-if="canShowRedundancy(form.payment_gateways.crypto)"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-2 text-sm font-medium text-zinc-700 transition hover:border-[var(--color-primary)] hover:bg-[var(--color-primary)]/10 hover:text-[var(--color-primary)] dark:border-zinc-600 dark:text-zinc-300 dark:hover:border-[var(--color-primary)] dark:hover:bg-[var(--color-primary)]/20"
                                        @click="openRedundancySidebar('crypto')"
                                    >
                                        <Layers class="h-4 w-4" />
                                        Redundância
                                    </button>
                                    <p v-if="(gateways_by_method.crypto || []).length === 0" class="text-xs text-zinc-500 dark:text-zinc-400">
                                        <Link href="/integracoes?tab=gateways" class="text-[var(--color-primary)] hover:underline">Conectar gateway</Link>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Tipo de entrega -->
                <section class="overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-zinc-800/95">
                    <div class="border-b border-zinc-200/80 bg-zinc-50/80 px-6 py-4 dark:border-zinc-700/80 dark:bg-zinc-800/50">
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Tipo de entrega</h2>
                        <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Como o cliente recebe o produto após a compra.</p>
                    </div>
                    <div class="p-6">
                        <div class="grid gap-3 grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                            <button
                                v-for="t in productTypes"
                                :key="t.value"
                                type="button"
                                :disabled="!t.available"
                                :class="[
                                    'flex items-start gap-3 rounded-xl border-2 p-4 text-left transition',
                                    form.type === t.value
                                        ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10 dark:bg-[var(--color-primary)]/20'
                                        : 'border-zinc-200 bg-zinc-50 hover:border-zinc-300 hover:bg-zinc-100 dark:border-zinc-600 dark:bg-zinc-800 dark:hover:border-zinc-500 dark:hover:bg-zinc-700',
                                    !t.available && 'cursor-not-allowed opacity-60',
                                ]"
                                @click="t.available && (form.type = t.value)"
                            >
                                <component :is="typeIcons[t.value] || Package" class="mt-0.5 h-5 w-5 shrink-0 text-zinc-500 dark:text-zinc-400" />
                                <div class="min-w-0 flex-1">
                                    <span class="font-medium text-zinc-900 dark:text-white">{{ t.label }}</span>
                                    <span v-if="!t.available" class="ml-1 text-xs text-zinc-500">(em breve)</span>
                                </div>
                                <button
                                    v-if="t.available && t.value === 'link' && form.type === t.value"
                                    type="button"
                                    class="shrink-0 rounded-lg p-1.5 text-zinc-500 transition hover:bg-zinc-200 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-600 dark:hover:text-white"
                                    title="Configurar link do entregável"
                                    aria-label="Configurar link do entregável"
                                    @click.stop="deliverableLinkSidebarOpen = true"
                                >
                                    <Settings class="h-4 w-4" aria-hidden="true" />
                                </button>
                                <button
                                    v-if="t.available && t.value === 'area_membros' && form.type === t.value"
                                    type="button"
                                    class="shrink-0 rounded-lg p-1.5 text-zinc-500 transition hover:bg-zinc-200 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-600 dark:hover:text-white"
                                    title="Abrir Member Builder"
                                    aria-label="Abrir Member Builder"
                                    @click.stop="goToMemberBuilder"
                                >
                                    <Settings class="h-4 w-4" aria-hidden="true" />
                                </button>
                            </button>
                        </div>
                        <p v-if="form.errors.type" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ form.errors.type }}</p>
                    </div>
                </section>

                <!-- Área de membros externa (Cademí) -->
                <section
                    v-if="form.type === 'area_membros_externa'"
                    class="overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-zinc-800/95"
                >
                    <div class="border-b border-zinc-200/80 bg-zinc-50/80 px-6 py-4 dark:border-zinc-700/80 dark:bg-zinc-800/50">
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Área de membros externa (Cademí)</h2>
                        <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                            Se este produto for entregue via Cademí, configure aqui qual integração e qual TAG o aluno receberá após o pagamento.
                        </p>
                    </div>
                    <div class="p-6 space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Integração Cademí</label>
                                <select
                                    v-model="cademiConfig.integration_id"
                                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                >
                                    <option value="">(desconectado)</option>
                                    <option v-for="i in cademi_integrations" :key="i.id" :value="String(i.id)">
                                        {{ i.name }}
                                    </option>
                                </select>
                                <p v-if="cademi_integrations.length === 0" class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    Nenhuma integração Cademí cadastrada. Vá em <Link href="/integracoes" class="text-[var(--color-primary)] hover:underline">/integracoes</Link> e crie uma.
                                </p>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Produto ID (Cademí)</label>
                                <div class="space-y-2">
                                    <div v-for="(pid, idx) in cademiConfig.cademi_produto_ids" :key="idx" class="flex gap-2">
                                        <input
                                            v-model="cademiConfig.cademi_produto_ids[idx]"
                                            type="number"
                                            min="1"
                                            placeholder="Ex: 231"
                                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                        />
                                        <Button type="button" variant="outline" :disabled="cademiConfig.cademi_produto_ids.length <= 1" @click="cademiConfig.cademi_produto_ids.splice(idx, 1)">
                                            Remover
                                        </Button>
                                    </div>
                                    <Button type="button" variant="outline" @click="cademiConfig.cademi_produto_ids.push('')">
                                        Adicionar Produto ID
                                    </Button>
                                </div>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    Obrigatório para conceder acesso na Cademí.
                                </p>
                            </div>

                            <div>
                                <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">TAG ID (Cademí) (opcional)</label>
                                <div class="space-y-2">
                                    <div class="flex gap-2">
                                        <select
                                            v-model="cademiConfig.cademi_tag_id"
                                            class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                            :disabled="!cademiConfig.integration_id || cademiTagsLoading"
                                        >
                                            <option value="">Selecione uma TAG</option>
                                            <option v-for="t in filteredCademiTags" :key="t.id" :value="String(t.id)">
                                                {{ t.nome ? `${t.nome} (#${t.id})` : `#${t.id}` }}
                                            </option>
                                        </select>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            :disabled="!cademiConfig.integration_id || cademiTagsLoading"
                                            @click="loadCademiTags"
                                        >
                                            <Loader2 v-if="cademiTagsLoading" class="mr-2 h-4 w-4 animate-spin" />
                                            Atualizar
                                        </Button>
                                    </div>

                                    <input
                                        v-model="cademiTagQuery"
                                        type="text"
                                        placeholder="Buscar TAG pelo nome…"
                                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                        :disabled="!cademiConfig.integration_id || cademiTagsLoading || (cademiTags || []).length === 0"
                                    />

                                    <input
                                        v-model="cademiConfig.cademi_tag_id"
                                        type="number"
                                        min="1"
                                        placeholder="Ou cole o TAG ID (ex: 472)"
                                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-[var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                        :disabled="!cademiConfig.integration_id"
                                    />

                                    <p v-if="cademiTagsError" class="text-xs text-red-600 dark:text-red-400">{{ cademiTagsError }}</p>
                                    <p v-else-if="cademiConfig.integration_id && !cademiTagsLoading && (cademiTags || []).length === 0" class="text-xs text-zinc-500 dark:text-zinc-400">
                                        Nenhuma TAG retornada pela Cademí (ou integração ainda não carregada).
                                    </p>
                                </div>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    Dica: selecione pelo nome (recomendado). Se necessário, cole o ID manualmente no campo acima.
                                </p>
                            </div>
                        </div>

                        <div class="flex items-end">
                            <Button type="button" class="w-full" :disabled="cademiSaving" @click="saveCademiProductMapping">
                                <Loader2 v-if="cademiSaving" class="mr-2 h-4 w-4 animate-spin" />
                                Salvar configuração Cademí
                            </Button>
                        </div>

                        <p v-if="cademiError" class="rounded-lg bg-red-100 px-3 py-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">
                            {{ cademiError }}
                        </p>
                    </div>
                </section>

                <!-- Sidebar: Link do entregável (só quando tipo Link) -->
                <Teleport to="body">
                    <Transition
                        enter-active-class="transition-opacity duration-200"
                        enter-from-class="opacity-0"
                        enter-to-class="opacity-100"
                        leave-active-class="transition-opacity duration-200"
                        leave-from-class="opacity-100"
                        leave-to-class="opacity-0"
                    >
                        <div
                            v-if="deliverableLinkSidebarOpen"
                            class="fixed inset-0 z-[100000] bg-black/30"
                            aria-hidden="true"
                            @click="deliverableLinkSidebarOpen = false"
                        />
                    </Transition>
                    <Transition
                        enter-active-class="transition-transform duration-300 ease-out"
                        enter-from-class="translate-x-full"
                        enter-to-class="translate-x-0"
                        leave-active-class="transition-transform duration-300 ease-in"
                        leave-from-class="translate-x-0"
                        leave-to-class="translate-x-full"
                    >
                        <aside
                            v-if="deliverableLinkSidebarOpen"
                            class="fixed top-0 right-0 h-full w-full max-w-md bg-white dark:bg-zinc-900 shadow-2xl z-[100001] flex flex-col"
                            role="dialog"
                            aria-labelledby="deliverable-link-sidebar-title"
                            @click.stop
                        >
                            <div class="flex shrink-0 items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                                <div class="flex items-center gap-2">
                                    <Settings class="h-5 w-5 text-zinc-500 dark:text-white" aria-hidden="true" />
                                    <h2 id="deliverable-link-sidebar-title" class="text-lg font-semibold text-zinc-900 dark:text-white">Link do entregável</h2>
                                </div>
                                <button
                                    type="button"
                                    class="rounded-lg p-2 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white"
                                    aria-label="Fechar"
                                    @click="deliverableLinkSidebarOpen = false"
                                >
                                    <X class="h-5 w-5" />
                                </button>
                            </div>
                            <div class="flex-1 overflow-y-auto p-4">
                                <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
                                    URL enviada por e-mail ao cliente após a compra. O link deve apontar para o conteúdo (página, arquivo, etc.).
                                </p>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">URL do entregável</label>
                                    <input v-model="form.deliverable_link" type="url" placeholder="https://..." :class="inputClass" />
                                    <p v-if="form.errors.deliverable_link" class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ form.errors.deliverable_link }}</p>
                                </div>
                            </div>
                            <div class="shrink-0 border-t border-zinc-200 p-4 flex gap-2 dark:border-zinc-700">
                                <Button type="button" class="flex-1" :disabled="form.processing" @click="submit(); deliverableLinkSidebarOpen = false">
                                    Salvar
                                </Button>
                                <Button type="button" variant="outline" class="flex-1" @click="deliverableLinkSidebarOpen = false">
                                    Fechar
                                </Button>
                            </div>
                        </aside>
                    </Transition>
                </Teleport>

                <GatewayRedundancySidebar
                    :open="redundancySidebarOpen"
                    :method="redundancySidebarMethod"
                    :method-label="METHOD_LABELS[redundancySidebarMethod] || redundancySidebarMethod"
                    :primary-slug="redundancySidebarMethod ? (form.payment_gateways[redundancySidebarMethod] || '') : ''"
                    :gateways="gateways_by_method[redundancySidebarMethod] || []"
                    :model-value="redundancySidebarMethod ? (form.payment_gateways[redundancySidebarMethod + '_redundancy'] || []) : []"
                    @update:model-value="(val) => redundancySidebarMethod && (form.payment_gateways[redundancySidebarMethod + '_redundancy'] = val)"
                    @save="(val) => { if (redundancySidebarMethod) { form.payment_gateways[redundancySidebarMethod + '_redundancy'] = val; submit(); } redundancySidebarOpen = false; }"
                    @close="redundancySidebarOpen = false"
                />

                <Teleport to="body">
                    <Transition
                        enter-active-class="transition-opacity duration-200"
                        enter-from-class="opacity-0"
                        enter-to-class="opacity-100"
                        leave-active-class="transition-opacity duration-200"
                        leave-from-class="opacity-100"
                        leave-to-class="opacity-0"
                    >
                        <div
                            v-if="pagarmeBillingSidebarOpen"
                            class="fixed inset-0 z-[100000] bg-black/30"
                            aria-hidden="true"
                            @click="pagarmeBillingSidebarOpen = false"
                        />
                    </Transition>
                    <Transition
                        enter-active-class="transition-transform duration-300 ease-out"
                        enter-from-class="translate-x-full"
                        enter-to-class="translate-x-0"
                        leave-active-class="transition-transform duration-300 ease-in"
                        leave-from-class="translate-x-0"
                        leave-to-class="translate-x-full"
                    >
                        <aside
                            v-if="pagarmeBillingSidebarOpen"
                            class="fixed top-0 right-0 z-[100001] flex h-full w-full max-w-md flex-col bg-white shadow-2xl dark:bg-zinc-900"
                            role="dialog"
                            aria-labelledby="pagarme-billing-sidebar-title"
                            @click.stop
                        >
                            <div class="flex shrink-0 items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                                <div class="flex items-center gap-2">
                                    <MapPin class="h-5 w-5 text-zinc-500 dark:text-white" aria-hidden="true" />
                                    <h2 id="pagarme-billing-sidebar-title" class="text-lg font-semibold text-zinc-900 dark:text-white">
                                        Cobrança — endereço (Pagar.me / Efí)
                                    </h2>
                                </div>
                                <button
                                    type="button"
                                    class="rounded-lg p-2 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white"
                                    aria-label="Fechar"
                                    @click="pagarmeBillingSidebarOpen = false"
                                >
                                    <X class="h-5 w-5" />
                                </button>
                            </div>
                            <div class="flex-1 space-y-4 overflow-y-auto p-4">
                                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                                    Quando usar <strong>Pagar.me</strong> ou <strong>Efí</strong> no cartão e/ou no boleto, defina se o checkout pede o endereço do cliente ou usa o endereço da empresa (fatura/antifraude). No modo empresa, preencha o endereço abaixo (CEP com 8 dígitos, UF com 2 letras).
                                </p>
                                <div class="space-y-2 rounded-xl border border-zinc-200 bg-zinc-50/80 p-3 dark:border-zinc-600 dark:bg-zinc-800/50">
                                    <p class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Modo no checkout</p>
                                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-600 dark:bg-zinc-900/80">
                                        <input v-model="form.pagarme_billing.mode" type="radio" class="mt-0.5" value="customer" />
                                        <span>
                                            <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Solicitar endereço do cliente</span>
                                            <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">O checkout exibe o bloco de CEP/endereço.</span>
                                        </span>
                                    </label>
                                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-600 dark:bg-zinc-900/80">
                                        <input v-model="form.pagarme_billing.mode" type="radio" class="mt-0.5" value="company" />
                                        <span>
                                            <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Usar endereço da empresa</span>
                                            <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">O checkout não pede endereço; usamos os dados abaixo na cobrança.</span>
                                        </span>
                                    </label>
                                </div>
                                <p v-if="form.errors['pagarme_billing.company_address']" class="text-sm text-red-600 dark:text-red-400">
                                    {{ form.errors['pagarme_billing.company_address'] }}
                                </p>
                                <p class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Endereço da empresa</p>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">CEP</label>
                                    <div class="flex gap-2">
                                        <input
                                            :value="form.pagarme_billing.company_address.zipcode"
                                            type="text"
                                            inputmode="numeric"
                                            maxlength="9"
                                            placeholder="00000-000"
                                            :class="inputClass"
                                            @input="onPagarmeCompanyCepInput"
                                        />
                                        <Button
                                            type="button"
                                            variant="outline"
                                            :disabled="pagarmeCompanyCepLoading || (form.pagarme_billing.company_address.zipcode || '').replace(/\D/g, '').length < 8"
                                            @click="fetchPagarmeCompanyCep"
                                        >
                                            <Loader2 v-if="pagarmeCompanyCepLoading" class="h-4 w-4 animate-spin" />
                                            <span v-else>Buscar</span>
                                        </Button>
                                    </div>
                                    <p v-if="pagarmeCompanyCepError" class="mt-1 text-xs text-amber-600 dark:text-amber-400">{{ pagarmeCompanyCepError }}</p>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Rua</label>
                                    <input v-model="form.pagarme_billing.company_address.street" type="text" :class="inputClass" />
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Número</label>
                                        <input v-model="form.pagarme_billing.company_address.number" type="text" :class="inputClass" />
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">UF</label>
                                        <input
                                            v-model="form.pagarme_billing.company_address.state"
                                            type="text"
                                            maxlength="2"
                                            class="uppercase"
                                            :class="inputClass"
                                            @blur="form.pagarme_billing.company_address.state = (form.pagarme_billing.company_address.state || '').toUpperCase().slice(0, 2)"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Bairro</label>
                                    <input v-model="form.pagarme_billing.company_address.neighborhood" type="text" :class="inputClass" />
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Cidade</label>
                                    <input v-model="form.pagarme_billing.company_address.city" type="text" :class="inputClass" />
                                </div>
                            </div>
                            <div class="flex shrink-0 gap-2 border-t border-zinc-200 p-4 dark:border-zinc-700">
                                <Button type="button" class="flex-1" :disabled="form.processing" @click="submit(); pagarmeBillingSidebarOpen = false">
                                    Salvar
                                </Button>
                                <Button type="button" variant="outline" class="flex-1" @click="pagarmeBillingSidebarOpen = false">Fechar</Button>
                            </div>
                        </aside>
                    </Transition>
                </Teleport>

                <!-- Pixels de conversão -->
                <section class="overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-zinc-800/95">
                    <div class="border-b border-zinc-200/80 bg-gradient-to-r from-zinc-50/90 to-zinc-100/50 px-6 py-5 dark:from-zinc-800/80 dark:to-zinc-800/50">
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Pixels de conversão</h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Configure pixels para rastrear conversões no checkout, upsell e downsell.</p>
                    </div>
                    <div class="space-y-6 p-6">
                        <!-- Carrossel de abas -->
                        <div class="flex gap-3 overflow-x-auto pb-2 scroll-smooth" style="scrollbar-width: thin;">
                            <button
                                v-for="tab in PIXEL_TABS"
                                :key="tab.id"
                                type="button"
                                :class="[
                                    'flex shrink-0 flex-col items-center justify-center gap-1.5 rounded-xl border-2 p-4 w-28 h-24 transition-all duration-200',
                                    selectedPixelTab === tab.id
                                        ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10 dark:bg-[var(--color-primary)]/20'
                                        : 'border-zinc-200 bg-zinc-50 hover:border-zinc-300 hover:bg-zinc-100 dark:border-zinc-600 dark:bg-zinc-800 dark:hover:border-zinc-500 dark:hover:bg-zinc-700',
                                ]"
                                @click="selectedPixelTab = tab.id"
                            >
                                <img :src="tab.image" :alt="tab.label" class="h-8 w-8 object-contain" @error="($e) => $e.target && ($e.target.style.display = 'none')" />
                                <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">{{ tab.label }}</span>
                            </button>
                        </div>

                        <!-- Painel Meta Ads -->
                        <div v-if="selectedPixelTab === 'meta'" class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50 space-y-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Meta Ads (Facebook)</h3>
                                <div class="flex items-center gap-3">
                                    <Button type="button" variant="outline" size="sm" :disabled="!form.conversion_pixels.meta.enabled" @click="form.conversion_pixels.meta.entries.push(newMetaEntry())">
                                        <Plus class="h-4 w-4 mr-1" /> Adicionar pixel
                                    </Button>
                                    <Toggle v-model="form.conversion_pixels.meta.enabled" />
                                </div>
                            </div>
                            <template v-if="form.conversion_pixels.meta.enabled">
                                <div v-for="(item, idx) in form.conversion_pixels.meta.entries" :key="item.id" class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800 space-y-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Pixel {{ idx + 1 }}</span>
                                        <button type="button" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300" @click="form.conversion_pixels.meta.entries.splice(idx, 1)">
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Pixel ID</label>
                                        <input v-model="item.pixel_id" type="text" placeholder="Ex: 123456789" :class="inputClass" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Access Token (CAPI)</label>
                                        <input v-model="item.access_token" type="password" placeholder="Token para Conversions API" :class="inputClass" autocomplete="off" />
                                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Usado para enviar eventos server-side (CAPI).</p>
                                    </div>
                                    <div class="space-y-3 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                                        <Checkbox v-model="item.fire_purchase_on_pix" label="Disparar evento Purchase ao gerar PIX?" />
                                        <Checkbox v-model="item.fire_purchase_on_boleto" label="Disparar evento Purchase ao gerar Boleto?" />
                                        <Checkbox v-model="item.disable_order_bump_events" label="Desativar eventos de order bumps?" />
                                    </div>
                                </div>
                                <p v-if="form.conversion_pixels.meta.entries.length === 0" class="text-sm text-zinc-500 dark:text-zinc-400">Nenhum pixel. Clique em «Adicionar pixel» ou desative a integração.</p>
                            </template>
                        </div>

                        <!-- Painel TikTok Ads -->
                        <div v-if="selectedPixelTab === 'tiktok'" class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50 space-y-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">TikTok Ads</h3>
                                <div class="flex items-center gap-3">
                                    <Button type="button" variant="outline" size="sm" :disabled="!form.conversion_pixels.tiktok.enabled" @click="form.conversion_pixels.tiktok.entries.push(newTiktokEntry())">
                                        <Plus class="h-4 w-4 mr-1" /> Adicionar pixel
                                    </Button>
                                    <Toggle v-model="form.conversion_pixels.tiktok.enabled" />
                                </div>
                            </div>
                            <template v-if="form.conversion_pixels.tiktok.enabled">
                                <div v-for="(item, idx) in form.conversion_pixels.tiktok.entries" :key="item.id" class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800 space-y-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Pixel {{ idx + 1 }}</span>
                                        <button type="button" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300" @click="form.conversion_pixels.tiktok.entries.splice(idx, 1)">
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Pixel ID</label>
                                        <input v-model="item.pixel_id" type="text" placeholder="Ex: C1X2Y3Z4..." :class="inputClass" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Access Token</label>
                                        <input v-model="item.access_token" type="password" placeholder="Token do TikTok Events API" :class="inputClass" autocomplete="off" />
                                    </div>
                                    <div class="space-y-3 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                                        <Checkbox v-model="item.fire_purchase_on_pix" label="Disparar evento Purchase ao gerar PIX?" />
                                        <Checkbox v-model="item.fire_purchase_on_boleto" label="Disparar evento Purchase ao gerar Boleto?" />
                                        <Checkbox v-model="item.disable_order_bump_events" label="Desativar eventos de order bumps?" />
                                    </div>
                                </div>
                                <p v-if="form.conversion_pixels.tiktok.entries.length === 0" class="text-sm text-zinc-500 dark:text-zinc-400">Nenhum pixel. Clique em «Adicionar pixel» ou desative a integração.</p>
                            </template>
                        </div>

                        <!-- Painel Google Ads -->
                        <div v-if="selectedPixelTab === 'google_ads'" class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50 space-y-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Google Ads</h3>
                                <div class="flex items-center gap-3">
                                    <Button type="button" variant="outline" size="sm" :disabled="!form.conversion_pixels.google_ads.enabled" @click="form.conversion_pixels.google_ads.entries.push(newGoogleAdsEntry())">
                                        <Plus class="h-4 w-4 mr-1" /> Adicionar conversão
                                    </Button>
                                    <Toggle v-model="form.conversion_pixels.google_ads.enabled" />
                                </div>
                            </div>
                            <template v-if="form.conversion_pixels.google_ads.enabled">
                                <div v-for="(item, idx) in form.conversion_pixels.google_ads.entries" :key="item.id" class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800 space-y-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Conversão {{ idx + 1 }}</span>
                                        <button type="button" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300" @click="form.conversion_pixels.google_ads.entries.splice(idx, 1)">
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Conversion ID</label>
                                        <input v-model="item.conversion_id" type="text" placeholder="Ex: AW-123456789" :class="inputClass" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Conversion Label</label>
                                        <input v-model="item.conversion_label" type="text" placeholder="Ex: AbCdEfGhIjKlMn" :class="inputClass" />
                                    </div>
                                    <div class="space-y-3 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                                        <Checkbox v-model="item.fire_purchase_on_pix" label="Disparar evento Purchase ao gerar PIX?" />
                                        <Checkbox v-model="item.fire_purchase_on_boleto" label="Disparar evento Purchase ao gerar Boleto?" />
                                        <Checkbox v-model="item.disable_order_bump_events" label="Desativar eventos de order bumps?" />
                                    </div>
                                </div>
                                <p v-if="form.conversion_pixels.google_ads.entries.length === 0" class="text-sm text-zinc-500 dark:text-zinc-400">Nenhuma conversão. Clique em «Adicionar conversão» ou desative a integração.</p>
                            </template>
                        </div>

                        <!-- Painel Google Analytics -->
                        <div v-if="selectedPixelTab === 'google_analytics'" class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50 space-y-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Google Analytics (GA4)</h3>
                                <div class="flex items-center gap-3">
                                    <Button type="button" variant="outline" size="sm" :disabled="!form.conversion_pixels.google_analytics.enabled" @click="form.conversion_pixels.google_analytics.entries.push(newGaEntry())">
                                        <Plus class="h-4 w-4 mr-1" /> Adicionar propriedade
                                    </Button>
                                    <Toggle v-model="form.conversion_pixels.google_analytics.enabled" />
                                </div>
                            </div>
                            <template v-if="form.conversion_pixels.google_analytics.enabled">
                                <div v-for="(item, idx) in form.conversion_pixels.google_analytics.entries" :key="item.id" class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800 space-y-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">GA4 {{ idx + 1 }}</span>
                                        <button type="button" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300" @click="form.conversion_pixels.google_analytics.entries.splice(idx, 1)">
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Measurement ID</label>
                                        <input v-model="item.measurement_id" type="text" placeholder="Ex: G-XXXXXXXXXX" :class="inputClass" />
                                    </div>
                                    <div class="space-y-3 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                                        <Checkbox v-model="item.fire_purchase_on_pix" label="Disparar evento Purchase ao gerar PIX?" />
                                        <Checkbox v-model="item.fire_purchase_on_boleto" label="Disparar evento Purchase ao gerar Boleto?" />
                                        <Checkbox v-model="item.disable_order_bump_events" label="Desativar eventos de order bumps?" />
                                    </div>
                                </div>
                                <p v-if="form.conversion_pixels.google_analytics.entries.length === 0" class="text-sm text-zinc-500 dark:text-zinc-400">Nenhuma propriedade. Clique em «Adicionar propriedade» ou desative a integração.</p>
                            </template>
                        </div>

                        <!-- Painel Script personalizado -->
                        <div v-if="selectedPixelTab === 'custom_script'" class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50 space-y-4">
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Scripts personalizados</h3>
                                <Button type="button" variant="outline" size="sm" @click="form.conversion_pixels.custom_script.push({ id: randomClientId(), name: '', script: '' })">
                                    <Plus class="h-4 w-4 mr-1" /> Adicionar pixel
                                </Button>
                            </div>
                            <div v-for="(item, idx) in form.conversion_pixels.custom_script" :key="item.id" class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800 space-y-3">
                                <div class="flex items-center gap-2">
                                    <input v-model="item.name" type="text" placeholder="Nome (opcional)" :class="inputClass + ' flex-1'" />
                                    <button type="button" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300" @click="form.conversion_pixels.custom_script.splice(idx, 1)">
                                        <Trash2 class="h-4 w-4" />
                                    </button>
                                </div>
                                <textarea v-model="item.script" rows="4" :class="inputClass + ' font-mono text-sm'" placeholder="Cole o código do pixel aqui (ex: <script>...</script>)" />
                            </div>
                            <p v-if="form.conversion_pixels.custom_script.length === 0" class="text-sm text-zinc-500 dark:text-zinc-400">Nenhum script adicionado. Clique em "Adicionar pixel" para incluir um código de rastreamento personalizado.</p>
                        </div>
                    </div>
                </section>
                <div class="flex flex-wrap items-center gap-3">
                    <Button type="submit" :disabled="form.processing">Salvar alterações</Button>
                    <Link
                        href="/produtos"
                        class="inline-flex items-center rounded-xl border border-zinc-200 px-4 py-2.5 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    >
                        Cancelar
                    </Link>
                </div>
            </form>
        </template>

        <!-- Aba E-mail -->
        <template v-if="currentTab === 'email'">
            <form class="mx-auto w-full max-w-3xl space-y-8 xl:max-w-6xl" @submit.prevent="submit">
                <div class="grid grid-cols-1 gap-8 xl:grid-cols-2">
                    <!-- Configuração do template -->
                    <section class="overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-zinc-800/95">
                        <div class="border-b border-zinc-200/80 bg-gradient-to-r from-zinc-50/90 to-zinc-100/50 px-6 py-5 dark:from-zinc-800/80 dark:to-zinc-800/50">
                            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Template do e-mail de acesso</h2>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                Personalize o e-mail enviado ao cliente após a compra. Use os placeholders; eles serão substituídos pelos dados reais no envio.
                            </p>
                        </div>
                        <div class="p-6 space-y-5">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Logo do e-mail</label>
                                <div class="flex flex-col sm:flex-row gap-4 items-start">
                                    <div
                                        class="flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-zinc-300 bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800/80 w-full sm:w-44 h-32 shrink-0 cursor-pointer transition hover:border-[var(--color-primary)] hover:bg-[var(--color-primary)]/5"
                                        @click="logoInputRef?.click()"
                                    >
                                        <input ref="logoInputRef" type="file" accept="image/*" class="hidden" @change="onLogoFileChange" />
                                        <template v-if="logoUploading">
                                            <Loader2 class="h-8 w-8 text-[var(--color-primary)] animate-spin" />
                                            <span class="mt-2 text-xs text-zinc-500">Enviando...</span>
                                        </template>
                                        <template v-else-if="form.email_template.logo_url">
                                            <img :src="form.email_template.logo_url" alt="Logo" class="max-h-20 w-auto object-contain px-2" @error="($e) => $e.target.style.display = 'none'" />
                                            <span class="mt-2 text-xs text-zinc-500">Clique para trocar</span>
                                        </template>
                                        <template v-else>
                                            <ImageIcon class="h-8 w-8 text-zinc-400 dark:text-zinc-500" />
                                            <span class="mt-2 text-xs text-zinc-500">Clique para enviar</span>
                                        </template>
                                    </div>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400 sm:pt-2">PNG ou JPG, até 2 MB. Exibida no topo do e-mail.</p>
                                </div>
                                <p v-if="logoError" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ logoError }}</p>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nome do remetente (opcional)</label>
                                <input v-model="form.email_template.from_name" type="text" placeholder="Ex: Minha Marca" :class="inputClass" />
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Se vazio, usa o nome das Configurações gerais.</p>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Assunto do e-mail</label>
                                <input v-model="form.email_template.subject" type="text" placeholder="Seu acesso a {nome_produto}" :class="inputClass" />
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Corpo do e-mail (HTML)</label>
                                <textarea v-model="form.email_template.body_html" rows="14" :class="inputClass + ' font-mono text-sm'" placeholder="<p>Olá, {nome_cliente}!</p>..." />
                                <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                                    Placeholders: <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-700">{nome_cliente}</code>,
                                    <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-700">{nome_produto}</code>,
                                    <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-700">{link_acesso}</code>,
                                    <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-700">{email_cliente}</code>,
                                    <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-700">{senha}</code>
                                    (preenchido apenas para área de membros quando uma senha é enviada ao cliente).
                                </p>
                            </div>
                        </div>
                    </section>
                    <!-- Preview do e-mail -->
                    <section class="overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-zinc-800/95 xl:sticky xl:top-6">
                        <div class="border-b border-zinc-200/80 bg-zinc-50/80 px-6 py-4 dark:border-zinc-700/80 dark:bg-zinc-800/50">
                            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Preview do e-mail</h2>
                            <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Como o e-mail será exibido para o cliente.</p>
                        </div>
                        <div class="p-6">
                            <EmailTemplatePreview
                                :logo-url="form.email_template.logo_url"
                                :subject="form.email_template.subject"
                                :body-html="form.email_template.body_html"
                                :from-name="form.email_template.from_name"
                            />
                        </div>
                    </section>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <Button type="submit" :disabled="form.processing">Salvar alterações</Button>
                    <Link
                        href="/produtos"
                        class="inline-flex items-center rounded-xl border border-zinc-200 px-4 py-2.5 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    >
                        Cancelar
                    </Link>
                </div>
            </form>
        </template>

        <!-- Aba Order Bump -->
        <template v-if="currentTab === 'order_bump'">
            <div class="w-full space-y-6">
                <div class="relative overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-zinc-800/95">
                        <div class="border-b border-zinc-200/80 bg-gradient-to-r from-[var(--color-primary)]/10 via-zinc-50/90 to-zinc-100/50 px-6 py-5 dark:from-[var(--color-primary)]/15 dark:via-zinc-800/80 dark:to-zinc-800/50">
                            <div class="relative flex flex-wrap items-center justify-between gap-4">
                                <div>
                                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Order Bump</h2>
                                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                        Ofereça outros produtos no checkout para o cliente comprar junto. Escolha o produto, personalize título, descrição e preço.
                                    </p>
                                </div>
                                <Button type="button" class="inline-flex items-center gap-2 rounded-xl" @click="openNewOrderBump">
                                    <Plus class="h-4 w-4" />
                                    Adicionar order bump
                                </Button>
                            </div>
                        </div>
                    </div>

                    <div v-if="!produto.order_bumps || !produto.order_bumps.length" class="flex flex-col items-center justify-center rounded-2xl border-2 border-dashed border-zinc-200 bg-zinc-50/50 px-6 py-16 text-center dark:border-zinc-600 dark:bg-zinc-800/30">
                        <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-zinc-200/80 text-zinc-400 dark:bg-zinc-700/80 dark:text-zinc-500">
                            <Package class="h-7 w-7" />
                        </span>
                        <p class="mt-4 font-medium text-zinc-700 dark:text-zinc-300">Nenhum order bump</p>
                        <p class="mt-1 max-w-sm text-sm text-zinc-500 dark:text-zinc-400">
                            Adicione produtos que aparecerão no checkout como oferta especial para comprar junto.
                        </p>
                        <Button type="button" class="mt-4 rounded-xl" @click="openNewOrderBump">
                            <Plus class="mr-2 h-4 w-4" />
                            Adicionar order bump
                        </Button>
                    </div>

                    <ul v-else class="space-y-4">
                        <li
                            v-for="bump in produto.order_bumps"
                            :key="bump.id"
                            class="flex flex-col overflow-hidden rounded-xl border border-zinc-200/80 bg-white shadow-sm transition hover:shadow-md dark:border-zinc-600/80 dark:bg-zinc-800/80"
                        >
                            <div class="flex flex-wrap items-start gap-4 p-4 sm:flex-nowrap">
                                <div class="flex h-16 w-16 shrink-0 overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-700">
                                    <img
                                        v-if="bump.target_image_url"
                                        :src="bump.target_image_url"
                                        :alt="bump.target_name"
                                        class="h-full w-full object-cover"
                                    />
                                    <div v-else class="flex h-full w-full items-center justify-center text-zinc-400 dark:text-zinc-500">
                                        <Package class="h-8 w-8" />
                                    </div>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h3 class="font-semibold text-zinc-900 dark:text-white">{{ bump.title }}</h3>
                                    <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Produto: {{ bump.target_name }}</p>
                                    <p v-if="bump.description" class="mt-1 line-clamp-2 text-sm text-zinc-600 dark:text-zinc-300">{{ bump.description }}</p>
                                    <p class="mt-2 text-sm font-medium text-[var(--color-primary)]">
                                        {{ bump.price_override != null ? `R$ ${Number(bump.price_override).toFixed(2)}` : `R$ ${Number(bump.effective_amount_brl).toFixed(2)}` }}
                                        <span class="font-normal text-zinc-500 dark:text-zinc-400"> · CTA: {{ bump.cta_title }}</span>
                                    </p>
                                </div>
                                <div class="flex shrink-0 gap-2">
                                    <Button size="sm" variant="outline" class="h-9 w-9 p-0" @click="openEditOrderBump(bump)">
                                        <Pencil class="h-4 w-4" />
                                    </Button>
                                    <Button size="sm" variant="outline" class="h-9 w-9 p-0 text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20" @click="confirmDestroyOrderBump(bump)">
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                        </li>
                    </ul>
            </div>

            <!-- Modal: Adicionar/Editar Order Bump -->
            <Teleport to="body">
                <Transition
                    enter-active-class="transition duration-200 ease-out"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="transition duration-150 ease-in"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div
                        v-if="showOrderBumpModal"
                        class="fixed inset-0 z-50 flex items-center justify-center p-4"
                        aria-modal="true"
                        role="dialog"
                        @keydown.escape="closeOrderBumpModal"
                    >
                        <div class="absolute inset-0 bg-zinc-900/60 dark:bg-zinc-950/70" aria-hidden="true" @click="closeOrderBumpModal" />
                        <div
                            class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-800"
                            role="document"
                        >
                            <div class="flex items-center justify-between gap-4">
                                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ editingBump ? 'Editar order bump' : 'Adicionar order bump' }}</h3>
                                <button
                                    type="button"
                                    class="rounded-lg p-1.5 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300"
                                    aria-label="Fechar"
                                    @click="closeOrderBumpModal"
                                >
                                    <X class="h-5 w-5" />
                                </button>
                            </div>
                            <form class="mt-5 space-y-4" @submit.prevent="submitOrderBump">
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Produto</label>
                                    <select
                                        v-model="bumpForm.target_product_id"
                                        required
                                        :class="inputClass"
                                        class="w-full"
                                    >
                                        <option value="">Selecione o produto</option>
                                        <option
                                            v-for="p in (produto.available_products_for_bump || [])"
                                            :key="p.id"
                                            :value="p.id"
                                        >
                                            {{ p.name }} — {{ p.currency }} {{ Number(p.price).toFixed(2) }}
                                        </option>
                                    </select>
                                </div>
                                <div v-if="selectedBumpProduct && selectedBumpProduct.offers && selectedBumpProduct.offers.length">
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Oferta (opcional)</label>
                                    <select v-model="bumpForm.target_product_offer_id" :class="inputClass" class="w-full">
                                        <option value="">Preço base do produto</option>
                                        <option v-for="o in selectedBumpProduct.offers" :key="o.id" :value="o.id">
                                            {{ o.name }} — {{ o.currency }} {{ Number(o.price).toFixed(2) }}
                                        </option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Título</label>
                                    <input v-model="bumpForm.title" type="text" required :class="inputClass" placeholder="Ex: Módulo Extra" />
                                    <p v-if="bumpForm.errors.title" class="mt-1 text-sm text-red-600 dark:text-red-400">{{ bumpForm.errors.title }}</p>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Descrição</label>
                                    <textarea v-model="bumpForm.description" rows="3" :class="inputClass" placeholder="Descreva o benefício da oferta" />
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Preço com desconto (opcional)</label>
                                    <input v-model="bumpForm.price_override" type="number" step="0.01" min="0" :class="inputClass" placeholder="Deixe vazio para usar o preço do produto" />
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Se não preencher, será usado o preço do produto ou da oferta selecionada.</p>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Texto do botão / CTA</label>
                                    <input v-model="bumpForm.cta_title" type="text" required :class="inputClass" placeholder="Ex: Sim, quero esta oferta!" />
                                </div>
                                <div class="flex flex-wrap gap-2 pt-2">
                                    <Button type="submit" :disabled="bumpForm.processing">{{ editingBump ? 'Atualizar' : 'Adicionar' }}</Button>
                                    <Button type="button" variant="outline" @click="closeOrderBumpModal">Cancelar</Button>
                                </div>
                            </form>
                        </div>
                    </div>
                </Transition>
            </Teleport>
        </template>

        <!-- Aba Upsell / Downsell -->
        <template v-if="currentTab === 'upsell_downsell'">
            <div class="w-full space-y-6">
                <div class="relative overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-zinc-800/95">
                    <div class="border-b border-zinc-200/80 bg-gradient-to-r from-[var(--color-primary)]/10 via-zinc-50/90 to-zinc-100/50 px-6 py-5 dark:from-[var(--color-primary)]/15 dark:via-zinc-800/80 dark:to-zinc-800/50">
                            <div class="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Upsell / Downsell</h2>
                                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                    Ofertas exibidas após a compra aprovada. Upsell: ofertas extras; Downsell: oferta alternativa se o cliente recusar.
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <Link
                                    :href="`/produtos/${produto.id}/upsell-page/edit`"
                                    class="inline-flex items-center gap-2 rounded-xl border border-zinc-300 bg-white px-4 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                >
                                    <Pencil class="h-4 w-4" />
                                    Editar página upsell
                                </Link>
                                <Link
                                    :href="`/produtos/${produto.id}/downsell-page/edit`"
                                    class="inline-flex items-center gap-2 rounded-xl border border-zinc-300 bg-white px-4 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                >
                                    <Pencil class="h-4 w-4" />
                                    Editar página downsell
                                </Link>
                                <Button type="button" class="rounded-xl" :disabled="savingUpsellDownsell" @click="saveUpsellDownsell">
                                    <Loader2 v-if="savingUpsellDownsell" class="mr-2 h-4 w-4 animate-spin" />
                                    Salvar configuração
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-6 rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                    <!-- Upsell -->
                    <div>
                        <Toggle v-model="upsellDownsellForm.upsell.enabled" label="Ativar upsell (após compra aprovada)" />
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Exibe ofertas extras antes da página de obrigado.</p>
                        <template v-if="upsellDownsellForm.upsell.enabled">
                            <label class="mt-3 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Ofertas de upsell</label>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Títulos, cores e textos são editados em « Editar página upsell ».</p>
                            <div v-for="(item, idx) in upsellDownsellForm.upsell.products" :key="'u-' + idx" class="mt-2 flex flex-wrap items-end gap-2 rounded-lg border border-zinc-200 bg-zinc-50/50 p-3 dark:border-zinc-600 dark:bg-zinc-800/50">
                                <div class="min-w-[140px] flex-1">
                                    <label class="mb-0.5 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Produto</label>
                                    <select v-model="item.product_id" :class="inputClass" class="py-2">
                                        <option :value="null">Selecione</option>
                                        <option v-for="p in (produto.products_for_upsell || [])" :key="p.id" :value="p.id">{{ p.name }}</option>
                                    </select>
                                </div>
                                <div class="min-w-[140px] flex-1">
                                    <label class="mb-0.5 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Oferta (opcional)</label>
                                    <select v-model.number="item.product_offer_id" :class="inputClass" class="py-2">
                                        <option :value="null">Preço base</option>
                                        <template v-for="p in (produto.products_for_upsell || [])" :key="p.id">
                                            <option v-if="p.id === item.product_id" v-for="o in p.offers" :key="o.id" :value="o.id">{{ o.name }} (R$ {{ o.price?.toFixed(2) }})</option>
                                        </template>
                                    </select>
                                </div>
                                <button type="button" class="rounded-lg border border-red-200 px-2 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/30" @click="upsellDownsellForm.upsell.products.splice(idx, 1)">Remover</button>
                            </div>
                            <button type="button" class="mt-2 rounded-xl border-2 border-dashed border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-600 hover:border-[var(--color-primary)] hover:text-[var(--color-primary)] dark:border-zinc-600 dark:text-zinc-400 dark:hover:border-[var(--color-primary)]" @click="upsellDownsellForm.upsell.products.push({ product_id: null, product_offer_id: null, title_override: '', description: '', image_url: '', video_url: '' })">+ Adicionar oferta</button>
                        </template>
                    </div>

                    <!-- Downsell -->
                    <div class="border-t border-zinc-200 pt-6 dark:border-zinc-700">
                        <Toggle
                            v-model="upsellDownsellForm.downsell.enabled"
                            :disabled="!upsellDownsellForm.upsell.enabled"
                            label="Ativar downsell (após recusar upsell)"
                        />
                        <p v-if="!upsellDownsellForm.upsell.enabled" class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                            Ative o upsell acima para habilitar o downsell.
                        </p>
                        <p v-else class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Uma oferta alternativa se o cliente recusar o upsell.</p>
                        <template v-if="upsellDownsellForm.downsell.enabled">
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Títulos, cores e textos são editados em « Editar página downsell ».</p>
                            <div class="mt-3 flex flex-wrap items-end gap-2">
                                <div class="min-w-[140px] flex-1">
                                    <label class="mb-0.5 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Produto</label>
                                    <select v-model="upsellDownsellForm.downsell.product_id" :class="inputClass" class="py-2">
                                        <option :value="null">Selecione</option>
                                        <option v-for="p in (produto.products_for_upsell || [])" :key="p.id" :value="p.id">{{ p.name }}</option>
                                    </select>
                                </div>
                                <div class="min-w-[140px] flex-1">
                                    <label class="mb-0.5 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Oferta (opcional)</label>
                                    <select v-model.number="upsellDownsellForm.downsell.product_offer_id" :class="inputClass" class="py-2">
                                        <option :value="null">Preço base</option>
                                        <template v-for="p in (produto.products_for_upsell || [])" :key="p.id">
                                            <option v-if="p.id === upsellDownsellForm.downsell.product_id" v-for="o in p.offers" :key="o.id" :value="o.id">{{ o.name }} (R$ {{ o.price?.toFixed(2) }})</option>
                                        </template>
                                    </select>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </template>

        <!-- Aba Checkout -->
        <template v-if="currentTab === 'checkout'">
            <div class="w-full space-y-6">
                <!-- Header + ação Criar checkout -->
                <div class="relative overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-zinc-800/95">
                    <div class="border-b border-zinc-200/80 bg-gradient-to-r from-[var(--color-primary)]/10 via-zinc-50/90 to-zinc-100/50 px-6 py-5 dark:from-[var(--color-primary)]/15 dark:via-zinc-800/80 dark:to-zinc-800/50">
                        <div class="relative flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Checkouts</h2>
                                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                    Ofertas e planos usam o checkout principal por padrão. Crie um checkout exclusivo só quando quiser um link direto para uma oferta ou plano.
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <Button
                                    v-if="offerPlanItemsWithoutExclusiveCheckout.length > 0"
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-xl"
                                    @click="showCreateCheckoutModal = true"
                                >
                                    <Plus class="h-4 w-4" />
                                    Criar novo checkout
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Grid de checkouts -->
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    <div
                        v-for="item in checkoutItems"
                        :key="item.id"
                        class="flex flex-col overflow-hidden rounded-xl border border-zinc-200/80 bg-white shadow-sm transition hover:shadow-md dark:border-zinc-600/80 dark:bg-zinc-800/80 dark:hover:border-zinc-500/50"
                    >
                        <div class="flex flex-1 flex-col gap-3 p-4">
                            <div class="flex items-center gap-2">
                                <span
                                    class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium"
                                    :class="item.type === 'main' ? 'bg-[var(--color-primary)]/20 text-[var(--color-primary)]' : item.type === 'offer' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300'"
                                >
                                    {{ item.type === 'main' ? 'Principal' : item.type === 'offer' ? 'Oferta' : 'Plano' }}
                                </span>
                                <span class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ item.type === 'main' ? 'Preço base' : item.label }}</span>
                            </div>
                            <template v-if="item.slug">
                                <p class="truncate font-mono text-xs text-zinc-500 dark:text-zinc-400" :title="checkoutUrl(item.slug)">{{ checkoutUrl(item.slug) }}</p>
                                <div class="mt-auto flex flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium transition"
                                        :class="copiedSlug === item.slug ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-600'"
                                        @click="copyLink(item.slug)"
                                    >
                                        <Check v-if="copiedSlug === item.slug" class="h-3.5 w-3.5" />
                                        <Copy v-else class="h-3.5 w-3.5" />
                                        {{ copiedSlug === item.slug ? 'Copiado' : 'Copiar' }}
                                    </button>
                                    <a
                                        :href="checkoutUrl(item.slug)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-2.5 py-1.5 text-xs font-medium text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-400 dark:hover:bg-zinc-700"
                                    >
                                        Abrir
                                    </a>
                                    <Link
                                        :href="editCheckoutUrl(item)"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-[var(--color-primary)]/50 bg-[var(--color-primary)]/10 px-2.5 py-1.5 text-xs font-medium text-[var(--color-primary)] transition hover:bg-[var(--color-primary)]/20 dark:border-[var(--color-primary)]/50 dark:bg-[var(--color-primary)]/20 dark:hover:bg-[var(--color-primary)]/30"
                                    >
                                        <Pencil class="h-3.5 w-3.5" />
                                        Editar
                                    </Link>
                                    <button
                                        v-if="item.type === 'offer' || item.type === 'plan'"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-red-600 transition hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20"
                                        @click="removeCheckoutSlug(item)"
                                    >
                                        <Trash2 class="h-3.5 w-3.5" />
                                        Excluir
                                    </button>
                                </div>
                            </template>
                            <template v-else>
                                <template v-if="item.type === 'main'">
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Salve o produto para gerar o link do checkout.</p>
                                    <Button type="button" variant="outline" size="sm" class="mt-auto w-fit rounded-lg" @click="ensureCheckoutSlug(item)">
                                        Gerar link
                                    </Button>
                                </template>
                                <template v-else>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Usa o checkout principal.</p>
                                    <p v-if="props.produto.checkout_slug" class="truncate font-mono text-xs text-zinc-400 dark:text-zinc-500" :title="checkoutUrl(props.produto.checkout_slug)">{{ checkoutUrl(props.produto.checkout_slug) }}</p>
                                    <Button type="button" variant="outline" size="sm" class="mt-auto w-fit rounded-lg" @click="ensureCheckoutSlug(item)">
                                        Criar checkout exclusivo
                                    </Button>
                                </template>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal: Criar checkout (vincular a qual oferta/plano) -->
            <Teleport to="body">
                <Transition
                    enter-active-class="transition duration-200 ease-out"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="transition duration-150 ease-in"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div
                        v-if="showCreateCheckoutModal"
                        class="fixed inset-0 z-50 flex items-center justify-center p-4"
                        aria-modal="true"
                        role="dialog"
                        @keydown.escape="showCreateCheckoutModal = false"
                    >
                        <div class="absolute inset-0 bg-zinc-900/60 dark:bg-zinc-950/70" aria-hidden="true" @click="showCreateCheckoutModal = false" />
                        <div
                            class="relative w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-800"
                            role="document"
                        >
                            <div class="flex items-center justify-between gap-4">
                                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">Criar novo checkout</h3>
                                <button
                                    type="button"
                                    class="rounded-lg p-1.5 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300"
                                    aria-label="Fechar"
                                    @click="showCreateCheckoutModal = false"
                                >
                                    <X class="h-5 w-5" />
                                </button>
                            </div>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                Crie um link direto para uma oferta ou plano. Caso contrário, eles usam o checkout principal.
                            </p>
                            <ul class="mt-4 space-y-2">
                                <li
                                    v-for="item in offerPlanItemsWithoutExclusiveCheckout"
                                    :key="item.id"
                                    class="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-zinc-50/50 px-4 py-3 dark:border-zinc-600 dark:bg-zinc-800/50"
                                >
                                    <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ item.type === 'offer' ? `Oferta: ${item.label}` : `Plano: ${item.label}` }}</span>
                                    <Button type="button" size="sm" class="shrink-0 rounded-lg" @click="ensureCheckoutSlug(item); showCreateCheckoutModal = false">
                                        Criar checkout exclusivo
                                    </Button>
                                </li>
                            </ul>
                            <p v-if="offerPlanItemsWithoutExclusiveCheckout.length === 0" class="mt-4 rounded-xl bg-zinc-100 px-4 py-3 text-sm text-zinc-600 dark:bg-zinc-700/50 dark:text-zinc-400">
                                Todas as ofertas e planos já possuem checkout exclusivo ou usam o principal.
                            </p>
                            <div class="mt-5 flex justify-end">
                                <Button variant="outline" class="rounded-xl" @click="showCreateCheckoutModal = false">Fechar</Button>
                            </div>
                        </div>
                    </div>
                </Transition>
            </Teleport>
        </template>

        <!-- Aba Links -->
        <template v-if="currentTab === 'links'">
            <div class="w-full">
                <!-- Header -->
                <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-[var(--color-primary)]/10 via-zinc-50 to-zinc-100/80 px-6 py-8 dark:from-[var(--color-primary)]/15 dark:via-zinc-800/80 dark:to-zinc-900/80">
                    <div class="absolute right-0 top-0 h-32 w-32 translate-x-8 -translate-y-8 rounded-full bg-[var(--color-primary)]/10 dark:bg-[var(--color-primary)]/20" aria-hidden="true" />
                    <div class="relative flex items-start gap-4">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-[var(--color-primary)]/20 text-[var(--color-primary)] dark:bg-[var(--color-primary)]/30">
                            <Link2 class="h-6 w-6" aria-hidden="true" />
                        </span>
                        <div>
                            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Links de checkout</h2>
                            <p class="mt-1 max-w-xl text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                                Compartilhe estes links em campanhas, e-mails ou redes sociais. Cada link leva direto ao checkout correspondente.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Lista de links -->
                <div class="mt-6">
                    <template v-if="allCheckoutLinks.length === 0">
                        <div class="flex flex-col items-center justify-center rounded-2xl border-2 border-dashed border-zinc-200 bg-zinc-50/50 px-6 py-16 text-center dark:border-zinc-600 dark:bg-zinc-800/30">
                            <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-zinc-200/80 text-zinc-400 dark:bg-zinc-700/80 dark:text-zinc-500">
                                <Link2 class="h-7 w-7" />
                            </span>
                            <p class="mt-4 font-medium text-zinc-700 dark:text-zinc-300">Nenhum link disponível</p>
                            <p class="mt-1 max-w-sm text-sm text-zinc-500 dark:text-zinc-400">
                                Salve o produto na aba Geral para gerar o link. Ofertas e planos configurados aparecerão aqui.
                            </p>
                        </div>
                    </template>
                    <ul v-else class="space-y-3">
                        <li
                            v-for="(item, index) in allCheckoutLinks"
                            :key="item.id"
                            class="group relative flex flex-col overflow-hidden rounded-xl border border-zinc-200/80 bg-white shadow-sm transition hover:shadow-md dark:border-zinc-600/80 dark:bg-zinc-800/80 dark:hover:border-zinc-500/50"
                        >
                            <div class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span
                                            class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium"
                                            :class="item.id === 'main' ? 'bg-[var(--color-primary)]/20 text-[var(--color-primary)] dark:bg-[var(--color-primary)]/30' : 'bg-zinc-200/80 text-zinc-600 dark:bg-zinc-600/80 dark:text-zinc-400'"
                                        >
                                            {{ item.id === 'main' ? 'Principal' : item.id.startsWith('offer') ? 'Oferta' : 'Plano' }}
                                        </span>
                                        <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ item.id === 'main' ? 'Preço base' : item.label }}</span>
                                    </div>
                                    <p class="mt-1.5 truncate font-mono text-xs text-zinc-500 dark:text-zinc-400" :title="getCheckoutLinkUrl(item)">
                                        {{ getCheckoutLinkUrl(item) }}
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <a
                                        :href="getCheckoutLinkUrl(item)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-2 text-xs font-medium text-zinc-600 transition hover:bg-zinc-50 hover:text-zinc-900 dark:border-zinc-600 dark:text-zinc-400 dark:hover:bg-zinc-700 dark:hover:text-white"
                                    >
                                        Abrir
                                    </a>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-medium transition"
                                        :class="copiedSlug === item.id ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-[var(--color-primary)]/10 text-[var(--color-primary)] hover:bg-[var(--color-primary)]/20 dark:bg-[var(--color-primary)]/20 dark:hover:bg-[var(--color-primary)]/30'"
                                        @click="copyLinkForItem(item)"
                                    >
                                        <Check v-if="copiedSlug === item.id" class="h-3.5 w-3.5" />
                                        <Copy v-else class="h-3.5 w-3.5" />
                                        {{ copiedSlug === item.id ? 'Copiado!' : 'Copiar' }}
                                    </button>
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </template>

        <!-- Aba Co-produção -->
        <template v-if="currentTab === 'coproducao'">
            <div class="rounded-xl border border-zinc-200 bg-white p-8 dark:border-zinc-700 dark:bg-zinc-800">
                <Handshake class="mx-auto h-12 w-12 text-zinc-400 dark:text-zinc-500" />
                <p class="mt-3 text-center font-medium text-zinc-600 dark:text-zinc-400">Co-produção</p>
                <p class="mt-1 text-center text-sm text-zinc-500 dark:text-zinc-500">Esta funcionalidade será implementada em breve.</p>
            </div>
        </template>

        <!-- Aba Afiliados -->
        <template v-if="currentTab === 'afiliados'">
            <div class="rounded-xl border border-zinc-200 bg-white p-8 dark:border-zinc-700 dark:bg-zinc-800">
                <Users class="mx-auto h-12 w-12 text-zinc-400 dark:text-zinc-500" />
                <p class="mt-3 text-center font-medium text-zinc-600 dark:text-zinc-400">Afiliados</p>
                <p class="mt-1 text-center text-sm text-zinc-500 dark:text-zinc-500">Esta funcionalidade será implementada em breve.</p>
            </div>
        </template>
        </div>
    </div>
</template>
