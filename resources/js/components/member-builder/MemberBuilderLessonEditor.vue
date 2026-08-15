<script setup>
import { computed, watch } from 'vue';
import Draggable from 'vuedraggable';
import Button from '@/components/ui/Button.vue';
import Toggle from '@/components/ui/Toggle.vue';
import {
    Plus, Trash2, GripVertical, FileVideo, Link, FileText, BookOpen, Presentation, Copy,
} from 'lucide-vue-next';

const props = defineProps({
    module: { type: Object, default: null },
    lessonForm: { type: Object, default: null },
    lessonFormSaving: { type: Boolean, default: false },
    memberReorderSaving: { type: Boolean, default: false },
    inputClass: { type: String, required: true },
    uploadLimits: { type: Object, required: true },
    lessonPdfUploading: { type: Boolean, default: false },
    lessonSupportUploading: { type: Boolean, default: false },
    isLessonPdfContentType: { type: Function, required: true },
    pdfLessonFileLabel: { type: Function, required: true },
});

const emit = defineEmits([
    'open-lesson-form',
    'close-lesson-form',
    'save-lesson',
    'delete-lesson',
    'duplicate-lesson',
    'lessons-reorder-end',
    'lesson-pdf-change',
    'clear-lesson-pdf',
    'remove-lesson-pdf-at',
    'support-pdf-change',
    'clear-support-files',
    'remove-support-file-at',
    'add-useful-link',
    'remove-useful-link-at',
    'pick-lesson-pdf',
    'pick-support-pdf',
]);

const lessonsModel = defineModel('lessons', { type: Array, default: () => [] });
const previousLessons = computed(() => {
    if (!props.lessonForm) return [];
    const lessons = props.module?.lessons ?? [];
    const index = props.lessonForm.id ? lessons.findIndex((lesson) => lesson.id === props.lessonForm.id) : lessons.length;
    return lessons.slice(0, Math.max(0, index)).reverse();
});

watch(previousLessons, (lessons) => {
    if (!props.lessonForm) return;

    const validIds = new Set(lessons.map((lesson) => Number(lesson.id)));
    const validDependencies = (props.lessonForm.release_dependency_lesson_ids ?? [])
        .map(Number)
        .filter((id) => validIds.has(id));

    props.lessonForm.release_dependency_lesson_ids = validDependencies;
    if (validDependencies.length === 0) {
        props.lessonForm.requires_previous_lessons = false;
    }
}, { immediate: true });
</script>

<template>
    <div v-if="!module" class="flex h-full flex-col items-center justify-center p-4 text-center text-xs text-zinc-500 dark:text-zinc-400">
        Selecione um módulo para ver ou editar aulas.
    </div>
    <div v-else class="flex min-h-0 flex-1 flex-col">
        <div class="mb-3 shrink-0">
            <p class="truncate text-xs font-medium text-zinc-700 dark:text-zinc-300">{{ module.title }}</p>
            <p class="text-[10px] text-zinc-500 dark:text-zinc-400">{{ (module.lessons?.length ?? 0) }} aula(s)</p>
        </div>

        <template v-if="!lessonForm">
            <Draggable
                v-model="lessonsModel"
                tag="ul"
                :component-data="{ class: 'min-h-0 flex-1 space-y-1 overflow-y-auto' }"
                item-key="id"
                handle=".mb-drag-handle--lesson"
                :animation="160"
                ghost-class="opacity-60"
                :disabled="memberReorderSaving"
                @end="emit('lessons-reorder-end')"
            >
                <template #item="{ element: lesson }">
                    <li
                        class="flex cursor-pointer items-center gap-1 rounded-lg py-2 pl-1 pr-2 text-sm transition hover:bg-zinc-200/80 dark:hover:bg-zinc-700/50"
                        @click="emit('open-lesson-form', lesson)"
                    >
                        <button
                            type="button"
                            class="mb-drag-handle--lesson shrink-0 cursor-grab rounded p-0.5 text-zinc-400 hover:text-zinc-600 active:cursor-grabbing"
                            title="Arrastar"
                            @click.stop
                        >
                            <GripVertical class="h-3.5 w-3.5" />
                        </button>
                        <span class="flex min-w-0 flex-1 items-center gap-2 truncate">
                            <FileVideo v-if="lesson.type === 'video'" class="h-4 w-4 shrink-0 text-zinc-500" />
                            <Link v-else-if="lesson.type === 'link'" class="h-4 w-4 shrink-0 text-zinc-500" />
                            <Presentation v-else-if="lesson.type === 'pdf_presentation'" class="h-4 w-4 shrink-0 text-zinc-500" />
                            <BookOpen v-else-if="lesson.type === 'pdf_reader'" class="h-4 w-4 shrink-0 text-zinc-500" />
                            <FileText v-else class="h-4 w-4 shrink-0 text-zinc-500" />
                            <span class="truncate text-zinc-700 dark:text-zinc-300">{{ lesson.title || 'Sem título' }}</span>
                        </span>
                        <button
                            type="button"
                            class="shrink-0 rounded p-1 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300"
                            title="Duplicar aula"
                            @click.stop="emit('duplicate-lesson', lesson.id)"
                        >
                            <Copy class="h-3 w-3" />
                        </button>
                        <button
                            type="button"
                            class="shrink-0 rounded p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30"
                            title="Remover aula"
                            @click.stop="emit('delete-lesson', lesson.id)"
                        >
                            <Trash2 class="h-3 w-3" />
                        </button>
                    </li>
                </template>
            </Draggable>
            <p v-if="!lessonsModel.length" class="py-3 text-xs text-zinc-500 dark:text-zinc-400">Nenhuma aula neste módulo.</p>
            <Button size="sm" class="mt-3 w-full shrink-0" @click="emit('open-lesson-form', null)">
                <Plus class="mr-2 h-4 w-4" />
                Nova aula
            </Button>
        </template>

        <div v-else class="min-h-0 flex-1 space-y-3 overflow-y-auto">
            <div>
                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Título</label>
                <input v-model="lessonForm.title" type="text" :class="inputClass" class="w-full" placeholder="Título da aula" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Tipo</label>
                <select v-model="lessonForm.type" :class="inputClass" class="w-full">
                    <option value="video">Vídeo</option>
                    <option value="link">Link</option>
                    <option value="pdf">Material</option>
                    <option value="pdf_presentation">Apresentação (PDF)</option>
                    <option value="pdf_reader">Leitor de PDF</option>
                    <option value="text">Texto</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Liberação</label>
                <div class="grid gap-2">
                    <select v-model="lessonForm.release_mode" :class="inputClass" class="w-full">
                        <option value="none">Imediata</option>
                        <option value="days">Após X dias</option>
                        <option value="date">Na data</option>
                    </select>
                    <input
                        v-if="lessonForm.release_mode === 'days'"
                        v-model="lessonForm.release_after_days"
                        type="number"
                        min="1"
                        :class="inputClass"
                        class="w-full"
                        placeholder="Ex.: 7"
                    />
                    <input
                        v-else-if="lessonForm.release_mode === 'date'"
                        v-model="lessonForm.release_at_date"
                        type="date"
                        :class="inputClass"
                        class="w-full"
                    />
                </div>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Duração do acesso</label>
                <input
                    v-model="lessonForm.access_duration_days"
                    type="number"
                    min="1"
                    max="3650"
                    :class="inputClass"
                    class="w-full"
                    placeholder="Ilimitado"
                />
                <p class="mt-1 text-[10px] text-zinc-500 dark:text-zinc-400">
                    Em dias após a compra. Deixe vazio para acesso ilimitado.
                </p>
                <label class="flex items-center gap-2 text-xs font-medium text-zinc-600 dark:text-zinc-400">
                    <input v-model="lessonForm.requires_previous_lessons" type="checkbox" :disabled="previousLessons.length === 0" class="rounded border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]" />
                    Aula(s) anterior(es) concluída(s)?
                </label>
                <select v-if="lessonForm.requires_previous_lessons && previousLessons.length" v-model="lessonForm.release_dependency_lesson_ids" multiple size="4" :class="inputClass" class="mt-2 w-full">
                    <option v-for="(lesson, index) in previousLessons" :key="lesson.id" :value="lesson.id">{{ index === 0 ? 'Aula anterior — ' : '' }}{{ lesson.title }}</option>
                </select>
                <p v-if="previousLessons.length === 0" class="mt-1 text-[10px] text-zinc-500">Não há aula anterior neste módulo para selecionar.</p>
                <p v-else-if="lessonForm.requires_previous_lessons" class="mt-1 text-[10px] text-zinc-500">Selecione uma ou mais aulas que o aluno deve concluir.</p>
            </div>
            <div v-if="lessonForm.type === 'link'">
                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Título do link</label>
                <input v-model="lessonForm.link_title" type="text" :class="inputClass" class="w-full" placeholder="Ex: Abrir material" />
            </div>
            <div v-if="['video', 'link', 'pdf', 'pdf_presentation', 'pdf_reader'].includes(lessonForm.type)">
                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">URL do conteúdo</label>
                <input v-model="lessonForm.content_url" type="url" :class="inputClass" class="w-full" placeholder="https://..." />
            </div>
            <div v-if="isLessonPdfContentType(lessonForm.type)" class="space-y-2">
                <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">Enviar PDF</label>
                <div class="flex flex-wrap items-center gap-2">
                    <Button type="button" size="sm" variant="outline" :disabled="lessonPdfUploading" @click="emit('pick-lesson-pdf')">
                        {{ lessonPdfUploading ? 'Enviando…' : 'Selecionar PDFs' }}
                    </Button>
                    <button v-if="(lessonForm.content_files?.length ?? 0) > 0" type="button" class="text-xs text-red-600 hover:underline" @click="emit('clear-lesson-pdf')">Remover todos</button>
                </div>
                <div v-if="(lessonForm.content_files?.length ?? 0) > 0" class="space-y-1">
                    <div
                        v-for="(f, i) in lessonForm.content_files"
                        :key="`${f.url}-${i}`"
                        class="flex items-center justify-between gap-2 rounded-md border border-zinc-200 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800/50"
                    >
                        <span class="min-w-0 flex-1 truncate">{{ f.name || pdfLessonFileLabel(lessonForm.type) }}</span>
                        <button type="button" class="text-red-600 hover:underline" @click="emit('remove-lesson-pdf-at', i)">Remover</button>
                    </div>
                </div>
            </div>
            <div v-if="lessonForm.type === 'text'">
                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Texto</label>
                <textarea v-model="lessonForm.content_text" :class="inputClass" class="w-full" rows="3" />
            </div>
            <div v-if="lessonForm.type !== 'text'">
                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Descrição</label>
                <textarea v-model="lessonForm.content_text" :class="inputClass" class="w-full" rows="2" placeholder="Opcional..." />
            </div>
            <div v-if="lessonForm.type === 'video'" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                <Toggle v-model="lessonForm.watermark_enabled" label="Marca d'água (DRM)" />
            </div>
            <div class="space-y-2 rounded-lg border border-zinc-200 bg-zinc-50/80 p-3 dark:border-zinc-700 dark:bg-zinc-800/30">
                <div class="flex items-center justify-between gap-2">
                    <label class="text-xs font-medium text-zinc-600 dark:text-zinc-400">Materiais de apoio</label>
                    <Button type="button" size="sm" variant="outline" :disabled="lessonSupportUploading" @click="emit('pick-support-pdf')">PDFs</Button>
                </div>
                <div v-if="(lessonForm.support_files?.length ?? 0) > 0" class="space-y-1">
                    <div
                        v-for="(f, i) in lessonForm.support_files"
                        :key="`s-${f.url}-${i}`"
                        class="flex items-center justify-between gap-2 rounded-md border border-zinc-200 bg-white px-2 py-1 text-xs dark:border-zinc-700"
                    >
                        <span class="truncate">{{ f.name || 'Material' }}</span>
                        <button type="button" class="text-red-600 hover:underline" @click="emit('remove-support-file-at', i)">Remover</button>
                    </div>
                </div>
                <div class="flex items-center justify-between gap-2 border-t border-zinc-200 pt-2 dark:border-zinc-700">
                    <label class="text-xs font-medium text-zinc-600 dark:text-zinc-400">Links úteis</label>
                    <Button type="button" size="sm" variant="outline" @click="emit('add-useful-link')">Adicionar</Button>
                </div>
                <div v-if="(lessonForm.useful_links?.length ?? 0) > 0" class="space-y-2">
                    <div v-for="(link, i) in lessonForm.useful_links" :key="`l-${i}`" class="space-y-1 rounded-md border border-zinc-200 p-2 dark:border-zinc-700">
                        <input v-model="link.title" type="text" :class="inputClass" class="w-full" placeholder="Título" />
                        <input v-model="link.url" type="url" :class="inputClass" class="w-full" placeholder="https://..." />
                        <button type="button" class="text-xs text-red-600 hover:underline" @click="emit('remove-useful-link-at', i)">Remover link</button>
                    </div>
                </div>
            </div>
            <div class="flex gap-2 pt-2">
                <Button variant="outline" size="sm" class="flex-1" @click="emit('close-lesson-form')">Cancelar</Button>
                <Button size="sm" class="flex-1" :disabled="lessonFormSaving" @click="emit('save-lesson')">
                    {{ lessonFormSaving ? 'Salvando…' : 'Salvar' }}
                </Button>
            </div>
        </div>
    </div>
</template>
