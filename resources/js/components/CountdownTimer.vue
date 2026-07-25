<script setup>
import { computed } from 'vue'

const props = defineProps({
    remaining: { type: Number, default: 0 },
    duration: { type: Number, default: 1 },
    size: { type: Number, default: 160 },
})

// The clock lives on the server; this only draws whatever the poll returned.
const fraction = computed(() => Math.max(0, Math.min(1, props.remaining / Math.max(1, props.duration))))
const label = computed(() => {
    const s = Math.max(0, props.remaining)
    return s >= 60 ? `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}` : String(s)
})
const urgent = computed(() => props.remaining <= 10 && props.remaining > 0)
const stroke = computed(() => (urgent.value ? '#f43f5e' : fraction.value <= 0.25 ? '#fbbf24' : '#38bdf8'))
const circumference = 2 * Math.PI * 45
</script>

<template>
    <div class="relative grid place-items-center" :style="{ width: size + 'px', height: size + 'px' }">
        <svg :width="size" :height="size" viewBox="0 0 100 100" class="-rotate-90">
            <circle cx="50" cy="50" r="45" fill="none" stroke="rgba(148,163,184,0.18)" stroke-width="8" />
            <circle
                cx="50" cy="50" r="45" fill="none"
                :stroke="stroke"
                stroke-width="8"
                stroke-linecap="round"
                :stroke-dasharray="circumference"
                :stroke-dashoffset="circumference * (1 - fraction)"
                class="transition-[stroke-dashoffset,stroke] duration-1000 ease-linear"
                :style="{ filter: `drop-shadow(0 0 8px ${stroke})` }"
            />
        </svg>
        <div
            class="absolute font-black tabular-nums text-white"
            :class="urgent ? 'animate-pulse-fast' : ''"
            :style="{ fontSize: size * 0.28 + 'px' }"
        >
            {{ label }}
        </div>
    </div>
</template>
