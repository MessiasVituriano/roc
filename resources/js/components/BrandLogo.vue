<script setup>
import { computed } from 'vue'
import BrandMark from './BrandMark.vue'

/**
 * A assinatura da marca: símbolo + "Sistema IO" + assinatura "Inteligência em
 * Ocupação". É o mesmo bloco em todas as telas — só muda o tamanho e, no
 * cabeçalho apertado do celular, a assinatura some.
 */
const props = defineProps({
    size: { type: String, default: 'md' }, // xs | sm | md | lg | xl
    tagline: { type: Boolean, default: true },
    wordmark: { type: Boolean, default: true },
    stacked: { type: Boolean, default: false },
})

const SIZES = {
    xs: { mark: 18, word: 'text-sm', tag: 'text-[8px] tracking-[0.22em]', gap: 'gap-2' },
    sm: { mark: 24, word: 'text-lg', tag: 'text-[9px] tracking-[0.24em]', gap: 'gap-2.5' },
    md: { mark: 32, word: 'text-2xl', tag: 'text-[10px] tracking-[0.28em]', gap: 'gap-3' },
    lg: { mark: 48, word: 'text-4xl', tag: 'text-xs tracking-[0.3em]', gap: 'gap-4' },
    xl: { mark: 72, word: 'text-6xl', tag: 'text-sm tracking-[0.35em]', gap: 'gap-5' },
}

const s = computed(() => SIZES[props.size] ?? SIZES.md)
</script>

<template>
    <div
        class="flex select-none"
        :class="[
            s.gap,
            stacked ? 'flex-col items-center text-center' : 'flex-row items-center',
        ]"
    >
        <BrandMark :size="s.mark" class="brand-glow" />

        <div v-if="wordmark" :class="stacked ? '' : 'leading-none'">
            <p
                class="font-black leading-none bg-gradient-to-r from-sky-300 via-cyan-200 to-lime-300 bg-clip-text text-transparent"
                :class="s.word"
            >
                Sistema IO
            </p>
            <p
                v-if="tagline"
                class="mt-1 font-bold uppercase text-emerald-300/70"
                :class="s.tag"
            >
                Inteligência em Ocupação
            </p>
        </div>
    </div>
</template>
