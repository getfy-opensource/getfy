<script setup>
import { computed, ref, watch } from 'vue';
import Draggable from 'vuedraggable';
import Button from '@/components/ui/Button.vue';
import MemberBuilderLessonEditor from '@/components/member-builder/MemberBuilderLessonEditor.vue';
import MemberBuilderModuleEditor from '@/components/member-builder/MemberBuilderModuleEditor.vue';
import {
    Plus, Trash2, GripVertical, Pencil, FolderOpen, BookOpen, ChevronLeft, PanelRightClose, PanelRight, Copy,
} from 'lucide-vue-next';

const sections = defineModel('sections', { type: Array, default: () => [] });
const selectedSectionId = defineModel('selectedSectionId', { type: Number, default: null });
const selectedModuleId = defineModel('selectedModuleId', { type: Number, default: null });
const previewOpen = defineModel('previewOpen', { type: Boolean, default: true });

const props = defineProps({
    tenantProducts: { type: Array, default: () => [] },
    uploadLimits: { type: Object, required: true },
    inputClass: { type: String, required: true },
    memberReorderSaving: { type: Boolean, default: false },
    sectionTypeLabel: { type: Function, required: true },
    lessonForm: { type: Object, default: null },
    lessonFormSaving: { type: Boolean, default: false },
    moduleFormSaving: { type: Boolean, default: false },
    lessonPdfUploading: { type: Boolean, default: false },
    lessonSupportUploading: { type: Boolean, default: false },
    isLessonPdfContentType: { type: Function, required: true },
    pdfLessonFileLabel: { type: Function, required: true },
    editingSectionId: { type: Number, default: null },
    editingSectionTitle: { type: String, default: '' },
    editingSectionCoverMode: { type: String, default: 'vertical' },
    editingModuleId: { type: Number, default: null },
    editingModuleTitle: { type: String, default: '' },
    editingModuleShowTitleOnCover: { type: Boolean, default: true },
    editingModuleRelatedProductId: { default: null },
    editingModuleAccessType: { type: String, default: 'paid' },
    editingModuleExternalUrl: { type: String, default: '' },
    editingModuleReleaseMode: { type: String, default: 'none' },
    editingModuleReleaseAfterDays: { type: String, default: '' },
    editingModuleReleaseAtDate: { type: String, default: '' },
    editingModuleReleaseProgressPercent: { type: String, default: '' },
    editingModuleReleaseRequiredModuleIds: { type: Array, default: () => [] },
    editingModuleAccessDurationDays: { type: String, default: '' },
    editingModuleThumbnail: { type: String, default: null },
    moduleThumbnailUploading: { type: Boolean, default: false },
});

const emit = defineEmits([
    'open-section-modal',
    'open-module-modal',
    'delete-section',
    'duplicate-section',
    'delete-module',
    'duplicate-module',
    'sections-reorder-end',
    'modules-reorder-end',
    'lessons-reorder-end',
    'edit-section',
    'save-section',
    'cancel-section-edit',
    'save-module',
    'open-module-edit',
    'open-lesson-form',
    'close-lesson-form',
    'save-lesson',
    'delete-lesson',
    'duplicate-lesson',
    'pick-lesson-pdf',
    'pick-support-pdf',
    'pick-module-thumbnail',
    'remove-module-thumbnail',
    'set-module-show-title-on-cover',
    'clear-lesson-pdf',
    'remove-lesson-pdf-at',
    'clear-support-files',
    'remove-support-file-at',
    'add-useful-link',
    'remove-useful-link-at',
    'update:editingSectionTitle',
    'update:editingSectionCoverMode',
    'update:editingModuleTitle',
    'update:editingModuleShowTitleOnCover',
    'update:editingModuleRelatedProductId',
    'update:editingModuleAccessType',
    'update:editingModuleExternalUrl',
    'update:editingModuleReleaseMode',
    'update:editingModuleReleaseAfterDays',
    'update:editingModuleReleaseAtDate',
    'update:editingModuleReleaseProgressPercent',
    'update:editingModuleReleaseRequiredModuleIds',
    'update:editingModuleAccessDurationDays',
]);

const mobileStep = ref('sections');
/** Em seções courses: alterna entre lista de aulas e edição do módulo na coluna 3. */
const column3View = ref('lessons');

const selectedSection = computed(() =>
    sections.value.find((s) => s.id === selectedSectionId.value) ?? null,
);

const selectedModule = computed(() =>
    selectedSection.value?.modules?.find((m) => m.id === selectedModuleId.value) ?? null,
);

const productModulesForPrereq = computed(() => {
    const list = [];
    for (const s of sections.value || []) {
        for (const m of s.modules || []) {
            list.push({
                id: m.id,
                title: m.title,
                section_title: s.title,
            });
        }
    }
    return list;
});

const isCoursesSection = computed(() =>
    (selectedSection.value?.section_type ?? 'courses') === 'courses',
);

const lessonsForEditor = computed({
    get: () => selectedModule.value?.lessons ?? [],
    set: (val) => {
        if (selectedModule.value) selectedModule.value.lessons = val;
    },
});

function selectSection(section) {
    selectedSectionId.value = section.id;
    selectedModuleId.value = null;
    column3View.value = 'lessons';
    if (window.matchMedia('(max-width: 1023px)').matches) mobileStep.value = 'modules';
}

function selectModule(mod) {
    selectedModuleId.value = mod.id;
    column3View.value = 'lessons';
    emit('open-module-edit', mod);
    if (window.matchMedia('(max-width: 1023px)').matches) mobileStep.value = 'lessons';
}

function openModuleSettings(mod) {
    selectedModuleId.value = mod.id;
    column3View.value = 'module';
    emit('open-module-edit', mod);
    if (window.matchMedia('(max-width: 1023px)').matches) mobileStep.value = 'lessons';
}

function mobileBack() {
    if (mobileStep.value === 'lessons') {
        mobileStep.value = 'modules';
        selectedModuleId.value = null;
    } else if (mobileStep.value === 'modules') {
        mobileStep.value = 'sections';
        selectedSectionId.value = null;
    }
}

watch(selectedSectionId, (id) => {
    if (!id) return;
    const section = sections.value.find((s) => s.id === id);
    if (section && !section.modules?.length) selectedModuleId.value = null;
});

watch(selectedModuleId, (id, prev) => {
    if (id !== prev) column3View.value = 'lessons';
});

const columnClass = (step) => [
    'flex min-h-0 min-w-0 flex-col rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900',
    'lg:flex lg:flex-1',
    mobileStep.value === step ? 'flex flex-1' : 'hidden',
];
</script>

<template>
    <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
        <div class="mb-3 flex shrink-0 flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Módulos e aulas</h2>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Arraste para reordenar. Selecione seção → módulo → aula.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button size="sm" @click="emit('open-section-modal')">
                    <Plus class="mr-1.5 h-4 w-4" /> Nova seção
                </Button>
                <Button size="sm" variant="outline" class="hidden lg:inline-flex" @click="previewOpen = !previewOpen">
                    <PanelRightClose v-if="previewOpen" class="mr-1.5 h-4 w-4" />
                    <PanelRight v-else class="mr-1.5 h-4 w-4" />
                    {{ previewOpen ? 'Ocultar preview' : 'Mostrar preview' }}
                </Button>
            </div>
        </div>

        <div v-if="memberReorderSaving" class="mb-2 shrink-0 text-xs text-sky-600 dark:text-sky-400">Salvando ordem…</div>

        <!-- Mobile breadcrumb -->
        <div v-if="mobileStep !== 'sections'" class="mb-2 flex shrink-0 items-center gap-2 lg:hidden">
            <button type="button" class="inline-flex items-center gap-1 text-xs text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200" @click="mobileBack">
                <ChevronLeft class="h-4 w-4" /> Voltar
            </button>
            <span class="truncate text-xs text-zinc-600 dark:text-zinc-400">
                <template v-if="mobileStep === 'modules'">{{ selectedSection?.title }}</template>
                <template v-else>{{ selectedSection?.title }} › {{ selectedModule?.title }}</template>
            </span>
        </div>

        <div class="flex min-h-0 min-w-0 flex-1 gap-3 overflow-hidden lg:gap-4">
            <!-- Coluna 1: Seções -->
            <div :class="columnClass('sections')">
                <div class="border-b border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Seções</p>
                </div>
                <Draggable
                    v-model="sections"
                    tag="div"
                    :component-data="{ class: 'min-h-0 flex-1 overflow-y-auto p-2 space-y-1' }"
                    item-key="id"
                    handle=".mb-drag-handle--section"
                    :animation="160"
                    ghost-class="opacity-60"
                    :disabled="memberReorderSaving"
                    @end="emit('sections-reorder-end')"
                >
                    <template #item="{ element: section }">
                        <div
                            :class="[
                                'rounded-lg border transition',
                                selectedSectionId === section.id
                                    ? 'border-sky-500/50 bg-sky-500/5 dark:bg-sky-500/10'
                                    : 'border-transparent hover:bg-zinc-50 dark:hover:bg-zinc-800/50',
                            ]"
                        >
                            <template v-if="editingSectionId === section.id">
                                <div class="space-y-2 p-2">
                                    <input
                                        :value="editingSectionTitle"
                                        type="text"
                                        :class="inputClass"
                                        class="w-full !py-1.5 !text-sm"
                                        @input="emit('update:editingSectionTitle', $event.target.value)"
                                        @keydown.enter="emit('save-section')"
                                    />
                                    <div class="flex gap-2">
                                        <button
                                            type="button"
                                            :class="editingSectionCoverMode === 'vertical' ? 'ring-2 ring-sky-500' : ''"
                                            class="rounded border border-zinc-200 px-2 py-1 text-[10px] dark:border-zinc-600"
                                            @click="emit('update:editingSectionCoverMode', 'vertical')"
                                        >Vertical</button>
                                        <button
                                            type="button"
                                            :class="editingSectionCoverMode === 'horizontal' ? 'ring-2 ring-sky-500' : ''"
                                            class="rounded border border-zinc-200 px-2 py-1 text-[10px] dark:border-zinc-600"
                                            @click="emit('update:editingSectionCoverMode', 'horizontal')"
                                        >Banner</button>
                                    </div>
                                    <div class="flex gap-1">
                                        <Button size="sm" class="!py-1 !text-xs" @click="emit('save-section')">Ok</Button>
                                        <Button size="sm" variant="ghost" class="!py-1 !text-xs" @click="emit('cancel-section-edit')">Cancelar</Button>
                                    </div>
                                </div>
                            </template>
                            <div v-else class="flex items-center gap-1 p-1.5">
                                <button type="button" class="mb-drag-handle--section shrink-0 cursor-grab rounded p-0.5 text-zinc-400" @click.stop>
                                    <GripVertical class="h-3.5 w-3.5" />
                                </button>
                                <button type="button" class="min-w-0 flex-1 text-left" @click="selectSection(section)">
                                    <span class="block truncate text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ section.title }}</span>
                                    <span class="text-[10px] text-zinc-500">{{ sectionTypeLabel(section.section_type ?? 'courses') }}</span>
                                </button>
                                <button type="button" class="rounded p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-700" @click.stop="emit('edit-section', section)">
                                    <Pencil class="h-3 w-3" />
                                </button>
                                <button type="button" class="rounded p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-700" title="Duplicar seção" @click.stop="emit('duplicate-section', section.id)">
                                    <Copy class="h-3 w-3" />
                                </button>
                                <button type="button" class="rounded p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30" @click.stop="emit('delete-section', section.id)">
                                    <Trash2 class="h-3 w-3" />
                                </button>
                            </div>
                        </div>
                    </template>
                </Draggable>
                <p v-if="!sections.length" class="p-4 text-center text-xs text-zinc-500">Nenhuma seção.</p>
            </div>

            <!-- Coluna 2: Módulos -->
            <div :class="columnClass('modules')">
                <div class="flex items-center justify-between gap-2 border-b border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <p class="truncate text-xs font-semibold uppercase tracking-wide text-zinc-500">Módulos</p>
                    <Button
                        v-if="selectedSection"
                        size="sm"
                        variant="outline"
                        class="!py-0.5 !text-[10px]"
                        @click="emit('open-module-modal', selectedSection.id)"
                    >
                        <Plus class="mr-1 h-3 w-3" /> Módulo
                    </Button>
                </div>
                <div v-if="!selectedSection" class="flex flex-1 items-center justify-center p-4 text-xs text-zinc-500">
                    Selecione uma seção.
                </div>
                <Draggable
                    v-else
                    v-model="selectedSection.modules"
                    tag="div"
                    :component-data="{ class: 'min-h-0 flex-1 overflow-y-auto p-2 space-y-1' }"
                    item-key="id"
                    handle=".mb-drag-handle--module"
                    :animation="160"
                    ghost-class="opacity-60"
                    :disabled="memberReorderSaving"
                    @end="emit('modules-reorder-end', selectedSection.id)"
                >
                    <template #item="{ element: mod }">
                        <div
                            :class="[
                                'flex items-center gap-2 rounded-lg border p-2 transition',
                                selectedModuleId === mod.id
                                    ? 'border-sky-500/50 bg-sky-500/5 dark:bg-sky-500/10'
                                    : 'border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800/50',
                            ]"
                        >
                            <button type="button" class="mb-drag-handle--module shrink-0 cursor-grab text-zinc-400" @click.stop>
                                <GripVertical class="h-3.5 w-3.5" />
                            </button>
                            <button type="button" class="flex min-w-0 flex-1 items-center gap-2 text-left" @click="selectModule(mod)">
                                <div class="h-10 w-10 shrink-0 overflow-hidden rounded-md bg-zinc-200 dark:bg-zinc-700">
                                    <img v-if="mod.thumbnail" :src="mod.thumbnail" :alt="mod.title" class="h-full w-full object-cover" />
                                    <div v-else class="flex h-full w-full items-center justify-center">
                                        <BookOpen class="h-4 w-4 text-zinc-400" />
                                    </div>
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ mod.title }}</p>
                                    <p v-if="isCoursesSection" class="text-[10px] text-zinc-500">{{ (mod.lessons?.length ?? 0) }} aula(s)</p>
                                </div>
                            </button>
                            <button
                                v-if="isCoursesSection"
                                type="button"
                                class="rounded p-1 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300"
                                title="Editar módulo"
                                @click.stop="openModuleSettings(mod)"
                            >
                                <Pencil class="h-3 w-3" />
                            </button>
                            <button type="button" class="rounded p-1 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300" title="Duplicar módulo" @click.stop="emit('duplicate-module', mod.id)">
                                <Copy class="h-3 w-3" />
                            </button>
                            <button type="button" class="rounded p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30" @click.stop="emit('delete-module', mod.id)">
                                <Trash2 class="h-3 w-3" />
                            </button>
                        </div>
                    </template>
                </Draggable>
                <p v-if="selectedSection && !selectedSection.modules?.length" class="p-4 text-center text-xs text-zinc-500">Nenhum módulo nesta seção.</p>
            </div>

            <!-- Coluna 3: Aulas ou edição de módulo -->
            <div :class="columnClass('lessons')">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                        <template v-if="!isCoursesSection || !selectedModule">Edição do módulo</template>
                        <template v-else-if="column3View === 'lessons'">Aulas</template>
                        <template v-else>Edição do módulo</template>
                    </p>
                    <div v-if="isCoursesSection && selectedModule" class="flex shrink-0 rounded-lg border border-zinc-200 p-0.5 dark:border-zinc-600">
                        <button
                            type="button"
                            :class="column3View === 'lessons'
                                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900'
                                : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'"
                            class="rounded-md px-2 py-0.5 text-[10px] font-medium transition"
                            @click="column3View = 'lessons'"
                        >Aulas</button>
                        <button
                            type="button"
                            :class="column3View === 'module'
                                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900'
                                : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100'"
                            class="rounded-md px-2 py-0.5 text-[10px] font-medium transition"
                            @click="openModuleSettings(selectedModule)"
                        >Módulo</button>
                    </div>
                </div>
                <div class="flex min-h-0 flex-1 flex-col p-3">
                    <MemberBuilderLessonEditor
                        v-if="isCoursesSection && selectedModule && column3View === 'lessons'"
                        v-model:lessons="lessonsForEditor"
                        :module="selectedModule"
                        :lesson-form="lessonForm"
                        :lesson-form-saving="lessonFormSaving"
                        :member-reorder-saving="memberReorderSaving"
                        :input-class="inputClass"
                        :upload-limits="uploadLimits"
                        :lesson-pdf-uploading="lessonPdfUploading"
                        :lesson-support-uploading="lessonSupportUploading"
                        :is-lesson-pdf-content-type="isLessonPdfContentType"
                        :pdf-lesson-file-label="pdfLessonFileLabel"
                        @open-lesson-form="emit('open-lesson-form', $event)"
                        @close-lesson-form="emit('close-lesson-form')"
                        @save-lesson="emit('save-lesson')"
                        @delete-lesson="emit('delete-lesson', $event)"
                        @duplicate-lesson="emit('duplicate-lesson', $event)"
                        @lessons-reorder-end="emit('lessons-reorder-end')"
                        @pick-lesson-pdf="emit('pick-lesson-pdf')"
                        @pick-support-pdf="emit('pick-support-pdf')"
                        @clear-lesson-pdf="emit('clear-lesson-pdf')"
                        @remove-lesson-pdf-at="emit('remove-lesson-pdf-at', $event)"
                        @clear-support-files="emit('clear-support-files')"
                        @remove-support-file-at="emit('remove-support-file-at', $event)"
                        @add-useful-link="emit('add-useful-link')"
                        @remove-useful-link-at="emit('remove-useful-link-at', $event)"
                    />
                    <MemberBuilderModuleEditor
                        v-else-if="selectedModule && selectedSection && (!isCoursesSection || column3View === 'module')"
                        :section="selectedSection"
                        :module="selectedModule"
                        :input-class="inputClass"
                        :upload-limits="uploadLimits"
                        :tenant-products="tenantProducts"
                        :product-modules="productModulesForPrereq"
                        :editing-title="editingModuleTitle"
                        :editing-show-title-on-cover="editingModuleShowTitleOnCover"
                        :editing-related-product-id="editingModuleRelatedProductId"
                        :editing-access-type="editingModuleAccessType"
                        :editing-external-url="editingModuleExternalUrl"
                        :editing-release-mode="editingModuleReleaseMode"
                        :editing-release-after-days="editingModuleReleaseAfterDays"
                        :editing-release-at-date="editingModuleReleaseAtDate"
                        :editing-release-progress-percent="editingModuleReleaseProgressPercent"
                        :editing-release-required-module-ids="editingModuleReleaseRequiredModuleIds"
                        :editing-access-duration-days="editingModuleAccessDurationDays"
                        :editing-thumbnail="editingModuleThumbnail"
                        :thumbnail-uploading="moduleThumbnailUploading"
                        :saving="moduleFormSaving"
                        @update:editing-title="emit('update:editingModuleTitle', $event)"
                        @update:editing-show-title-on-cover="emit('update:editingModuleShowTitleOnCover', $event)"
                        @update:editing-related-product-id="emit('update:editingModuleRelatedProductId', $event)"
                        @update:editing-access-type="emit('update:editingModuleAccessType', $event)"
                        @update:editing-external-url="emit('update:editingModuleExternalUrl', $event)"
                        @update:editing-release-mode="emit('update:editingModuleReleaseMode', $event)"
                        @update:editing-release-after-days="emit('update:editingModuleReleaseAfterDays', $event)"
                        @update:editing-release-at-date="emit('update:editingModuleReleaseAtDate', $event)"
                        @update:editing-release-progress-percent="emit('update:editingModuleReleaseProgressPercent', $event)"
                        @update:editing-release-required-module-ids="emit('update:editingModuleReleaseRequiredModuleIds', $event)"
                        @update:editing-access-duration-days="emit('update:editingModuleAccessDurationDays', $event)"
                        @save="emit('save-module')"
                        @pick-thumbnail="emit('pick-module-thumbnail')"
                        @remove-thumbnail="emit('remove-module-thumbnail')"
                        @set-show-title-on-cover="emit('set-module-show-title-on-cover', $event)"
                    />
                    <div v-else class="flex flex-1 items-center justify-center text-xs text-zinc-500">
                        Selecione um módulo.
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
