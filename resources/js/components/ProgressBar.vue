<script setup>
defineProps({
    value: { type: Number, default: 0 },
    label: { type: String, default: '' },
    tone: { type: String, default: 'from-sky-400 to-indigo-500' },
    height: { type: String, default: 'h-4' },
})
</script>

<template>
    <div class="w-full">
        <div class="rounded-full bg-slate-800/80 overflow-hidden ring-1 ring-white/10" :class="height">
            <div
                class="h-full rounded-full bg-gradient-to-r transition-all duration-700 ease-out"
                :class="tone"
                :style="{ width: Math.min(100, Math.max(0, value)) + '%' }"
            />
        </div>
        <p v-if="label" class="mt-2 text-center text-slate-300 font-semibold">{{ label }}</p>
    </div>
</template>
