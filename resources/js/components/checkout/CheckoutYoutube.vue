<script setup>
import { computed } from 'vue';
import { YOUTUBE_IFRAME_ALLOW, youtubeEmbedSrcFromVideoId } from '@/lib/youtubeEmbed';

const props = defineProps({
    url: { type: String, default: '' },
});

const videoId = computed(() => {
    if (!props.url || typeof props.url !== 'string') return null;
    const u = props.url.trim();
    const m = u.match(/(?:youtube(?:-nocookie)?\.com\/(?:[^/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?/\s]{11})/i);
    const id = m ? m[1] : null;
    return id && /^[a-zA-Z0-9_-]{11}$/.test(id) ? id : null;
});

const embedSrc = computed(() => (videoId.value ? youtubeEmbedSrcFromVideoId(videoId.value) : ''));
</script>

<template>
    <div v-if="videoId" class="mb-8" data-checkout="youtube">
        <div class="aspect-video overflow-hidden rounded-2xl shadow-xl ring-1 ring-black/5">
            <iframe
                :src="embedSrc"
                title="Vídeo"
                class="h-full w-full"
                :allow="YOUTUBE_IFRAME_ALLOW"
                allowfullscreen
            />
        </div>
    </div>
</template>
