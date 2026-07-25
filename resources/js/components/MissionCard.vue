<script setup>
defineProps({
    mission: { type: Object, required: true },
    compact: { type: Boolean, default: false },
})
</script>

<template>
    <!-- A missão fica visível o tempo todo na Fase 1: é ela que cria o viés
         que o placar por grupo vai revelar na virada de fase. -->
    <div
        class="rounded-2xl ring-2 backdrop-blur transition-all"
        :class="compact ? 'px-3 py-2' : 'px-5 py-4'"
        :style="{
            borderColor: mission.color,
            boxShadow: `0 0 0 2px ${mission.color}`,
            background: `linear-gradient(135deg, ${mission.color}22, transparent)`,
        }"
    >
        <div class="flex items-center gap-2">
            <span :class="compact ? 'text-lg' : 'text-2xl'">{{ mission.icon }}</span>
            <div class="min-w-0">
                <p class="text-[10px] font-black uppercase tracking-widest" :style="{ color: mission.color }">
                    Sua missão
                </p>
                <p class="font-black text-white leading-tight" :class="compact ? 'text-sm' : 'text-lg'">
                    {{ mission.name }}
                </p>
            </div>
        </div>
        <p v-if="!compact" class="mt-2 text-sm text-slate-300 italic leading-relaxed">
            “{{ mission.statement }}”
        </p>
    </div>
</template>
