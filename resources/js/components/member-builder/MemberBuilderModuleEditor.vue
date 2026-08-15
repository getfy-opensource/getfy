<script setup>
import Button from '@/components/ui/Button.vue';
import Toggle from '@/components/ui/Toggle.vue';
import { ShoppingBag, ExternalLink } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps({
    section: { type: Object, required: true },
    module: { type: Object, required: true },
    inputClass: { type: String, required: true },
    uploadLimits: { type: Object, required: true },
    tenantProducts: { type: Array, default: () => [] },
    productModules: { type: Array, default: () => [] },
    editingTitle: { type: String, default: '' },
    editingShowTitleOnCover: { type: Boolean, default: true },
    editingRelatedProductId: { default: null },
    editingAccessType: { type: String, default: 'paid' },
    editingExternalUrl: { type: String, default: '' },
    editingReleaseMode: { type: String, default: 'none' },
    editingReleaseAfterDays: { type: String, default: '' },
    editingReleaseAtDate: { type: String, default: '' },
    editingReleaseProgressPercent: { type: String, default: '' },
    editingReleaseRequiredModuleIds: { type: Array, default: () => [] },
    editingAccessDurationDays: { type: String, default: '' },
    editingThumbnail: { type: String, default: null },
    thumbnailUploading: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
});

const emit = defineEmits([
    'update:editingTitle',
    'update:editingShowTitleOnCover',
    'update:editingRelatedProductId',
    'update:editingAccessType',
    'update:editingExternalUrl',
    'update:editingReleaseMode',
    'update:editingReleaseAfterDays',
    'update:editingReleaseAtDate',
    'update:editingReleaseProgressPercent',
    'update:editingReleaseRequiredModuleIds',
    'update:editingAccessDurationDays',
    'save',
    'pick-thumbnail',
    'remove-thumbnail',
    'set-show-title-on-cover',
]);

const prerequisiteOptions = computed(() => {
    const selfId = props.module?.id;
    return (props.productModules || []).filter((m) => m.id !== selfId);
});

function isRequiredSelected(id) {
    return (props.editingReleaseRequiredModuleIds || []).map(Number).includes(Number(id));
}

function toggleRequiredModule(id) {
    const n = Number(id);
    const current = (props.editingReleaseRequiredModuleIds || []).map(Number);
    const next = current.includes(n) ? current.filter((x) => x !== n) : [...current, n];
    emit('update:editingReleaseRequiredModuleIds', next);
}
</script>

<template>
    <div class="min-h-0 flex-1 space-y-3 overflow-y-auto">
        <p class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Editar módulo</p>
        <div>
            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Título</label>
            <input
                :value="editingTitle"
                type="text"
                :class="inputClass"
                class="w-full"
                @input="emit('update:editingTitle', $event.target.value)"
                @keydown.enter="emit('save')"
            />
        </div>

        <template v-if="(section.section_type ?? 'courses') === 'products'">
            <div>
                <label class="mb-1 flex items-center gap-1 text-xs font-medium text-zinc-600 dark:text-zinc-400">
                    <ShoppingBag class="h-3.5 w-3.5" /> Produto relacionado
                </label>
                <select
                    :value="editingRelatedProductId"
                    :class="inputClass"
                    class="w-full"
                    @change="emit('update:editingRelatedProductId', $event.target.value ? Number($event.target.value) : null)"
                >
                    <option :value="null">Selecione o produto</option>
                    <option v-for="p in tenantProducts" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Acesso</label>
                <select
                    :value="editingAccessType"
                    :class="inputClass"
                    class="w-full"
                    @change="emit('update:editingAccessType', $event.target.value)"
                >
                    <option value="paid">Pago</option>
                    <option value="free">Liberado</option>
                </select>
            </div>
        </template>

        <template v-else-if="(section.section_type ?? 'courses') === 'external_links'">
            <div>
                <label class="mb-1 flex items-center gap-1 text-xs font-medium text-zinc-600 dark:text-zinc-400">
                    <ExternalLink class="h-3.5 w-3.5" /> URL
                </label>
                <input
                    :value="editingExternalUrl"
                    type="url"
                    :class="inputClass"
                    class="w-full"
                    placeholder="https://..."
                    @input="emit('update:editingExternalUrl', $event.target.value)"
                />
            </div>
        </template>

        <template v-else>
            <div>
                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Liberação</label>
                <div class="grid gap-2">
                    <select
                        :value="editingReleaseMode"
                        :class="inputClass"
                        class="w-full"
                        @change="emit('update:editingReleaseMode', $event.target.value)"
                    >
                        <option value="none">Imediata</option>
                        <option value="days">Após X dias</option>
                        <option value="date">Na data</option>
                        <option value="progress">Ao atingir X% do curso</option>
                        <option value="modules">Ao concluir módulos</option>
                    </select>
                    <input
                        v-if="editingReleaseMode === 'days'"
                        :value="editingReleaseAfterDays"
                        type="number"
                        min="1"
                        :class="inputClass"
                        class="w-full"
                        @input="emit('update:editingReleaseAfterDays', $event.target.value)"
                    />
                    <input
                        v-else-if="editingReleaseMode === 'date'"
                        :value="editingReleaseAtDate"
                        type="date"
                        :class="inputClass"
                        class="w-full"
                        @input="emit('update:editingReleaseAtDate', $event.target.value)"
                    />
                    <div v-else-if="editingReleaseMode === 'progress'" class="space-y-1">
                        <input
                            :value="editingReleaseProgressPercent"
                            type="number"
                            min="1"
                            max="100"
                            :class="inputClass"
                            class="w-full"
                            placeholder="Ex.: 40"
                            @input="emit('update:editingReleaseProgressPercent', $event.target.value)"
                        />
                        <p class="text-[10px] text-zinc-500 dark:text-zinc-400">
                            Percentual de aulas concluídas no curso (1–100).
                        </p>
                    </div>
                    <div v-else-if="editingReleaseMode === 'modules'" class="space-y-1">
                        <p class="text-[10px] text-zinc-500 dark:text-zinc-400">
                            O aluno precisa concluir 100% das aulas de todos os módulos marcados.
                        </p>
                        <div
                            v-if="prerequisiteOptions.length"
                            class="max-h-40 space-y-1 overflow-y-auto rounded-lg border border-zinc-200 p-2 dark:border-zinc-600"
                        >
                            <label
                                v-for="opt in prerequisiteOptions"
                                :key="opt.id"
                                class="flex cursor-pointer items-start gap-2 rounded px-1 py-0.5 text-xs text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800/50"
                            >
                                <input
                                    type="checkbox"
                                    class="mt-0.5"
                                    :checked="isRequiredSelected(opt.id)"
                                    @change="toggleRequiredModule(opt.id)"
                                />
                                <span>
                                    <span class="font-medium">{{ opt.title }}</span>
                                    <span v-if="opt.section_title" class="block text-[10px] text-zinc-500">{{ opt.section_title }}</span>
                                </span>
                            </label>
                        </div>
                        <p v-else class="text-[10px] text-zinc-500">Nenhum outro módulo neste produto.</p>
                    </div>
                </div>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Duração do acesso</label>
                <input
                    :value="editingAccessDurationDays"
                    type="number"
                    min="1"
                    max="3650"
                    :class="inputClass"
                    class="w-full"
                    placeholder="Ilimitado"
                    @input="emit('update:editingAccessDurationDays', $event.target.value)"
                />
                <p class="mt-1 text-[10px] text-zinc-500 dark:text-zinc-400">
                    Em dias após a compra. Deixe vazio para acesso ilimitado.
                </p>
            </div>
        </template>

        <Toggle
            :model-value="editingShowTitleOnCover"
            label="Mostrar título na capa"
            @update:model-value="(v) => { emit('update:editingShowTitleOnCover', v); emit('set-show-title-on-cover', v); }"
        />

        <div>
            <p class="mb-2 text-xs font-medium text-zinc-600 dark:text-zinc-400">Capa do módulo</p>
            <div v-if="editingThumbnail" class="flex items-center gap-3">
                <div
                    :class="section.cover_mode === 'horizontal' ? 'aspect-video w-24' : 'aspect-[2/3] h-20 w-14'"
                    class="shrink-0 overflow-hidden rounded-lg"
                >
                    <img :src="editingThumbnail" alt="Capa" class="h-full w-full object-cover" />
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button type="button" size="sm" variant="outline" :disabled="thumbnailUploading" @click="emit('pick-thumbnail')">Trocar</Button>
                    <Button type="button" size="sm" variant="ghost" class="text-red-600" :disabled="thumbnailUploading" @click="emit('remove-thumbnail')">Remover</Button>
                </div>
            </div>
            <Button v-else type="button" size="sm" variant="outline" :disabled="thumbnailUploading" @click="emit('pick-thumbnail')">
                {{ thumbnailUploading ? 'Enviando…' : 'Enviar capa' }}
            </Button>
            <p class="mt-1 text-[10px] text-zinc-500">Máx. {{ uploadLimits.image_max_mb }} MB.</p>
        </div>

        <Button size="sm" class="w-full" :disabled="saving" @click="emit('save')">
            {{ saving ? 'Salvando…' : 'Salvar módulo' }}
        </Button>
    </div>
</template>
