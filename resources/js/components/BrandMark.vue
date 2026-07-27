<script setup>
import { useId } from 'vue'

// O símbolo "io" da Sistema IO. Fica inline (e não como <img>) para herdar as
// animações da tela de carregamento e não custar um request extra em cada tela.
// Os gradientes ganham id único porque a marca aparece mais de uma vez por página.
defineProps({
    size: { type: [Number, String], default: 40 },
})

const uid = useId()
</script>

<template>
    <svg
        :width="size"
        :height="Number(size) * 1.293"
        viewBox="0 0 116 150"
        role="img"
        aria-label="Sistema IO"
        class="shrink-0 overflow-visible"
    >
        <defs>
            <radialGradient :id="`${uid}-dot`" cx="35%" cy="28%" r="78%">
                <stop offset="0%" stop-color="#c8e04e" />
                <stop offset="55%" stop-color="#8cc63f" />
                <stop offset="100%" stop-color="#3f9a2a" />
            </radialGradient>
            <linearGradient :id="`${uid}-stem`" x1="0" y1="0" x2="0.4" y2="1">
                <stop offset="0%" stop-color="#0b4c93" />
                <stop offset="100%" stop-color="#1a7ec2" />
            </linearGradient>
            <linearGradient :id="`${uid}-ring`" x1="0.12" y1="0" x2="0.88" y2="1">
                <stop offset="0%" stop-color="#0b4c93" />
                <stop offset="40%" stop-color="#1b86c4" />
                <stop offset="72%" stop-color="#3fa63c" />
                <stop offset="100%" stop-color="#7ac143" />
            </linearGradient>
        </defs>

        <circle class="io-dot" cx="25" cy="20" r="19" :fill="`url(#${uid}-dot)`" />
        <circle class="io-ring" cx="68" cy="100" r="32" fill="none" :stroke="`url(#${uid}-ring)`" stroke-width="30" />
        <rect class="io-stem" x="10" y="46" width="30" height="101" rx="3" :fill="`url(#${uid}-stem)`" />
    </svg>
</template>
