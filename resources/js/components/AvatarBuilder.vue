<script setup>
import { computed } from 'vue'
import PixelAvatar from './PixelAvatar.vue'
import { CLOTHES, HAIRS, SKINS, STYLES, encodeAvatar } from '../lib/avatar'

// o avatar é montado, não sorteado: estes índices são codificados na seed
const parts = defineModel('parts', { type: Object, required: true })
const gender = defineModel('gender', { type: String, required: true })

const seed = computed(() => encodeAvatar(parts.value))

const ROWS = [
    { key: 'skin', label: 'Tom de pele', colors: SKINS },
    { key: 'hair', label: 'Cor do cabelo', colors: HAIRS },
    { key: 'clothes', label: 'Roupa', colors: CLOTHES },
]
</script>

<template>
    <!-- monte o avatar: sexo (sprite), pele, cabelo, roupa -->
    <div class="space-y-1.5">
        <label class="text-xs font-bold uppercase tracking-widest text-slate-400">Sexo</label>
        <div class="grid grid-cols-2 gap-2">
            <button
                v-for="option in STYLES"
                :key="option.key"
                class="rounded-2xl p-2 ring-2 transition-all duration-200 flex flex-col items-center gap-1"
                :class="gender === option.key
                    ? 'ring-indigo-400 bg-indigo-500/20'
                    : 'ring-white/10 bg-slate-800/60 hover:ring-white/25'"
                @click="gender = option.key"
            >
                <PixelAvatar :seed="seed" :gender="option.key" :size="40" />
                <span class="text-[10px] font-semibold text-slate-200">{{ option.label }}</span>
            </button>
        </div>
    </div>

    <div v-for="row in ROWS" :key="row.key" class="space-y-1.5">
        <label class="text-xs font-bold uppercase tracking-widest text-slate-400">{{ row.label }}</label>
        <div class="flex flex-wrap gap-2">
            <button
                v-for="(color, i) in row.colors"
                :key="color"
                class="w-8 h-8 rounded-full ring-2 transition-all duration-200 active:scale-90"
                :class="parts[row.key] === i
                    ? 'ring-white scale-110 shadow-lg'
                    : 'ring-white/15 hover:ring-white/40'"
                :style="{ backgroundColor: color }"
                :aria-label="`${row.label} ${i + 1}`"
                @click="parts = { ...parts, [row.key]: i }"
            />
        </div>
    </div>
</template>
