<script setup>
import { computed } from 'vue'
import { buildAvatar } from '../lib/avatar'

const props = defineProps({
    seed: { type: [String, Number], required: true },
    gender: { type: String, default: 'custom' },
    size: { type: Number, default: 48 },
    ring: { type: Boolean, default: false },
    dim: { type: Boolean, default: false },
})

const sprite = computed(() => buildAvatar(props.seed, props.gender))
</script>

<template>
    <svg
        :width="size"
        :height="size"
        :viewBox="`0 0 ${sprite.size} ${sprite.size}`"
        shape-rendering="crispEdges"
        class="rounded-lg transition-all duration-300"
        :class="[
            ring ? 'ring-4 ring-emerald-400 scale-110 shadow-lg shadow-emerald-400/50' : '',
            dim ? 'opacity-35 saturate-0' : 'opacity-100',
        ]"
    >
        <rect :width="sprite.size" :height="sprite.size" :fill="sprite.background" />
        <rect
            v-for="(pixel, i) in sprite.pixels"
            :key="i"
            :x="pixel.x"
            :y="pixel.y"
            width="1"
            height="1"
            :fill="pixel.fill"
        />
    </svg>
</template>
