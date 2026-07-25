<script setup>
defineProps({
    // [{ label, value, sublabel, color, percent }]
    rows: { type: Array, required: true },
    compact: { type: Boolean, default: false },
    suffix: { type: String, default: '' },
})
</script>

<template>
    <div :class="compact ? 'space-y-2' : 'space-y-3'">
        <TransitionGroup name="bar" tag="div" :class="compact ? 'space-y-2' : 'space-y-3'">
            <div v-for="(row, i) in rows" :key="row.key ?? row.label">
                <div class="flex items-end justify-between mb-1">
                    <span class="font-bold text-white flex items-center gap-2" :class="compact ? 'text-sm' : 'text-lg'">
                        <span v-if="i === 0" class="text-base">🏆</span>
                        <span v-if="row.icon">{{ row.icon }}</span>
                        <span class="truncate">{{ row.label }}</span>
                    </span>
                    <span class="font-black tabular-nums text-white" :class="compact ? 'text-base' : 'text-2xl'">
                        {{ row.value }}{{ suffix }}
                    </span>
                </div>
                <div class="rounded-lg bg-slate-800/70 overflow-hidden ring-1 ring-white/10" :class="compact ? 'h-4' : 'h-7'">
                    <div
                        class="h-full rounded-lg transition-all duration-1000 ease-out flex items-center justify-end pr-2"
                        :style="{
                            width: Math.max(2, row.percent) + '%',
                            background: `linear-gradient(90deg, ${row.color}bb, ${row.color})`,
                            boxShadow: `0 0 20px ${row.color}55`,
                        }"
                    >
                        <span v-if="row.sublabel" class="text-[10px] font-bold text-white/90 whitespace-nowrap">
                            {{ row.sublabel }}
                        </span>
                    </div>
                </div>
            </div>
        </TransitionGroup>
    </div>
</template>
