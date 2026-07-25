<script setup>
import { ref } from 'vue'
import TableCard from './TableCard.vue'

const props = defineProps({
    tables: { type: Array, default: () => [] },
    mode: { type: String, default: 'individual' },
    compact: { type: Boolean, default: false },
    // master panel only: click to inspect, drag to lay out the room
    interactive: { type: Boolean, default: false },
    editable: { type: Boolean, default: false },
    selectedId: { type: [Number, null], default: null },
})

const emit = defineEmits(['select', 'move'])

const surface = ref(null)
const dragging = ref(null)

function startDrag(table, event) {
    if (!props.editable) return
    dragging.value = table.id
    event.preventDefault()
}

function onMove(event) {
    if (!dragging.value || !surface.value) return

    const rect = surface.value.getBoundingClientRect()
    const point = event.touches?.[0] ?? event
    const x = Math.min(96, Math.max(4, ((point.clientX - rect.left) / rect.width) * 100))
    const y = Math.min(94, Math.max(6, ((point.clientY - rect.top) / rect.height) * 100))

    emit('move', { id: dragging.value, position_x: Number(x.toFixed(2)), position_y: Number(y.toFixed(2)) })
}

function endDrag() {
    dragging.value = null
}
</script>

<template>
    <div
        ref="surface"
        class="relative w-full h-full rounded-3xl bg-slate-950/60 ring-1 ring-white/5 overflow-hidden select-none"
        @mousemove="onMove"
        @mouseup="endDrag"
        @mouseleave="endDrag"
        @touchmove.prevent="onMove"
        @touchend="endDrag"
    >
        <!-- soft stage lighting -->
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top,rgba(99,102,241,0.22),transparent_60%)]" />

        <div class="absolute top-3 left-1/2 -translate-x-1/2 z-20">
            <div class="px-8 py-1.5 rounded-full bg-gradient-to-r from-indigo-500 via-fuchsia-500 to-amber-400 text-white text-xs font-black tracking-[0.3em] shadow-lg shadow-fuchsia-500/30">
                PALCO
            </div>
        </div>

        <div
            v-for="table in tables"
            :key="table.id"
            class="absolute -translate-x-1/2 -translate-y-1/2 transition-[left,top] duration-300"
            :class="[
                compact ? 'w-36' : 'w-52',
                editable ? 'cursor-grab active:cursor-grabbing' : interactive ? 'cursor-pointer' : '',
            ]"
            :style="{ left: table.position_x + '%', top: table.position_y + '%' }"
            @mousedown="startDrag(table, $event)"
            @touchstart="startDrag(table, $event)"
            @click="interactive && emit('select', table)"
        >
            <TableCard
                :table="table"
                :mode="mode"
                :compact="compact"
                :highlight="selectedId === table.id"
            />
        </div>

        <p v-if="!tables.length" class="absolute inset-0 grid place-items-center text-slate-500">
            Nenhuma mesa cadastrada ainda.
        </p>
    </div>
</template>
