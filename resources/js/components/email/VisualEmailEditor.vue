<script setup>
import { nextTick, onMounted, reactive, ref, watch } from 'vue';
import {
    AlignCenter,
    AlignLeft,
    AlignRight,
    Bold,
    Code2,
    Italic,
    Link,
    List,
    ListOrdered,
    Pilcrow,
    Redo2,
    RemoveFormatting,
    Underline,
    Undo2,
    Unlink,
} from 'lucide-vue-next';

const props = defineProps({
    modelValue: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    placeholders: { type: Array, default: () => [] },
    minHeight: { type: String, default: '320px' },
    required: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const editor = ref(null);
const sourceMode = ref(false);
const previewMode = ref('desktop');
const savedRange = ref(null);
const undoStack = ref([]);
const redoStack = ref([]);
const restoringHistory = ref(false);
const selectedPlaceholder = ref('');
const selectedButton = ref(null);
const selectedElement = ref(null);
const selectedType = ref('');
const buttonColor = ref('#0ea5e9');
const textColor = ref('#334155');
const backgroundColor = ref('#f8fafc');
const contentBackgroundColor = ref('#ffffff');
const typographySettings = reactive({
    fontFamily: 'Arial, sans-serif',
    fontSize: 15,
    lineHeight: 1.6,
    color: '#334155',
});
const buttonSettings = reactive({
    text: '',
    url: '',
    backgroundColor: '#0ea5e9',
    textColor: '#ffffff',
    borderRadius: 8,
    alignment: 'center',
    fullWidth: false,
});
const linkSettings = reactive({ text: '', url: '', color: '#0284c7' });
const imageSettings = reactive({
    url: '',
    alt: '',
    link: '',
    width: 600,
    borderRadius: 0,
    alignment: 'center',
});
const blockLibrary = [
    { type: 'hero', label: 'Hero' },
    { type: 'title', label: 'Título' },
    { type: 'text', label: 'Texto' },
    { type: 'button', label: 'Botão' },
    { type: 'image', label: 'Imagem' },
    { type: 'content', label: 'Seção' },
    { type: 'columns', label: '2 colunas' },
    { type: 'product', label: 'Produto' },
    { type: 'coupon', label: 'Cupom' },
    { type: 'benefits', label: 'Benefícios' },
    { type: 'testimonial', label: 'Depoimento' },
    { type: 'social', label: 'Social' },
    { type: 'divider', label: 'Divisor' },
    { type: 'spacer', label: 'Espaço' },
    { type: 'footer', label: 'Rodapé' },
];

function syncEditor() {
    if (!editor.value || sourceMode.value || editor.value.innerHTML === props.modelValue) return;
    editor.value.innerHTML = props.modelValue || '';
    normalizeLegacyElements();
    syncGeneralColors();
    selectedButton.value = null;
    clearSelection();
}

function normalizeLegacyElements() {
    if (!editor.value) return;
    editor.value.querySelectorAll('a').forEach((link) => {
        const style = link.style;
        const looksLikeButton = style.backgroundColor || style.padding || style.display === 'inline-block' || style.borderRadius;
        if (looksLikeButton) {
            link.dataset.emailButton = 'true';
            const container = link.parentElement;
            if (container) container.dataset.emailButtonContainer = 'true';
        } else if (!link.dataset.emailLink) {
            link.dataset.emailLink = 'true';
        }
    });
}

function emitVisualValue() {
    if (!editor.value) return;
    const value = editor.value.innerHTML === '<br>' ? '' : editor.value.innerHTML;
    emit('update:modelValue', value);
}

function recordHistory() {
    if (!editor.value || restoringHistory.value) return;
    const snapshot = editor.value.innerHTML;
    if (undoStack.value.at(-1) !== snapshot) {
        undoStack.value.push(snapshot);
        if (undoStack.value.length > 60) undoStack.value.shift();
    }
    redoStack.value = [];
}

function restoreHistorySnapshot(snapshot) {
    if (!editor.value) return;
    restoringHistory.value = true;
    editor.value.innerHTML = snapshot;
    normalizeLegacyElements();
    syncGeneralColors();
    clearSelection();
    emitVisualValue();
    restoringHistory.value = false;
}

function undoEditor() {
    if (!editor.value) return;
    const snapshot = undoStack.value.pop();
    if (snapshot === undefined) {
        run('undo');
        return;
    }
    redoStack.value.push(editor.value.innerHTML);
    restoreHistorySnapshot(snapshot);
}

function redoEditor() {
    if (!editor.value) return;
    const snapshot = redoStack.value.pop();
    if (snapshot === undefined) {
        run('redo');
        return;
    }
    undoStack.value.push(editor.value.innerHTML);
    restoreHistorySnapshot(snapshot);
}

function rememberSelection() {
    if (!editor.value) return;
    const selection = window.getSelection();
    if (!selection?.rangeCount) return;
    const range = selection.getRangeAt(0);
    if (editor.value.contains(range.commonAncestorContainer)) {
        savedRange.value = range.cloneRange();
    }
}

function restoreSelection() {
    if (!editor.value) return;
    const selection = window.getSelection();
    if (!selection) return;
    let range = savedRange.value;
    if (!range || !editor.value.contains(range.commonAncestorContainer)) {
        range = document.createRange();
        range.selectNodeContents(editor.value);
        range.collapse(false);
    }
    selection.removeAllRanges();
    selection.addRange(range);
}

function run(command, value = null) {
    if (props.disabled || sourceMode.value) return;
    if (command !== 'undo' && command !== 'redo') recordHistory();
    editor.value?.focus();
    restoreSelection();
    document.execCommand(command, false, value);
    rememberSelection();
    emitVisualValue();
}

function setBlock(tag) {
    if (props.disabled || sourceMode.value || !editor.value) return;
    recordHistory();
    restoreSelection();

    const selection = window.getSelection();
    if (!selection?.rangeCount) return;
    const range = selection.getRangeAt(0);
    const selector = 'p, h1, h2, h3';
    const candidates = Array.from(editor.value.querySelectorAll(selector)).filter((element) => {
        try {
            return range.intersectsNode(element);
        } catch (_) {
            return false;
        }
    });

    let blocks = candidates.filter((element) => !candidates.some((other) => other !== element && other.contains(element)));
    if (!blocks.length) {
        const container = range.commonAncestorContainer.nodeType === Node.ELEMENT_NODE
            ? range.commonAncestorContainer
            : range.commonAncestorContainer.parentElement;
        const current = container?.closest?.(selector);
        if (current && editor.value.contains(current)) blocks = [current];
    }

    if (!blocks.length) {
        document.execCommand('formatBlock', false, `<${tag}>`);
        rememberSelection();
        emitVisualValue();
        return;
    }

    let lastReplacement = null;
    blocks.forEach((block) => {
        if (block.tagName.toLowerCase() === tag) return;
        const replacement = document.createElement(tag);
        Array.from(block.attributes).forEach((attribute) => replacement.setAttribute(attribute.name, attribute.value));
        replacement.innerHTML = block.innerHTML;
        replacement.style.removeProperty('font-size');
        replacement.style.removeProperty('font-weight');
        replacement.style.removeProperty('line-height');
        block.replaceWith(replacement);
        lastReplacement = replacement;
    });

    if (lastReplacement) {
        const nextRange = document.createRange();
        nextRange.selectNodeContents(lastReplacement);
        selection.removeAllRanges();
        selection.addRange(nextRange);
        savedRange.value = nextRange.cloneRange();
    }
    emitVisualValue();
}

function createLink() {
    if (props.disabled || sourceMode.value) return;
    const id = createEditorId('link');
    run('insertHTML', `<a data-email-link="true" data-editor-id="${id}" href="#" style="color:#0284c7;text-decoration:underline;">Editar link</a>`);
    selectInsertedElement(id, 'link');
}

function insertButton() {
    const id = createEditorId('button');
    run('insertHTML', `<div data-email-block="true" data-email-block-type="button"><p data-email-button-container="true" style="margin:24px 0;text-align:center;"><a data-email-button="true" data-editor-id="${id}" href="#" style="display:inline-block;padding:14px 28px;background-color:${buttonColor.value};color:#ffffff;text-decoration:none;font-weight:700;border-radius:8px;">Clique aqui</a></p></div>`);
    selectInsertedElement(id, 'button');
}

function insertImage() {
    const id = createEditorId('image');
    run('insertHTML', `<div data-email-block="true" data-email-block-type="image"><div data-email-image-block="true" data-editor-id="${id}" style="margin:20px 0;text-align:center;"><div data-email-image-placeholder="true" style="padding:40px 20px;border:2px dashed #cbd5e1;border-radius:8px;color:#64748b;background:#f8fafc;">Informe a URL da imagem no painel de propriedades</div><img data-email-image="true" src="" alt="" style="display:none;max-width:100%;height:auto;margin:0 auto;border:0;border-radius:0;" /></div></div>`);
    selectInsertedElement(id, 'image');
}

function createEditorId(prefix) {
    return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
}

async function selectInsertedElement(id, type) {
    await nextTick();
    const element = editor.value?.querySelector(`[data-editor-id="${id}"]`);
    if (element) selectElement(element, type);
}

function wrapPreset(type, html) {
    const id = createEditorId(type);
    return `<div data-email-block="true" data-email-block-type="${type}" data-editor-id="${id}" style="position:relative;">${html}</div>`;
}

function insertPreset(value) {
    const preset = typeof value === 'string' ? value : value.target.value;
    if (typeof value !== 'string') value.target.value = '';
    if (!preset || props.disabled) return;

    if (preset === 'button') return insertButton();
    if (preset === 'image') return insertImage();
    if (preset === 'title') return run('insertHTML', wrapPreset('title', '<h2 style="margin:0 0 16px;font-size:26px;line-height:1.25;color:#0f172a;">Título do e-mail</h2>'));
    if (preset === 'text') return run('insertHTML', wrapPreset('text', '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">Digite aqui o conteúdo da sua mensagem.</p>'));
    if (preset === 'content') {
        return run('insertHTML', wrapPreset('content', '<div style="margin:20px 0;padding:24px;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;"><h2 style="margin:0 0 12px;font-size:22px;line-height:1.3;color:#0f172a;">Título da seção</h2><p style="margin:0;font-size:15px;line-height:1.6;color:#334155;">Adicione aqui o conteúdo da sua mensagem.</p></div>'));
    }
    if (preset === 'hero') return run('insertHTML', wrapPreset('hero', `<div style="padding:40px 28px;text-align:center;background:#eff6ff;border-radius:16px;"><p style="margin:0 0 8px;font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#0284c7;">Novidade</p><h1 style="margin:0 0 16px;font-size:32px;line-height:1.2;color:#0f172a;">Uma mensagem que chama atenção</h1><p style="margin:0 auto 24px;max-width:480px;font-size:16px;line-height:1.6;color:#475569;">Explique o principal benefício da sua oferta de forma clara.</p><p data-email-button-container="true" style="margin:0;text-align:center;"><a data-email-button="true" href="#" style="display:inline-block;padding:14px 28px;background-color:${buttonColor.value};color:#ffffff;text-decoration:none;font-weight:700;border-radius:8px;">Conhecer oferta</a></p></div>`));
    if (preset === 'columns') return run('insertHTML', wrapPreset('columns', '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td width="50%" valign="top" style="padding:12px;"><h3 style="margin:0 0 8px;color:#0f172a;">Primeira coluna</h3><p style="margin:0;color:#475569;line-height:1.6;">Adicione seu conteúdo.</p></td><td width="50%" valign="top" style="padding:12px;"><h3 style="margin:0 0 8px;color:#0f172a;">Segunda coluna</h3><p style="margin:0;color:#475569;line-height:1.6;">Adicione seu conteúdo.</p></td></tr></table>'));
    if (preset === 'product') return run('insertHTML', wrapPreset('product', `<div style="padding:24px;text-align:center;border:1px solid #e2e8f0;border-radius:12px;background:#ffffff;"><h2 style="margin:0 0 8px;color:#0f172a;">Nome do produto</h2><p style="margin:0 0 8px;color:#64748b;">Descrição curta do produto</p><p style="margin:0 0 20px;font-size:24px;font-weight:700;color:#0f172a;">R$ 00,00</p><p data-email-button-container="true" style="margin:0;text-align:center;"><a data-email-button="true" href="#" style="display:inline-block;padding:14px 28px;background-color:${buttonColor.value};color:#ffffff;text-decoration:none;font-weight:700;border-radius:8px;">Comprar agora</a></p></div>`));
    if (preset === 'coupon') return run('insertHTML', wrapPreset('coupon', '<div style="margin:20px 0;padding:24px;text-align:center;background:#fffbeb;border:2px dashed #f59e0b;border-radius:12px;"><p style="margin:0 0 8px;color:#92400e;">Use o cupom</p><p style="margin:0;font-size:28px;font-weight:800;letter-spacing:.12em;color:#78350f;">DESCONTO10</p></div>'));
    if (preset === 'benefits') return run('insertHTML', wrapPreset('benefits', '<div style="margin:20px 0;padding:24px;background:#f8fafc;border-radius:12px;"><h2 style="margin:0 0 16px;color:#0f172a;">Você vai receber</h2><p style="margin:0 0 10px;color:#334155;">✓ Primeiro benefício importante</p><p style="margin:0 0 10px;color:#334155;">✓ Segundo benefício importante</p><p style="margin:0;color:#334155;">✓ Terceiro benefício importante</p></div>'));
    if (preset === 'testimonial') return run('insertHTML', wrapPreset('testimonial', '<blockquote style="margin:20px 0;padding:24px;border-left:4px solid #0ea5e9;background:#f8fafc;color:#334155;font-size:16px;line-height:1.6;"><p style="margin:0 0 12px;">“Escreva aqui um depoimento de cliente.”</p><footer style="font-size:13px;font-weight:700;color:#64748b;">— Nome do cliente</footer></blockquote>'));
    if (preset === 'social') return run('insertHTML', wrapPreset('social', '<p style="margin:24px 0;text-align:center;"><a data-email-link="true" href="#" style="margin:0 8px;color:#0284c7;">Instagram</a><a data-email-link="true" href="#" style="margin:0 8px;color:#0284c7;">YouTube</a><a data-email-link="true" href="#" style="margin:0 8px;color:#0284c7;">Site</a></p>'));
    if (preset === 'footer') return run('insertHTML', wrapPreset('footer', '<div style="padding:24px;text-align:center;background:#f1f5f9;color:#64748b;font-size:12px;line-height:1.6;"><p style="margin:0 0 8px;">Você recebeu este e-mail porque possui relacionamento com nossa empresa.</p><p style="margin:0;">© Sua marca</p></div>'));
    if (preset === 'divider') return run('insertHTML', wrapPreset('divider', '<hr style="margin:24px 0;border:0;border-top:1px solid #e2e8f0;" />'));
    if (preset === 'spacer') return run('insertHTML', wrapPreset('spacer', '<div data-email-spacer="true" style="height:32px;line-height:32px;font-size:1px;">&nbsp;</div>'));
}

function selectEditableElement(event) {
    const clickedLink = event.target.closest?.('a');
    const button = clickedLink && (clickedLink.dataset.emailButton === 'true' || clickedLink.style.backgroundColor || clickedLink.style.padding)
        ? clickedLink
        : null;
    if (button) return selectElement(button, 'button');
    const image = event.target.closest?.('[data-email-image-block="true"], img');
    if (image) return selectElement(image, 'image');
    const link = clickedLink;
    if (link) return selectElement(link, 'link');
    const block = event.target.closest?.('[data-email-block="true"]');
    if (block) return selectElement(block, 'block');
    clearSelection();
}

function colorToHex(value, fallback) {
    if (!value) return fallback;
    if (value.startsWith('#')) return value.length === 4
        ? `#${value[1]}${value[1]}${value[2]}${value[2]}${value[3]}${value[3]}`
        : value.slice(0, 7);
    const rgb = value.match(/\d+/g)?.map(Number);
    return rgb?.length >= 3
        ? `#${rgb.slice(0, 3).map((part) => part.toString(16).padStart(2, '0')).join('')}`
        : fallback;
}

function selectElement(element, type) {
    selectedElement.value = element;
    selectedType.value = type;

    if (type === 'button') {
        selectedButton.value = element;
        const container = element.closest('[data-email-button-container="true"]') || element.parentElement;
        element.dataset.emailButton = 'true';
        if (container) container.dataset.emailButtonContainer = 'true';
        Object.assign(buttonSettings, {
            text: element.textContent || '',
            url: element.getAttribute('href') || '',
            backgroundColor: colorToHex(element.style.backgroundColor, '#0ea5e9'),
            textColor: colorToHex(element.style.color, '#ffffff'),
            borderRadius: parseInt(element.style.borderRadius, 10) || 0,
            alignment: container?.style.textAlign || 'center',
            fullWidth: element.style.width === '100%',
        });
        buttonColor.value = buttonSettings.backgroundColor;
    } else if (type === 'link') {
        Object.assign(linkSettings, {
            text: element.textContent || '',
            url: element.getAttribute('href') || '',
            color: colorToHex(element.style.color, '#0284c7'),
        });
    } else if (type === 'image') {
        const image = element.matches?.('img') ? element : element.querySelector('[data-email-image="true"], img');
        const linkedImage = image?.closest('a');
        image?.setAttribute('data-email-image', 'true');
        Object.assign(imageSettings, {
            url: image?.getAttribute('src') || '',
            alt: image?.getAttribute('alt') || '',
            link: linkedImage?.getAttribute('href') || '',
            width: parseInt(image?.style.width, 10) || 600,
            borderRadius: parseInt(image?.style.borderRadius, 10) || 0,
            alignment: (element.matches?.('img') ? element.parentElement : element)?.style.textAlign || 'center',
        });
    }
}

function clearSelection() {
    selectedElement.value = null;
    selectedButton.value = null;
    selectedType.value = '';
}

function updateButtonColor(event) {
    recordHistory();
    buttonColor.value = event.target.value;
    if (!selectedButton.value) return;
    selectedButton.value.style.backgroundColor = buttonColor.value;
    buttonSettings.backgroundColor = buttonColor.value;
    emitVisualValue();
}

function updateSelectedButton() {
    const button = selectedElement.value;
    if (!button || selectedType.value !== 'button') return;
    recordHistory();
    const container = button.closest('[data-email-button-container="true"]') || button.parentElement;
    button.textContent = buttonSettings.text || 'Botão';
    button.setAttribute('href', buttonSettings.url || '#');
    button.style.backgroundColor = buttonSettings.backgroundColor;
    button.style.color = buttonSettings.textColor;
    button.style.borderRadius = `${buttonSettings.borderRadius}px`;
    button.style.display = buttonSettings.fullWidth ? 'block' : 'inline-block';
    button.style.width = buttonSettings.fullWidth ? '100%' : 'auto';
    button.style.boxSizing = 'border-box';
    if (container) container.style.textAlign = buttonSettings.alignment;
    buttonColor.value = buttonSettings.backgroundColor;
    emitVisualValue();
}

function updateSelectedLink() {
    const link = selectedElement.value;
    if (!link || selectedType.value !== 'link') return;
    recordHistory();
    link.textContent = linkSettings.text || 'Link';
    link.setAttribute('href', linkSettings.url || '#');
    link.style.color = linkSettings.color;
    emitVisualValue();
}

function updateSelectedImage() {
    const selected = selectedElement.value;
    if (!selected || selectedType.value !== 'image') return;
    recordHistory();
    const image = selected.matches?.('img') ? selected : selected.querySelector('[data-email-image="true"], img');
    const block = selected.matches?.('img') ? selected.parentElement : selected;
    const placeholder = block?.querySelector?.('[data-email-image-placeholder="true"]');
    if (!image || !block) return;
    let anchor = image?.closest('a');

    image.setAttribute('src', imageSettings.url || '');
    image.setAttribute('alt', imageSettings.alt || '');
    image.style.display = imageSettings.url ? 'block' : 'none';
    image.style.width = `${Math.max(40, Number(imageSettings.width) || 600)}px`;
    image.style.maxWidth = '100%';
    image.style.borderRadius = `${imageSettings.borderRadius}px`;
    block.style.textAlign = imageSettings.alignment;
    image.style.margin = imageSettings.alignment === 'left' ? '0 auto 0 0' : imageSettings.alignment === 'right' ? '0 0 0 auto' : '0 auto';
    if (placeholder) placeholder.style.display = imageSettings.url ? 'none' : 'block';

    if (imageSettings.link && !anchor) {
        anchor = document.createElement('a');
        image.replaceWith(anchor);
        anchor.appendChild(image);
    }
    if (anchor) {
        if (imageSettings.link) anchor.setAttribute('href', imageSettings.link);
        else anchor.replaceWith(image);
    }
    emitVisualValue();
}

function removeSelectedElement() {
    if (!selectedElement.value) return;
    recordHistory();
    const target = selectedType.value === 'button'
        ? (selectedElement.value.closest('[data-email-button-container="true"]') || selectedElement.value)
        : selectedType.value === 'image'
            ? (selectedElement.value.closest?.('[data-email-block-type="image"]') || selectedElement.value)
            : selectedElement.value;
    target.remove();
    clearSelection();
    emitVisualValue();
}

function selectedBlockRoot() {
    if (!selectedElement.value) return null;
    if (selectedType.value === 'button') {
        return selectedElement.value.closest('[data-email-block="true"], [data-email-button-container="true"]') || selectedElement.value;
    }
    return selectedElement.value.closest?.('[data-email-block="true"]') || selectedElement.value;
}

function duplicateSelectedElement() {
    const target = selectedBlockRoot();
    if (!target) return;
    recordHistory();
    const clone = target.cloneNode(true);
    clone.querySelectorAll?.('[data-editor-id]').forEach((item) => item.removeAttribute('data-editor-id'));
    clone.removeAttribute?.('data-editor-id');
    target.after(clone);
    selectElement(clone, clone.dataset.emailBlock ? 'block' : selectedType.value);
    emitVisualValue();
}

function moveSelectedElement(direction) {
    const target = selectedBlockRoot();
    if (!target) return;
    recordHistory();
    if (direction === 'up' && target.previousElementSibling) {
        target.parentElement.insertBefore(target, target.previousElementSibling);
    } else if (direction === 'down' && target.nextElementSibling) {
        target.parentElement.insertBefore(target.nextElementSibling, target);
    }
    emitVisualValue();
}

function blockLabel(type) {
    const labels = {
        title: 'Título', text: 'Texto', content: 'Seção', hero: 'Hero', columns: 'Duas colunas',
        product: 'Produto', coupon: 'Cupom', benefits: 'Benefícios', testimonial: 'Depoimento',
        social: 'Redes sociais', footer: 'Rodapé', divider: 'Divisor', spacer: 'Espaçamento',
    };
    return labels[type] || 'Bloco';
}

function updateTextColor(event) {
    textColor.value = event.target.value;
    run('foreColor', textColor.value);
}

function updateBackgroundColor(event) {
    backgroundColor.value = event.target.value;
    if (!editor.value) return;
    recordHistory();

    let wrapper = editor.value.querySelector(':scope > [data-email-background="true"]');
    if (!wrapper) {
        const currentHtml = editor.value.innerHTML || '<p><br></p>';
        editor.value.innerHTML = `<div data-email-background="true" style="background-color:${backgroundColor.value};padding:24px;">${currentHtml}</div>`;
        wrapper = editor.value.firstElementChild;
    }
    wrapper.style.backgroundColor = backgroundColor.value;
    emitVisualValue();
}

function findExternalSurface() {
    if (!editor.value) return null;
    return editor.value.querySelector(':scope > [data-email-background="true"], :scope > table');
}

function updateContentBackgroundColor(event) {
    contentBackgroundColor.value = event.target.value;
    recordHistory();
    const content = findContentSurface() || ensureEmailWrappers().content;
    if (!content) return;
    content.style.backgroundColor = contentBackgroundColor.value;
    emitVisualValue();
}

function updateTypography() {
    recordHistory();
    const content = findContentSurface() || ensureEmailWrappers().content;
    if (!content) return;

    Object.assign(content.style, {
        fontFamily: typographySettings.fontFamily,
        fontSize: `${typographySettings.fontSize}px`,
        lineHeight: String(typographySettings.lineHeight),
        color: typographySettings.color,
    });

    content.querySelectorAll('p, li, td, span, blockquote, footer').forEach((element) => {
        element.style.fontFamily = typographySettings.fontFamily;
        element.style.fontSize = `${typographySettings.fontSize}px`;
        element.style.lineHeight = String(typographySettings.lineHeight);
        element.style.color = typographySettings.color;
    });
    content.querySelectorAll('h1, h2, h3, h4').forEach((element) => {
        element.style.fontFamily = typographySettings.fontFamily;
        element.style.lineHeight = String(typographySettings.lineHeight);
        element.style.color = typographySettings.color;
    });

    emitVisualValue();
}

function findContentSurface() {
    if (!editor.value) return null;

    const explicitContent = editor.value.querySelector(':scope > [data-email-background="true"] > [data-email-content="true"]');
    if (explicitContent) return explicitContent;

    const rootTable = editor.value.querySelector(
        ':scope > table, :scope > [data-email-background="true"] > table, :scope > [data-email-background="true"] > [data-email-content="true"] > table',
    );
    if (rootTable) {
        return rootTable.querySelector(':scope > tbody > tr > td, :scope > tr > td');
    }

    return null;
}

function ensureEmailWrappers() {
    if (!editor.value) return {};

    let background = editor.value.querySelector(':scope > [data-email-background="true"]');
    if (!background) {
        const currentHtml = editor.value.innerHTML || '<p><br></p>';
        editor.value.innerHTML = `<div data-email-background="true" style="background-color:${backgroundColor.value};padding:24px;"><div data-email-content="true" style="background-color:${contentBackgroundColor.value};padding:24px;">${currentHtml}</div></div>`;
        background = editor.value.firstElementChild;
    }

    let content = background.querySelector(':scope > [data-email-content="true"]');
    if (!content) {
        const currentHtml = background.innerHTML || '<p><br></p>';
        background.innerHTML = `<div data-email-content="true" style="background-color:${contentBackgroundColor.value};padding:24px;">${currentHtml}</div>`;
        content = background.firstElementChild;
    }

    return { background, content };
}

function syncGeneralColors() {
    if (!editor.value) return;
    const background = findExternalSurface();
    const content = findContentSurface();
    backgroundColor.value = colorToHex(background?.style.backgroundColor, '#f8fafc');
    contentBackgroundColor.value = colorToHex(content?.style.backgroundColor, '#ffffff');
    typographySettings.fontFamily = content?.style.fontFamily || 'Arial, sans-serif';
    typographySettings.fontSize = parseFloat(content?.style.fontSize) || 15;
    typographySettings.lineHeight = parseFloat(content?.style.lineHeight) || 1.6;
    typographySettings.color = colorToHex(content?.style.color, '#334155');
}

function insertPlaceholder(value = selectedPlaceholder.value) {
    const placeholder = typeof value === 'string' ? value : selectedPlaceholder.value;
    if (!placeholder || props.disabled) return;
    if (!sourceMode.value) recordHistory();
    if (sourceMode.value) {
        emit('update:modelValue', `${props.modelValue || ''}${placeholder}`);
    } else {
        editor.value?.focus();
        restoreSelection();

        const selection = window.getSelection();
        if (selection?.rangeCount) {
            const range = selection.getRangeAt(0);
            range.deleteContents();

            const textNode = document.createTextNode(placeholder);
            range.insertNode(textNode);
            range.setStartAfter(textNode);
            range.collapse(true);

            selection.removeAllRanges();
            selection.addRange(range);
            savedRange.value = range.cloneRange();
            emitVisualValue();
        }
    }
    selectedPlaceholder.value = '';
}

async function toggleSourceMode() {
    sourceMode.value = !sourceMode.value;
    await nextTick();
    if (!sourceMode.value) syncEditor();
}

watch(() => props.modelValue, syncEditor);
onMounted(syncEditor);

const toolButtonClass = 'inline-flex h-8 w-8 items-center justify-center rounded-md text-zinc-600 transition hover:bg-zinc-200 hover:text-zinc-900 disabled:cursor-not-allowed disabled:opacity-40 dark:text-zinc-300 dark:hover:bg-zinc-700 dark:hover:text-white';
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-zinc-300 bg-white dark:border-zinc-600 dark:bg-zinc-900" :class="{ 'opacity-60': disabled }">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900">
            <div>
                <p class="text-sm font-semibold text-zinc-900 dark:text-white">Editor de e-mail</p>
                <p class="text-xs text-zinc-500">Selecione um bloco para editar suas propriedades.</p>
            </div>
            <div class="flex items-center gap-2">
                <div class="flex rounded-lg border border-zinc-300 p-0.5 dark:border-zinc-600">
                    <button type="button" class="rounded-md px-2.5 py-1.5 text-xs" :class="previewMode === 'desktop' ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-500'" @click="previewMode = 'desktop'">Desktop</button>
                    <button type="button" class="rounded-md px-2.5 py-1.5 text-xs" :class="previewMode === 'mobile' ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-500'" @click="previewMode = 'mobile'">Mobile</button>
                </div>
                <select v-if="placeholders.length" v-model="selectedPlaceholder" class="h-9 rounded-lg border border-zinc-300 bg-white px-2 text-xs dark:border-zinc-600 dark:bg-zinc-800" :disabled="disabled" @change="insertPlaceholder($event.target.value)">
                    <option value="">Inserir variável...</option>
                    <option v-for="item in placeholders" :key="item.value || item" :value="item.value || item">{{ item.label || item.value || item }}</option>
                </select>
                <button v-if="placeholders.length" type="button" :class="toolButtonClass" :disabled="disabled || !selectedPlaceholder" title="Inserir variável" @click="insertPlaceholder()"><Pilcrow class="h-4 w-4" /></button>
                <button type="button" class="inline-flex h-9 items-center gap-2 rounded-lg border border-zinc-300 px-3 text-xs font-medium dark:border-zinc-600" :class="{ 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900': sourceMode }" :disabled="disabled" @click="toggleSourceMode">
                    <Code2 class="h-4 w-4" /> {{ sourceMode ? 'Visual' : 'HTML' }}
                </button>
            </div>
        </div>

        <div class="grid min-h-[520px] xl:grid-cols-[170px_minmax(360px,1fr)_270px]">
            <aside class="border-b border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800/60 xl:border-b-0 xl:border-r">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Blocos</p>
                <div class="grid grid-cols-3 gap-2 sm:grid-cols-5 xl:grid-cols-2">
                    <button v-for="block in blockLibrary" :key="block.type" type="button" class="rounded-lg border border-zinc-200 bg-white px-2 py-2.5 text-xs font-medium text-zinc-700 shadow-sm transition hover:border-[var(--color-primary)] hover:text-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-200" :disabled="disabled || sourceMode" @click="insertPreset(block.type)">
                        {{ block.label }}
                    </button>
                </div>
            </aside>

            <main class="min-w-0 bg-zinc-100 dark:bg-zinc-950/50">
                <div v-if="!sourceMode" class="flex flex-wrap items-center gap-1 border-b border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900">
                    <button type="button" :class="toolButtonClass" title="Desfazer" @click="undoEditor"><Undo2 class="h-4 w-4" /></button>
                    <button type="button" :class="toolButtonClass" title="Refazer" @click="redoEditor"><Redo2 class="h-4 w-4" /></button>
                    <select class="h-8 rounded-md border border-zinc-300 bg-white px-2 text-xs dark:border-zinc-600 dark:bg-zinc-800" @change="setBlock($event.target.value); $event.target.value = 'p'">
                        <option value="p">Parágrafo</option><option value="h1">Título 1</option><option value="h2">Título 2</option><option value="h3">Título 3</option>
                    </select>
                    <button type="button" :class="toolButtonClass" title="Negrito" @click="run('bold')"><Bold class="h-4 w-4" /></button>
                    <button type="button" :class="toolButtonClass" title="Itálico" @click="run('italic')"><Italic class="h-4 w-4" /></button>
                    <button type="button" :class="toolButtonClass" title="Sublinhado" @click="run('underline')"><Underline class="h-4 w-4" /></button>
                    <button type="button" :class="toolButtonClass" title="Lista" @click="run('insertUnorderedList')"><List class="h-4 w-4" /></button>
                    <button type="button" :class="toolButtonClass" title="Lista numerada" @click="run('insertOrderedList')"><ListOrdered class="h-4 w-4" /></button>
                    <button type="button" :class="toolButtonClass" title="Esquerda" @click="run('justifyLeft')"><AlignLeft class="h-4 w-4" /></button>
                    <button type="button" :class="toolButtonClass" title="Centro" @click="run('justifyCenter')"><AlignCenter class="h-4 w-4" /></button>
                    <button type="button" :class="toolButtonClass" title="Direita" @click="run('justifyRight')"><AlignRight class="h-4 w-4" /></button>
                    <button type="button" :class="toolButtonClass" title="Adicionar link" @click="createLink"><Link class="h-4 w-4" /></button>
                    <label class="flex h-8 items-center gap-1 rounded-md border border-zinc-300 px-2 text-[11px] dark:border-zinc-600">Texto <input v-model="textColor" type="color" class="h-5 w-5 border-0 bg-transparent p-0" @input="updateTextColor" /></label>
                    <button type="button" :class="toolButtonClass" title="Limpar formatação" @click="run('removeFormat')"><RemoveFormatting class="h-4 w-4" /></button>
                </div>
                <div class="p-4 sm:p-6">
                    <textarea v-if="sourceMode" :value="modelValue" :disabled="disabled" :required="required" class="mx-auto block w-full max-w-[700px] resize-y rounded-lg border border-zinc-300 bg-zinc-950 px-4 py-3 font-mono text-sm text-zinc-100 outline-none" :style="{ minHeight }" aria-label="Código HTML do e-mail" @input="emit('update:modelValue', $event.target.value)" />
                    <div v-else ref="editor" class="email-visual-editor mx-auto block w-full overflow-auto rounded-sm bg-white px-7 py-6 text-sm text-zinc-900 shadow-md outline-none transition-[max-width]" :class="[previewMode === 'mobile' ? 'max-w-[375px]' : 'max-w-[600px]', { 'cursor-not-allowed': disabled }]" :contenteditable="disabled ? 'false' : 'true'" :style="{ minHeight }" role="textbox" aria-multiline="true" aria-label="Editor visual do e-mail" @click="selectEditableElement" @focus="rememberSelection" @keyup="rememberSelection" @mouseup="rememberSelection" @input="rememberSelection(); emitVisualValue()" @blur="emitVisualValue" />
                </div>
            </main>

            <aside class="border-t border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900 xl:border-l xl:border-t-0">
                <template v-if="selectedType === 'button'">
                    <p class="mb-3 text-sm font-semibold">Botão</p>
                    <div class="space-y-3">
                        <label class="block text-xs font-medium">Texto<input v-model="buttonSettings.text" class="mt-1 block w-full rounded-md border border-zinc-300 px-2.5 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800" @input="updateSelectedButton" /></label>
                        <label class="block text-xs font-medium">URL<input v-model="buttonSettings.url" type="url" placeholder="https://..." class="mt-1 block w-full rounded-md border border-zinc-300 px-2.5 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800" @input="updateSelectedButton" /></label>
                        <label class="flex items-center justify-between text-xs font-medium">Cor do botão<input v-model="buttonSettings.backgroundColor" type="color" class="h-8 w-10" @input="updateSelectedButton" /></label>
                        <label class="flex items-center justify-between text-xs font-medium">Cor do texto<input v-model="buttonSettings.textColor" type="color" class="h-8 w-10" @input="updateSelectedButton" /></label>
                        <label class="block text-xs font-medium">Alinhamento<select v-model="buttonSettings.alignment" class="mt-1 block w-full rounded-md border border-zinc-300 px-2 py-2 dark:border-zinc-600 dark:bg-zinc-800" @change="updateSelectedButton"><option value="left">Esquerda</option><option value="center">Centro</option><option value="right">Direita</option></select></label>
                        <label class="block text-xs font-medium">Bordas: {{ buttonSettings.borderRadius }}px<input v-model.number="buttonSettings.borderRadius" type="range" min="0" max="32" class="mt-1 block w-full" @input="updateSelectedButton" /></label>
                        <label class="flex items-center gap-2 text-xs"><input v-model="buttonSettings.fullWidth" type="checkbox" @change="updateSelectedButton" /> Largura inteira</label>
                    </div>
                </template>
                <template v-else-if="selectedType === 'image'">
                    <p class="mb-3 text-sm font-semibold">Imagem</p>
                    <div class="space-y-3">
                        <label class="block text-xs font-medium">URL pública<input v-model="imageSettings.url" type="url" placeholder="https://.../imagem.jpg" class="mt-1 block w-full rounded-md border border-zinc-300 px-2.5 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800" @input="updateSelectedImage" /></label>
                        <label class="block text-xs font-medium">Texto alternativo<input v-model="imageSettings.alt" class="mt-1 block w-full rounded-md border border-zinc-300 px-2.5 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800" @input="updateSelectedImage" /></label>
                        <label class="block text-xs font-medium">Link ao clicar<input v-model="imageSettings.link" type="url" placeholder="https://..." class="mt-1 block w-full rounded-md border border-zinc-300 px-2.5 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800" @input="updateSelectedImage" /></label>
                        <label class="block text-xs font-medium">Largura: {{ imageSettings.width }}px<input v-model.number="imageSettings.width" type="range" min="100" max="800" step="10" class="mt-1 block w-full" @input="updateSelectedImage" /></label>
                        <label class="block text-xs font-medium">Bordas: {{ imageSettings.borderRadius }}px<input v-model.number="imageSettings.borderRadius" type="range" min="0" max="40" class="mt-1 block w-full" @input="updateSelectedImage" /></label>
                    </div>
                </template>
                <template v-else-if="selectedType === 'link'">
                    <p class="mb-3 text-sm font-semibold">Link</p>
                    <div class="space-y-3">
                        <label class="block text-xs font-medium">Texto<input v-model="linkSettings.text" class="mt-1 block w-full rounded-md border border-zinc-300 px-2.5 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800" @input="updateSelectedLink" /></label>
                        <label class="block text-xs font-medium">URL<input v-model="linkSettings.url" type="url" placeholder="https://..." class="mt-1 block w-full rounded-md border border-zinc-300 px-2.5 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800" @input="updateSelectedLink" /></label>
                        <label class="flex items-center justify-between text-xs font-medium">Cor<input v-model="linkSettings.color" type="color" class="h-8 w-10" @input="updateSelectedLink" /></label>
                    </div>
                </template>
                <template v-else-if="selectedType === 'block'">
                    <p class="text-sm font-semibold">{{ blockLabel(selectedElement?.dataset?.emailBlockType) }}</p>
                    <p class="mt-1 text-xs text-zinc-500">Edite o texto diretamente no canvas ou use as ações abaixo.</p>
                </template>
                <template v-else>
                    <p class="mb-3 text-sm font-semibold">Estilo geral</p>
                    <div class="space-y-3">
                        <label class="flex items-center justify-between text-xs font-medium">Fundo sem conteúdo<input v-model="backgroundColor" type="color" class="h-8 w-10" @input="updateBackgroundColor" /></label>
                        <label class="flex items-center justify-between text-xs font-medium">Fundo com conteúdo<input v-model="contentBackgroundColor" type="color" class="h-8 w-10" @input="updateContentBackgroundColor" /></label>
                        <label class="block text-xs font-medium">Fonte<select v-model="typographySettings.fontFamily" class="mt-1 block w-full rounded-md border border-zinc-300 px-2 py-2 dark:border-zinc-600 dark:bg-zinc-800" @change="updateTypography"><option value="Arial, sans-serif">Arial</option><option value="'Segoe UI', Arial, sans-serif">Segoe UI</option><option value="Georgia, serif">Georgia</option><option value="Tahoma, sans-serif">Tahoma</option><option value="Verdana, sans-serif">Verdana</option></select></label>
                        <label class="block text-xs font-medium">Tamanho do texto: {{ typographySettings.fontSize }}px<input v-model.number="typographySettings.fontSize" type="range" min="10" max="24" class="mt-1 block w-full" @input="updateTypography" /></label>
                        <label class="block text-xs font-medium">Altura da linha: {{ typographySettings.lineHeight }}<input v-model.number="typographySettings.lineHeight" type="range" min="1" max="2.2" step="0.1" class="mt-1 block w-full" @input="updateTypography" /></label>
                        <label class="flex items-center justify-between text-xs font-medium">Cor geral do texto<input v-model="typographySettings.color" type="color" class="h-8 w-10" @input="updateTypography" /></label>
                    </div>
                    <p class="mt-4 rounded-lg bg-zinc-50 p-3 text-xs leading-relaxed text-zinc-500 dark:bg-zinc-800">Adicione blocos pela biblioteca e clique em qualquer botão, imagem ou link para abrir suas propriedades.</p>
                </template>

                <div v-if="selectedType" class="mt-5 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">Ações</p>
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" class="rounded-md border border-zinc-300 px-2 py-2 text-xs dark:border-zinc-600" @click="moveSelectedElement('up')">Mover acima</button>
                        <button type="button" class="rounded-md border border-zinc-300 px-2 py-2 text-xs dark:border-zinc-600" @click="moveSelectedElement('down')">Mover abaixo</button>
                        <button type="button" class="rounded-md border border-zinc-300 px-2 py-2 text-xs dark:border-zinc-600" @click="duplicateSelectedElement">Duplicar</button>
                        <button type="button" class="rounded-md border border-red-200 px-2 py-2 text-xs text-red-600 dark:border-red-800" @click="removeSelectedElement">Excluir</button>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</template>

<style scoped>
.email-visual-editor :deep(a) { color: #0284c7; text-decoration: underline; }
.email-visual-editor :deep(ul) { list-style: disc; padding-left: 1.5rem; }
.email-visual-editor :deep(ol) { list-style: decimal; padding-left: 1.5rem; }
.email-visual-editor :deep(h1) { font-size: 2em; font-weight: 700; line-height: 1.2; }
.email-visual-editor :deep(h2) { font-size: 1.5em; font-weight: 700; line-height: 1.25; }
.email-visual-editor :deep(h3) { font-size: 1.17em; font-weight: 700; line-height: 1.3; }
.email-visual-editor :deep([data-email-block="true"]) { transition: outline-color 0.15s ease; outline: 2px solid transparent; outline-offset: 3px; }
.email-visual-editor :deep([data-email-block="true"]:hover) { outline-color: #38bdf8; }
.email-visual-editor :deep([data-email-button="true"]),
.email-visual-editor :deep([data-email-image-block="true"]),
.email-visual-editor :deep([data-email-link="true"]) { cursor: pointer; }
</style>
