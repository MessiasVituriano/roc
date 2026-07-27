<script setup>
import BrandLogo from './BrandLogo.vue'

/**
 * Tela de carregamento padrão: enquanto o primeiro poll não volta, a marca
 * ocupa a tela inteira em vez de um vazio escuro. `full` cobre a viewport
 * (rotas inteiras); sem ela o bloco preenche o espaço que o pai der.
 */
defineProps({
    label: { type: String, default: 'Carregando…' },
    full: { type: Boolean, default: true },
})
</script>

<template>
    <div
        class="grid place-items-center p-8"
        :class="full ? 'min-h-dvh' : 'flex-1 min-h-64'"
    >
        <div class="flex flex-col items-center gap-7">
            <div class="brand-breathe">
                <BrandLogo size="xl" stacked />
            </div>

            <!-- barra de progresso indeterminada: dá a sensação de vida sem
                 fingir uma porcentagem que não existe -->
            <div class="w-56 h-1.5 rounded-full bg-white/10 overflow-hidden">
                <div class="h-full w-1/3 rounded-full bg-gradient-to-r from-sky-400 to-lime-400 brand-sweep" />
            </div>

            <p class="text-sm text-slate-400 flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" />
                {{ label }}
            </p>
        </div>
    </div>
</template>
