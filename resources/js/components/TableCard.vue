<script setup>
import { computed } from 'vue'
import PixelAvatar from './PixelAvatar.vue'

const props = defineProps({
    table: { type: Object, required: true },
    // 'individual' (phase 1) or 'consensus' (phase 2)
    mode: { type: String, default: 'individual' },
    compact: { type: Boolean, default: false },
    highlight: { type: Boolean, default: false },
})

// grey = idle, blue = answering, amber = time running out,
// green = finished the block, red = table went offline
const STATES = {
    idle: { ring: 'ring-slate-600/60', glow: '', bar: 'bg-slate-500', chip: 'text-slate-300' },
    discussing: { ring: 'ring-sky-400/80', glow: 'shadow-sky-500/30', bar: 'bg-sky-400', chip: 'text-sky-300' },
    warning: { ring: 'ring-amber-400/90', glow: 'shadow-amber-500/40', bar: 'bg-amber-400', chip: 'text-amber-300' },
    done: { ring: 'ring-emerald-400', glow: 'shadow-emerald-500/50', bar: 'bg-emerald-400', chip: 'text-emerald-300' },
    offline: { ring: 'ring-rose-500/80', glow: 'shadow-rose-500/40', bar: 'bg-rose-500', chip: 'text-rose-300' },
}

const state = computed(() => STATES[props.table.state] ?? STATES.idle)
const done = computed(() => props.table.state === 'done')
const individual = computed(() => props.mode === 'individual')
const avatarSize = computed(() => (props.compact ? 18 : 26))

// how far this table is through the block of questions
const barWidth = computed(() => Math.min(100, props.table.percent ?? 0))

const label = computed(() => {
    if (props.table.state === 'offline') return props.compact ? 'Offline' : 'Sem conexão'
    if (done.value) return individual.value ? 'Mesa completa' : 'Consenso completo'
    if (props.table.state === 'idle') return 'Aguardando'

    return `${props.table.answered ?? 0}/${props.table.expected ?? 0} respostas`
})
</script>

<template>
    <div
        class="relative rounded-2xl bg-slate-900/80 backdrop-blur ring-2 p-3 shadow-xl transition-all duration-500"
        :class="[state.ring, state.glow, highlight ? 'scale-105 z-10' : '', done ? 'animate-pop animate-done-glow' : '']"
    >
        <!-- destaque de time completo: selo que salta ao fechar o voto -->
        <div
            v-if="done"
            class="absolute -top-2.5 -right-2.5 z-20 grid place-items-center w-7 h-7 rounded-full bg-emerald-400 text-emerald-950 text-base font-black shadow-lg shadow-emerald-500/50 ring-2 ring-emerald-200 animate-pop"
            :title="individual ? 'Mesa completa' : 'Consenso completo'"
        >✓</div>
        <div class="flex items-center gap-2">
            <span class="text-lg leading-none" :style="{ filter: 'drop-shadow(0 0 6px ' + table.color + ')' }">
                {{ table.icon }}
            </span>
            <span class="font-bold text-white truncate" :class="compact ? 'text-xs' : 'text-sm'">
                {{ table.name }}
            </span>
            <span class="ml-auto text-[10px] font-mono text-slate-400">
                {{ table.online_count }}/{{ table.participants_count }}
            </span>
        </div>

        <div class="mt-2 flex flex-wrap" :class="compact ? 'gap-0.5' : 'gap-1'">
            <!-- phase 1: ring whoever finished their own block.
                 phase 2: crown the table's representative. -->
            <div v-for="person in table.participants" :key="person.id" class="relative">
                <PixelAvatar
                    :seed="person.avatar_seed"
                    :gender="person.gender"
                    :size="avatarSize"
                    :ring="individual ? person.finished : person.is_representative"
                    :dim="!person.online"
                />
                <span
                    v-if="!individual && person.is_representative"
                    class="absolute -top-1.5 -right-1 text-[10px] leading-none"
                    title="Representante da mesa"
                >👑</span>
            </div>
            <div
                v-if="!table.participants.length"
                class="text-[10px] text-slate-500 italic py-1"
            >
                mesa vazia
            </div>
        </div>

        <div class="mt-2 h-1.5 rounded-full bg-slate-800 overflow-hidden">
            <div
                class="h-full rounded-full transition-all duration-700 ease-out"
                :class="state.bar"
                :style="{ width: barWidth + '%' }"
            />
        </div>

        <div class="mt-1.5 flex items-center gap-1 text-[11px] font-semibold" :class="state.chip">
            <span v-if="done">✔</span>
            <span class="truncate">{{ label }}</span>
        </div>
    </div>
</template>
