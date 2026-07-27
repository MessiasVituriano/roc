<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, participantToken } from '../lib/api'
import { usePolling } from '../composables/usePolling'
import PixelAvatar from '../components/PixelAvatar.vue'
import BrandLogo from '../components/BrandLogo.vue'
import LoadingScreen from '../components/LoadingScreen.vue'
import { CLOTHES, HAIRS, SKINS, STYLES, encodeAvatar, randomAvatarParts } from '../lib/avatar'

const router = useRouter()

const name = ref('')
const email = ref('')
const gender = ref('male')
const tableId = ref(null)

// the avatar is assembled, not rolled: these indexes are encoded into the seed
const parts = ref(randomAvatarParts())
const seed = computed(() => encodeAvatar(parts.value))

const error = ref('')
const submitting = ref(false)

// mantém o status do evento vivo: se o facilitador abrir depois que a pessoa
// carregou a tela, o botão "Entrar" destrava sozinho (e as contagens de mesa
// ficam frescas). Pausa quando o celular está no bolso — cortesia do usePolling.
const { data: boot, error: bootError, start, refresh } = usePolling(
    () => api.get('/bootstrap'),
    { interval: 2500, immediate: false },
)
const event = computed(() => boot.value?.event ?? null)
const tables = computed(() => boot.value?.tables ?? [])

function surprise() {
    parts.value = randomAvatarParts()
    gender.value = STYLES[Math.floor(Math.random() * STYLES.length)].key
}

const chosenTable = computed(() => tables.value.find((t) => t.id === tableId.value) ?? null)
const emailLooksValid = computed(() => /^\S+@\S+\.\S+$/.test(email.value.trim()))
const ready = computed(
    () => name.value.trim().length >= 2 && emailLooksValid.value && tableId.value !== null,
)

// o cadastro fica aberto até o evento ser encerrado: as pessoas entram antes e
// esperam na sala de espera. Só um evento inexistente ou já encerrado trava.
const canRegister = computed(
    () => Boolean(event.value) && event.value.status !== 'finished',
)
// o evento ainda não começou: o cadastro leva à sala de espera, não à rodada
const preOpen = computed(() => event.value?.status === 'draft')
// por que o botão está travado, para explicar na tela
const blockedReason = computed(() => {
    if (!event.value) return 'Nenhum evento disponível no momento.'
    if (event.value.status === 'finished') return 'Este evento já foi encerrado.'
    return ''
})

onMounted(() => {
    if (participantToken.get()) {
        // already checked in on this device — go straight back to the dynamic
        router.replace('/play')
        return
    }

    refresh()
    start()
})

async function join() {
    if (!ready.value || submitting.value) return

    submitting.value = true
    error.value = ''

    try {
        const payload = await api.post('/join', {
            name: name.value.trim(),
            email: email.value.trim(),
            gender: gender.value,
            table_id: tableId.value,
            avatar_seed: seed.value,
        })

        participantToken.set(payload.token)
        router.replace('/play')
    } catch (e) {
        // validation errors carry the friendly message per field
        error.value = e.payload?.errors
            ? Object.values(e.payload.errors).flat()[0]
            : e.message
    } finally {
        submitting.value = false
    }
}
</script>

<template>
    <!-- o primeiro /bootstrap ainda não voltou: marca em vez de tela vazia -->
    <LoadingScreen
        v-if="!boot"
        :label="bootError ? 'Sem conexão — tentando de novo…' : 'Preparando a sala…'"
    />

    <div v-else class="min-h-dvh flex flex-col items-center p-5 gap-5">
        <header class="flex flex-col items-center gap-3 pt-4">
            <BrandLogo size="lg" stacked />
            <p
                v-if="event?.title"
                class="text-center text-lg font-black bg-gradient-to-r from-indigo-300 via-fuchsia-300 to-amber-200 bg-clip-text text-transparent"
            >
                {{ event.title }}
            </p>
            <p class="text-slate-400 text-sm">Vamos decidir juntos 🎯</p>
        </header>

        <div class="w-full max-w-md rounded-3xl bg-slate-900/70 ring-1 ring-white/10 p-6 shadow-2xl backdrop-blur space-y-5">
            <!-- live preview of the avatar the person will carry all event -->
            <div class="flex items-center gap-4">
                <PixelAvatar :seed="seed" :gender="gender" :size="76" />
                <div class="min-w-0">
                    <p class="text-lg font-black text-white truncate">
                        {{ name.trim() || 'Seu avatar' }}
                    </p>
                    <p v-if="chosenTable" class="text-sm font-bold truncate" :style="{ color: chosenTable.color }">
                        {{ chosenTable.icon }} {{ chosenTable.name }}
                    </p>
                    <button
                        class="mt-1 text-xs text-slate-400 hover:text-indigo-300 transition"
                        @click="surprise"
                    >
                        🎲 Surpreenda-me
                    </button>
                </div>
            </div>

            <div class="space-y-1.5">
                <label class="text-xs font-bold uppercase tracking-widest text-slate-400">Nome</label>
                <input
                    v-model="name"
                    type="text"
                    maxlength="60"
                    placeholder="Seu nome"
                    autocomplete="name"
                    class="w-full rounded-2xl bg-slate-800/80 px-4 py-3.5 text-white placeholder-slate-500 ring-2 ring-transparent focus:ring-indigo-400 outline-none transition"
                >
            </div>

            <div class="space-y-1.5">
                <label class="text-xs font-bold uppercase tracking-widest text-slate-400">E-mail</label>
                <input
                    v-model="email"
                    type="email"
                    maxlength="120"
                    inputmode="email"
                    autocapitalize="off"
                    autocomplete="email"
                    placeholder="voce@empresa.com"
                    class="w-full rounded-2xl bg-slate-800/80 px-4 py-3.5 text-white placeholder-slate-500 ring-2 outline-none transition"
                    :class="email && !emailLooksValid ? 'ring-rose-500/60' : 'ring-transparent focus:ring-indigo-400'"
                >
            </div>

            <!-- monte o avatar: sexo (sprite), pele, cabelo, roupa -->
            <div class="space-y-1.5">
                <label class="text-xs font-bold uppercase tracking-widest text-slate-400">Sexo</label>
                <div class="grid grid-cols-3 gap-2">
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

            <div
                v-for="row in [
                    { key: 'skin', label: 'Tom de pele', colors: SKINS },
                    { key: 'hair', label: 'Cor do cabelo', colors: HAIRS },
                    { key: 'clothes', label: 'Roupa', colors: CLOTHES },
                ]"
                :key="row.key"
                class="space-y-1.5"
            >
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

            <div class="space-y-1.5">
                <label class="text-xs font-bold uppercase tracking-widest text-slate-400">Sua mesa</label>
                <div class="grid grid-cols-2 gap-2 max-h-56 overflow-y-auto pr-1">
                    <button
                        v-for="table in tables"
                        :key="table.id"
                        class="rounded-2xl p-2.5 ring-2 transition-all duration-200 text-left flex items-center gap-2"
                        :class="tableId === table.id ? 'bg-white/10' : 'ring-white/10 bg-slate-800/60 hover:ring-white/25'"
                        :style="tableId === table.id ? { boxShadow: `0 0 0 2px ${table.color}` } : {}"
                        @click="tableId = table.id"
                    >
                        <span class="text-lg">{{ table.icon }}</span>
                        <span class="min-w-0">
                            <span class="block text-sm font-bold text-white truncate">{{ table.name }}</span>
                            <span class="block text-[11px] text-slate-400">{{ table.participants_count }} pessoas</span>
                        </span>
                    </button>
                    <p v-if="!tables.length" class="col-span-2 text-sm text-slate-500 italic py-2">
                        Nenhuma mesa disponível ainda.
                    </p>
                </div>
            </div>

            <p v-if="error" class="rounded-xl bg-rose-500/15 text-rose-300 text-sm px-4 py-2 text-center">
                {{ error }}
            </p>

            <button
                class="w-full rounded-2xl px-5 py-4 font-black text-white bg-gradient-to-r from-emerald-500 to-teal-400 hover:brightness-110 active:scale-95 transition disabled:opacity-40 disabled:cursor-not-allowed shadow-lg shadow-emerald-500/20"
                :disabled="!ready || !canRegister || submitting"
                @click="join"
            >
                {{ submitting ? 'Entrando…' : preOpen ? 'Reservar meu lugar 🎟️' : 'Entrar 🚀' }}
            </button>

            <!-- antes de abrir: o cadastro leva à sala de espera -->
            <p v-if="canRegister && preOpen" class="-mt-2 flex items-center justify-center gap-2 text-center text-xs text-slate-400">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" />
                Cadastre-se agora — o evento começa em instantes.
            </p>
            <!-- sem evento ou já encerrado: nada a fazer aqui -->
            <p v-else-if="!canRegister" class="-mt-2 flex items-center justify-center gap-2 text-center text-xs text-amber-300/90">
                <span class="w-2 h-2 rounded-full bg-amber-400 animate-pulse" />
                {{ blockedReason }}
            </p>
        </div>
    </div>
</template>
