<script setup>
import { onMounted, ref } from 'vue'
import QRCode from 'qrcode'

const props = defineProps({
    size: { type: Number, default: 220 },
})

const src = ref('')
// the join screen lives at the app root — whatever host the room is using
const url = window.location.origin + '/'

onMounted(async () => {
    src.value = await QRCode.toDataURL(url, {
        width: props.size * 2,
        margin: 1,
        color: { dark: '#0f172a', light: '#ffffff' },
    })
})
</script>

<template>
    <div class="flex flex-col items-center gap-3">
        <img
            v-if="src"
            :src="src"
            alt="QR Code para entrar"
            class="rounded-2xl ring-4 ring-white/90 shadow-2xl shadow-indigo-500/20"
            :width="size"
            :height="size"
        >
        <p class="text-xs font-mono text-slate-400">{{ url }}</p>
    </div>
</template>
