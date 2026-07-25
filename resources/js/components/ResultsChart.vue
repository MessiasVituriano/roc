<script setup>
const props = defineProps({
    results: { type: Object, required: true },
    compact: { type: Boolean, default: false },
})

const unitLabel = (unit) => (unit === 'participants' ? 'pessoas responderam' : 'mesas responderam')
</script>

<template>
    <div :class="compact ? 'space-y-2' : 'space-y-4'">
        <TransitionGroup name="bar" tag="div" :class="compact ? 'space-y-3' : 'space-y-4'">
            <div v-for="(option, index) in results.options" :key="option.option_id">
                <div class="flex items-end justify-between mb-1.5">
                    <span class="font-bold text-white flex items-center gap-3" :class="compact ? 'text-base' : 'text-xl md:text-2xl'">
                        <span
                            v-if="index === 0 && option.votes > 0"
                            class="text-2xl animate-bounce-soft"
                        >🏆</span>
                        {{ option.text }}
                    </span>
                    <span class="font-black tabular-nums text-white" :class="compact ? 'text-xl' : 'text-2xl md:text-3xl'">{{ option.percent }}%</span>
                </div>
                <div class="rounded-xl bg-slate-800/70 overflow-hidden ring-1 ring-white/10" :class="compact ? 'h-6' : 'h-8 md:h-10'">
                    <div
                        class="h-full rounded-xl transition-all duration-1000 ease-out flex items-center justify-end pr-3"
                        :style="{
                            width: Math.max(option.percent, option.votes ? 6 : 2) + '%',
                            background: `linear-gradient(90deg, ${option.color}bb, ${option.color})`,
                            boxShadow: `0 0 24px ${option.color}66`,
                        }"
                    >
                        <span class="text-xs font-bold text-white/90 tabular-nums">
                            {{ option.votes }}
                        </span>
                    </div>
                </div>
            </div>
        </TransitionGroup>
        <p class="text-center text-slate-400 text-sm">
            {{ results.total_votes }} {{ unitLabel(results.unit) }}
        </p>
    </div>
</template>

<style scoped>
.bar-move { transition: transform 700ms cubic-bezier(0.2, 0, 0.1, 1); }
</style>
