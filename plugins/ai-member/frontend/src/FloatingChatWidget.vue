<script setup>
import { ref, computed, nextTick, watch } from 'vue';
import axios from 'axios';
import {
    MessageCircle, X, Send, Loader2, Image as ImageIcon, Mic, Square,
    Archive, ChevronLeft, Plus, Sparkles,
} from 'lucide-vue-next';

const props = defineProps({
    product_id: { type: String, default: '' },
    slug: { type: String, default: '' },
    ai_member_widget: { type: Object, default: null },
    user_name: { type: String, default: '' },
    theme_primary: { type: String, default: '' },
});

const open = ref(false);
const view = ref('chat'); // chat | archive | archived-chat
const messages = ref([]);
const conversations = ref([]);
const conversationId = ref('');
const isArchivedView = ref(false);
const input = ref('');
const sending = ref(false);
const loadingHistory = ref(false);
const loadingConversations = ref(false);
const messagesEl = ref(null);
const imageInput = ref(null);
const recording = ref(false);
const mediaRecorder = ref(null);
const audioChunks = ref([]);

const config = computed(() => {
    if (props.ai_member_widget && typeof props.ai_member_widget === 'object') {
        return props.ai_member_widget;
    }
    return { enabled: false };
});

const productId = computed(() => props.product_id || config.value.product_id || '');
const accentColor = computed(() =>
    config.value.widget_color
    || props.theme_primary
    || config.value.theme_primary
    || '#0ea5e9',
);
const isVisible = computed(() => Boolean(config.value.enabled && productId.value));
const userFirstName = computed(() => {
    const name = (props.user_name || '').trim();
    if (!name) return 'por aqui';
    return name.split(/\s+/)[0];
});
const showWelcome = computed(() =>
    view.value === 'chat'
    && !loadingHistory.value
    && messages.value.length === 0
    && !isArchivedView.value,
);
const canSend = computed(() => !isArchivedView.value && !sending.value);

watch(open, async (isOpen) => {
    if (isOpen) {
        view.value = 'chat';
        isArchivedView.value = false;
        await loadHistory();
    }
});

async function loadHistory(id = null) {
    if (!productId.value) return;
    loadingHistory.value = true;
    try {
        const params = { product_id: productId.value };
        if (id) params.conversation_id = id;
        const { data } = await axios.get('/api/ai-member/history', { params });
        messages.value = data.messages ?? [];
        conversationId.value = data.conversation_id || '';
        isArchivedView.value = Boolean(data.archived);
    } catch {
        messages.value = [];
        conversationId.value = '';
    } finally {
        loadingHistory.value = false;
        scrollToBottom();
    }
}

async function loadConversations() {
    if (!productId.value) return;
    loadingConversations.value = true;
    try {
        const { data } = await axios.get('/api/ai-member/conversations', {
            params: { product_id: productId.value },
        });
        conversations.value = data.conversations ?? [];
    } catch {
        conversations.value = [];
    } finally {
        loadingConversations.value = false;
    }
}

async function openArchive() {
    view.value = 'archive';
    await loadConversations();
}

async function openConversation(conv) {
    view.value = 'archived-chat';
    isArchivedView.value = conv.archived;
    await loadHistory(conv.id);
}

async function startNewConversation() {
    try {
        const { data } = await axios.post('/api/ai-member/conversations/new', {
            product_id: productId.value,
        });
        conversationId.value = data.conversation_id || '';
        messages.value = [];
        isArchivedView.value = false;
        view.value = 'chat';
        input.value = '';
    } catch (e) {
        alert(e?.response?.data?.message || 'Não foi possível iniciar nova conversa.');
    }
}

async function send(payload = {}) {
    const text = (payload.message ?? input.value).trim();
    const hasMedia = payload.image_base64 || payload.audio_base64;
    if ((!text && !hasMedia) || !canSend.value) return;

    if (text) {
        messages.value.push({
            id: `u-${Date.now()}`,
            role: 'user',
            content: text,
            created_at: new Date().toISOString(),
        });
    }
    input.value = '';
    sending.value = true;
    scrollToBottom();

    try {
        const { data } = await axios.post('/api/ai-member/chat', {
            product_id: productId.value,
            message: text,
            ...payload,
        });
        if (data.conversation_id) {
            conversationId.value = data.conversation_id;
        }
        if (data.message?.content) {
            const last = messages.value[messages.value.length - 1];
            if (last?.role === 'user' && data.message.id) {
                last.id = data.message.id;
                last.created_at = data.message.created_at;
            }
        }
        if (data.reply) {
            messages.value.push({
                id: data.reply.id,
                role: 'assistant',
                content: data.reply.content,
                created_at: data.reply.created_at,
            });
        }
    } catch (e) {
        messages.value.push({
            id: `err-${Date.now()}`,
            role: 'assistant',
            content: e?.response?.data?.message || 'Não foi possível enviar. Tente novamente.',
            created_at: new Date().toISOString(),
        });
    } finally {
        sending.value = false;
        scrollToBottom();
    }
}

function backToChat() {
    if (view.value === 'archived-chat') {
        openArchive();
        return;
    }
    view.value = 'chat';
    isArchivedView.value = false;
    loadHistory();
}

function scrollToBottom() {
    nextTick(() => {
        if (messagesEl.value) {
            messagesEl.value.scrollTop = messagesEl.value.scrollHeight;
        }
    });
}

function onImageSelect(event) {
    const file = event.target.files?.[0];
    if (!file || !config.value.allow_image) return;
    if (file.size > 5 * 1024 * 1024) {
        alert('Imagem muito grande (máx. 5MB).');
        return;
    }
    const reader = new FileReader();
    reader.onload = () => {
        const result = reader.result;
        if (typeof result === 'string') {
            send({
                message: input.value || 'Analise esta imagem.',
                image_base64: result.split(',')[1],
                image_mime: file.type,
            });
        }
    };
    reader.readAsDataURL(file);
    event.target.value = '';
}

async function toggleRecording() {
    if (!config.value.allow_audio || !canSend.value) return;
    if (recording.value) {
        mediaRecorder.value?.stop();
        return;
    }
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        audioChunks.value = [];
        const recorder = new MediaRecorder(stream);
        mediaRecorder.value = recorder;
        recorder.ondataavailable = (e) => {
            if (e.data.size > 0) audioChunks.value.push(e.data);
        };
        recorder.onstop = async () => {
            stream.getTracks().forEach((t) => t.stop());
            recording.value = false;
            const blob = new Blob(audioChunks.value, { type: 'audio/webm' });
            if (blob.size > 10 * 1024 * 1024) {
                alert('Áudio muito grande (máx. 10MB).');
                return;
            }
            const reader = new FileReader();
            reader.onload = () => {
                const result = reader.result;
                if (typeof result === 'string') {
                    send({
                        message: input.value,
                        audio_base64: result.split(',')[1],
                        audio_mime: 'audio/webm',
                    });
                }
            };
            reader.readAsDataURL(blob);
        };
        recorder.start();
        recording.value = true;
    } catch {
        alert('Permita acesso ao microfone para enviar áudio.');
    }
}

function formatContent(text) {
    if (!text) return '';
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\n/g, '<br>');
}

function formatTimestamp(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const day = String(d.getDate()).padStart(2, '0');
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const hours = String(d.getHours()).padStart(2, '0');
    const mins = String(d.getMinutes()).padStart(2, '0');
    return `${day}/${month} • ${hours}:${mins}`;
}

function formatListDate(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' });
}
</script>

<template>
    <Teleport v-if="isVisible" to="body">
        <!-- FAB -->
        <button
            type="button"
            class="fixed bottom-4 right-4 z-[9998] flex h-14 w-14 items-center justify-center rounded-full shadow-lg transition hover:scale-105 focus:outline-none focus:ring-2 focus:ring-offset-2 sm:bottom-6 sm:right-6"
            :style="{ backgroundColor: accentColor }"
            :aria-label="open ? 'Fechar chat' : 'Abrir chat'"
            @click="open = !open"
        >
            <img
                v-if="config.widget_icon && !open"
                :src="config.widget_icon"
                alt=""
                class="h-8 w-8 rounded-full object-cover"
            />
            <MessageCircle v-else-if="!open" class="h-7 w-7 text-white" />
            <X v-else class="h-7 w-7 text-white" />
        </button>

        <!-- Painel -->
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:translate-x-4"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0 translate-y-4"
        >
            <div
                v-if="open"
                class="fixed z-[9999] flex flex-col overflow-hidden bg-[#121212] text-zinc-100 shadow-2xl
                    inset-0 sm:inset-auto sm:bottom-24 sm:right-6 sm:h-[min(560px,calc(100vh-7rem))] sm:w-[400px] sm:rounded-2xl sm:border sm:border-zinc-800"
            >
                <!-- Header -->
                <header class="relative flex shrink-0 items-center justify-between border-b border-zinc-800 px-4 py-3">
                    <div class="flex w-20 items-center gap-1">
                        <button
                            v-if="view !== 'chat'"
                            type="button"
                            class="rounded-lg p-2 text-zinc-400 transition hover:bg-zinc-800 hover:text-white"
                            aria-label="Voltar"
                            @click="backToChat"
                        >
                            <ChevronLeft class="h-5 w-5" />
                        </button>
                        <button
                            v-else
                            type="button"
                            class="rounded-lg p-2 text-zinc-400 transition hover:bg-zinc-800 hover:text-white"
                            aria-label="Conversas anteriores"
                            @click="openArchive"
                        >
                            <Archive class="h-5 w-5" />
                        </button>
                    </div>
                    <h2 class="absolute left-1/2 -translate-x-1/2 truncate text-sm font-medium text-white">
                        {{ view === 'archive' ? 'Conversas' : (config.name || 'Assistente') }}
                    </h2>
                    <div class="flex w-20 items-center justify-end gap-1">
                        <button
                            v-if="view === 'chat' && messages.length > 0"
                            type="button"
                            class="rounded-lg p-2 text-zinc-400 transition hover:bg-zinc-800 hover:text-white"
                            aria-label="Nova conversa"
                            @click="startNewConversation"
                        >
                            <Plus class="h-5 w-5" />
                        </button>
                        <button
                            type="button"
                            class="rounded-lg p-2 text-zinc-400 transition hover:bg-zinc-800 hover:text-white sm:hidden"
                            aria-label="Fechar"
                            @click="open = false"
                        >
                            <X class="h-5 w-5" />
                        </button>
                    </div>
                </header>

                <!-- Lista de conversas -->
                <div v-if="view === 'archive'" class="flex-1 overflow-y-auto px-2 py-2">
                    <div v-if="loadingConversations" class="flex justify-center py-12">
                        <Loader2 class="h-6 w-6 animate-spin text-zinc-500" />
                    </div>
                    <div v-else-if="conversations.length === 0" class="px-4 py-12 text-center text-sm text-zinc-500">
                        Nenhuma conversa anterior.
                    </div>
                    <button
                        v-for="conv in conversations"
                        :key="conv.id"
                        type="button"
                        class="mb-1 flex w-full items-start gap-3 rounded-xl px-3 py-3 text-left transition hover:bg-zinc-800/80"
                        @click="openConversation(conv)"
                    >
                        <div
                            class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                            :style="{ backgroundColor: `${accentColor}33` }"
                        >
                            <MessageCircle class="h-4 w-4" :style="{ color: accentColor }" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="truncate text-sm font-medium text-white">{{ conv.title }}</span>
                                <span
                                    v-if="!conv.archived"
                                    class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium"
                                    :style="{ backgroundColor: `${accentColor}22`, color: accentColor }"
                                >
                                    Atual
                                </span>
                            </div>
                            <p class="mt-0.5 text-xs text-zinc-500">
                                {{ conv.messages_count }} mensagem{{ conv.messages_count === 1 ? '' : 'ens' }}
                                · {{ formatListDate(conv.last_message_at) }}
                            </p>
                        </div>
                    </button>
                    <button
                        type="button"
                        class="mx-2 mt-2 flex w-[calc(100%-1rem)] items-center justify-center gap-2 rounded-xl border border-zinc-700 py-2.5 text-sm font-medium text-zinc-300 transition hover:bg-zinc-800"
                        @click="startNewConversation"
                    >
                        <Plus class="h-4 w-4" />
                        Nova conversa
                    </button>
                </div>

                <!-- Chat / histórico -->
                <template v-else>
                    <div ref="messagesEl" class="flex-1 overflow-y-auto px-4 py-4">
                        <div v-if="loadingHistory" class="flex justify-center py-12">
                            <Loader2 class="h-6 w-6 animate-spin text-zinc-500" />
                        </div>

                        <!-- Tela de boas-vindas -->
                        <div
                            v-else-if="showWelcome"
                            class="flex h-full min-h-[280px] flex-col items-center justify-center px-2 text-center"
                        >
                            <div
                                class="mb-5 flex h-16 w-16 items-center justify-center rounded-full shadow-lg"
                                :style="{ background: `linear-gradient(135deg, ${accentColor}, #1e1b4b)` }"
                            >
                                <img
                                    v-if="config.widget_icon"
                                    :src="config.widget_icon"
                                    alt=""
                                    class="h-10 w-10 rounded-full object-cover"
                                />
                                <Sparkles v-else class="h-8 w-8 text-white" />
                            </div>
                            <p class="text-base text-zinc-300">
                                Olá, {{ userFirstName }}!
                            </p>
                            <h3 class="mt-2 max-w-[280px] text-xl font-bold leading-snug text-white">
                                {{ config.intro_headline || 'Como posso ajudar você hoje?' }}
                            </h3>
                            <p
                                v-if="config.welcome_message"
                                class="mt-4 max-w-[300px] text-sm leading-relaxed text-zinc-500"
                            >
                                {{ config.welcome_message }}
                            </p>
                        </div>

                        <!-- Mensagens -->
                        <div v-else class="space-y-5">
                            <div
                                v-for="msg in messages"
                                :key="msg.id"
                                class="flex flex-col"
                                :class="msg.role === 'user' ? 'items-end' : 'items-start'"
                            >
                                <div
                                    v-if="msg.role === 'user'"
                                    class="max-w-[85%] rounded-2xl rounded-br-md bg-zinc-800 px-4 py-2.5 text-sm leading-relaxed text-zinc-100"
                                    v-html="formatContent(msg.content)"
                                />
                                <div
                                    v-else
                                    class="max-w-[92%] text-sm leading-relaxed text-zinc-200"
                                    v-html="formatContent(msg.content)"
                                />
                                <span
                                    class="mt-1.5 text-[11px] text-zinc-600"
                                    :class="msg.role === 'user' ? 'text-right' : 'text-left'"
                                >
                                    {{ formatTimestamp(msg.created_at) }}
                                </span>
                            </div>
                            <div v-if="sending" class="flex items-start">
                                <Loader2 class="h-4 w-4 animate-spin text-zinc-500" />
                            </div>
                        </div>
                    </div>

                    <!-- Input -->
                    <footer class="shrink-0 border-t border-zinc-800 px-4 py-3">
                        <div
                            v-if="isArchivedView"
                            class="mb-2 rounded-xl bg-zinc-900 px-3 py-2 text-center text-xs text-zinc-500"
                        >
                            Conversa arquivada — somente leitura
                        </div>
                        <div class="relative flex items-center">
                            <input ref="imageInput" type="file" accept="image/*" class="hidden" @change="onImageSelect" />
                            <textarea
                                v-model="input"
                                rows="1"
                                class="max-h-24 min-h-[44px] w-full resize-none rounded-full border border-zinc-700 bg-transparent py-2.5 pl-4 pr-24 text-sm text-zinc-100 placeholder:text-zinc-500 focus:border-zinc-600 focus:outline-none disabled:opacity-50"
                                :placeholder="isArchivedView ? 'Conversa arquivada' : 'Pergunte alguma coisa'"
                                :disabled="!canSend"
                                @keydown.enter.exact.prevent="send()"
                            />
                            <div class="absolute right-1.5 flex items-center gap-0.5">
                                <button
                                    v-if="config.allow_image && canSend"
                                    type="button"
                                    class="rounded-full p-2 text-zinc-500 transition hover:text-zinc-300"
                                    @click="imageInput?.click()"
                                >
                                    <ImageIcon class="h-4 w-4" />
                                </button>
                                <button
                                    v-if="config.allow_audio && canSend"
                                    type="button"
                                    class="rounded-full p-2 transition"
                                    :class="recording ? 'text-red-400' : 'text-zinc-500 hover:text-zinc-300'"
                                    @click="toggleRecording"
                                >
                                    <Square v-if="recording" class="h-4 w-4" />
                                    <Mic v-else class="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded-full p-2 text-zinc-300 transition hover:text-white disabled:opacity-40"
                                    :disabled="!canSend"
                                    @click="send()"
                                >
                                    <Send class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                        <p class="mt-2 text-center text-[10px] text-zinc-600">
                            Tecnologia ✦ {{ config.name || 'IA' }}
                        </p>
                    </footer>
                </template>
            </div>
        </Transition>
    </Teleport>
</template>
