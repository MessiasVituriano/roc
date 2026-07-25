<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, masterToken } from '../lib/api'
import { usePolling } from '../composables/usePolling'
import AuditoriumMap from '../components/AuditoriumMap.vue'
import CountdownTimer from '../components/CountdownTimer.vue'
import ProgressBar from '../components/ProgressBar.vue'
import PixelAvatar from '../components/PixelAvatar.vue'

const route = useRoute()
const router = useRouter()

// ?token=… permite abrir o painel por atalho; guarda e limpa a URL
if (route.query.token) {
    masterToken.set(String(route.query.token))
    router.replace({ path: '/master' })
}

const authed = ref(Boolean(masterToken.get()))
const tokenInput = ref('')
const authError = ref('')
const busy = ref('')
const actionError = ref('')

const selected = ref(null)
const editing = ref(false)
const dirtyLayout = ref(new Map())
const layer = ref('tables')

const { data: state, online, refresh, start } = usePolling(
    () => api.get('/admin/overview'),
    { interval: 1000, immediate: false },
)

const event = computed(() => state.value?.event ?? null)
const question = computed(() => state.value?.question ?? null)
const timer = computed(() => state.value?.timer ?? { remaining: 0, duration: 1 })
const progress = computed(() => state.value?.progress ?? { answered: 0, total: 0, percent: 0 })
const stats = computed(() => state.value?.stats ?? { participants: 0, connected: 0, tables: 0 })
const results = computed(() => state.value?.results ?? null)
const tableRanking = computed(() => state.value?.table_ranking ?? [])
const missionRanking = computed(() => state.value?.mission_ranking ?? [])
const individualRanking = computed(() => state.value?.individual_ranking ?? [])
const needsTieBreak = computed(() => state.value?.needs_tie_break ?? false)

const individual = computed(() => event.value?.phase_mode === 'individual')
const revealed = computed(() => event.value?.round_status === 'revealed')
const voting = computed(() => event.value?.round_status === 'voting')

// --- máquina de estados vista pelo painel -------------------------------
// status: draft → open → running → finished · round_status: idle → voting → revealed
const status = computed(() => event.value?.status ?? 'draft')
const isDraft = computed(() => status.value === 'draft')
const isFinished = computed(() => status.value === 'finished')
const idle = computed(() => (event.value?.round_status ?? 'idle') === 'idle')
// voting_open = ainda aceita votos (tempo > 0). votingClosed = votação em curso mas tempo esgotado.
const votingOpen = computed(() => event.value?.voting_open ?? false)
const votingClosed = computed(() => voting.value && !votingOpen.value)
// só dá para abrir a votação quando o evento já foi aberto e a rodada está parada
const canStart = computed(() => !isDraft.value && !isFinished.value && idle.value)

// o passo do roteiro em que estamos — ilumina o stepper e o botão recomendado
const STEPS = [
    { key: 'open', label: 'Abrir' },
    { key: 'voting', label: 'Votação' },
    { key: 'reveal', label: 'Revelar' },
    { key: 'next', label: 'Próxima' },
]
const currentStep = computed(() => {
    if (isDraft.value) return 'open'
    if (voting.value) return 'reveal'
    if (revealed.value) return 'next'
    return 'voting'
})
const stepIndex = computed(() => STEPS.findIndex((s) => s.key === currentStep.value))

// etiqueta grande de estado, no cabeçalho
const stageLabel = computed(() => {
    if (isDraft.value) return ['Evento não aberto', 'bg-slate-600']
    if (isFinished.value) return ['Evento encerrado', 'bg-rose-600']
    if (revealed.value) return ['Revelado no telão', 'bg-emerald-500']
    if (votingClosed.value) return ['Votação encerrada — revele', 'bg-amber-500']
    if (voting.value) return ['Votação aberta', 'bg-sky-500 animate-pulse']
    return ['Pronto para abrir votação', 'bg-slate-600']
})

const tables = computed(() => (state.value?.tables ?? []).map((table) => {
    const pending = dirtyLayout.value.get(table.id)
    return pending ? { ...table, ...pending } : table
}))

const selectedTable = computed(() => tables.value.find((t) => t.id === selected.value) ?? null)

// quantas mesas já fecharam o voto nesta rodada — alimenta o destaque de "completo"
const doneTables = computed(() => tables.value.filter((t) => t.state === 'done').length)

async function authenticate() {
    masterToken.set(tokenInput.value.trim())

    try {
        await api.get('/admin/overview')
        authed.value = true
        authError.value = ''
        start()
        refresh()
    } catch (e) {
        masterToken.clear()
        authError.value = e.status === 401 ? 'Token inválido.' : e.message
    }
}

if (authed.value) {
    refresh()
    start()
}

async function action(name, path, body) {
    busy.value = name
    actionError.value = ''

    try {
        state.value = await api.post(path, body)
    } catch (e) {
        actionError.value = e.message
    } finally {
        busy.value = ''
    }
}

// destrutivo: pede confirmação antes de apagar tudo e recomeçar do zero
async function resetEvent() {
    const ok = window.confirm(
        'Resetar o evento?\n\nIsto apaga TODOS os participantes, votos e mesas e recria um evento novo em rascunho. Não dá para desfazer.',
    )

    if (ok) {
        await action('resetEvent', '/admin/reset-event')
    }
}

function setRepresentative(participantId) {
    if (!selectedTable.value) return
    action('rep', `/admin/tables/${selectedTable.value.id}/representative`, {
        participant_id: participantId,
    })
}

function onMove({ id, position_x, position_y }) {
    dirtyLayout.value = new Map(dirtyLayout.value).set(id, { position_x, position_y })
}

async function saveLayout() {
    if (!dirtyLayout.value.size) {
        editing.value = false
        return
    }

    busy.value = 'layout'

    try {
        await api.post('/admin/layout', {
            tables: [...dirtyLayout.value.entries()].map(([id, pos]) => ({ id, ...pos })),
        })
        dirtyLayout.value = new Map()
        editing.value = false
        await refresh()
    } catch (e) {
        actionError.value = e.message
    } finally {
        busy.value = ''
    }
}

</script>

<template>
    <div v-if="!authed" class="min-h-dvh grid place-items-center p-6">
        <div class="w-full max-w-sm rounded-3xl bg-slate-900/80 ring-1 ring-white/10 p-8 space-y-4">
            <h1 class="text-2xl font-black text-white">Painel Master 🎛️</h1>
            <input
                v-model="tokenInput"
                type="password"
                placeholder="Token do painel"
                class="w-full rounded-2xl bg-slate-800 px-4 py-3.5 text-white ring-2 ring-transparent focus:ring-indigo-400 outline-none"
                @keyup.enter="authenticate"
            >
            <p v-if="authError" class="text-sm text-rose-400">{{ authError }}</p>
            <button
                class="w-full rounded-2xl px-5 py-3.5 font-bold text-white bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:brightness-110 active:scale-95 transition"
                @click="authenticate"
            >
                Entrar
            </button>
        </div>
    </div>

    <div v-else class="min-h-dvh flex flex-col">
        <header class="px-6 py-3 flex flex-wrap items-center gap-4 bg-slate-950/80 ring-1 ring-white/5">
            <h1 class="text-lg font-black text-white">🎛️ {{ event?.title ?? 'Painel Master' }}</h1>
            <span class="px-3 py-1 rounded-full text-xs font-bold text-white" :class="stageLabel[1]">
                {{ stageLabel[0] }}
            </span>
            <span v-if="event" class="text-xs font-bold uppercase tracking-widest text-slate-400">
                Fase {{ event.phase }}/{{ event.last_phase }} · rodada {{ event.round }}/{{ event.total_rounds }} ·
                {{ individual ? 'individual' : 'consenso' }}
            </span>
            <span v-if="needsTieBreak" class="px-3 py-1 rounded-full bg-rose-500 text-white text-xs font-black animate-pulse">
                ⚠ empate na liderança
            </span>

            <div class="ml-auto flex items-center gap-5">
                <div v-for="stat in [
                    { label: 'Participantes', value: stats.participants },
                    { label: 'Conectados', value: stats.connected },
                    { label: 'Votos', value: `${progress.answered}/${progress.total}` },
                    { label: 'Mesas completas', value: `${doneTables}/${stats.tables}`, hot: doneTables > 0 },
                ]" :key="stat.label" class="text-center">
                    <p class="text-xl font-black tabular-nums" :class="stat.hot ? 'text-emerald-400' : 'text-white'">{{ stat.value }}</p>
                    <p class="text-[10px] uppercase tracking-widest text-slate-500">{{ stat.label }}</p>
                </div>
                <span
                    class="w-3 h-3 rounded-full"
                    :class="online ? 'bg-emerald-400 shadow-[0_0_10px] shadow-emerald-400' : 'bg-rose-500 animate-pulse'"
                />
            </div>
        </header>

        <div class="flex-1 grid grid-cols-[minmax(0,22rem)_1fr_minmax(0,22rem)] gap-4 p-4 min-h-0">
            <!-- coluna 1: controle da rodada -->
            <aside class="flex flex-col gap-3 overflow-y-auto">
                <div class="rounded-3xl bg-slate-900/70 ring-1 ring-white/10 p-5 space-y-3">
                    <div class="grid place-items-center">
                        <CountdownTimer :remaining="timer.remaining" :duration="timer.duration" :size="120" />
                    </div>
                    <ProgressBar :value="progress.percent" />
                    <p class="text-center text-sm text-slate-400">
                        {{ progress.answered }} de {{ progress.total }} votos
                    </p>
                    <div class="grid grid-cols-2 gap-2">
                        <button
                            v-for="s in [10, 30]" :key="s"
                            class="rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-bold py-2 transition disabled:opacity-30 disabled:cursor-not-allowed"
                            :disabled="!votingOpen"
                            @click="action('time', '/admin/add-time', { seconds: s })"
                        >
                            +{{ s }}s
                        </button>
                    </div>
                </div>

                <!-- controle da rodada, guiado pelo estado -->
                <div class="rounded-3xl bg-slate-900/70 ring-1 ring-white/10 p-5 space-y-3">
                    <!-- roteiro da rodada: ilumina o passo atual -->
                    <div class="flex items-center gap-1">
                        <template v-for="(step, i) in STEPS" :key="step.key">
                            <div
                                class="flex-1 text-center text-[10px] font-black uppercase tracking-wider py-1.5 rounded-lg transition-colors"
                                :class="i === stepIndex
                                    ? 'bg-indigo-500 text-white'
                                    : i < stepIndex ? 'bg-emerald-500/20 text-emerald-300' : 'bg-slate-800 text-slate-500'"
                            >{{ step.label }}</div>
                        </template>
                    </div>

                    <!-- passo 0: abrir o evento (só no início) -->
                    <button
                        v-if="isDraft"
                        class="w-full rounded-2xl px-4 py-4 font-black text-white text-lg bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:brightness-110 active:scale-95 transition ring-2 ring-white/40 shadow-lg animate-attn"
                        :disabled="busy === 'open'"
                        @click="action('open', '/admin/open')"
                    >
                        🚪 Abrir Evento
                    </button>

                    <template v-else>
                        <!-- abrir votação: só quando a rodada está parada -->
                        <button
                            class="w-full rounded-2xl px-4 py-3.5 font-bold text-white bg-gradient-to-r from-sky-500 to-indigo-500 hover:brightness-110 active:scale-95 transition disabled:opacity-30 disabled:grayscale disabled:cursor-not-allowed"
                            :class="canStart && busy !== 'start' ? 'ring-2 ring-white/40 shadow-lg animate-attn' : ''"
                            :disabled="!canStart || busy === 'start'"
                            @click="action('start', '/admin/start')"
                        >
                            ▶ Abrir votação · rodada {{ event?.round }}
                        </button>

                        <!-- durante a votação: encerrar e revelar; abrir fica travado -->
                        <div class="grid grid-cols-2 gap-2">
                            <button
                                class="rounded-2xl px-3 py-3 font-bold text-white bg-gradient-to-r from-amber-500 to-orange-500 hover:brightness-110 active:scale-95 transition disabled:opacity-30 disabled:grayscale disabled:cursor-not-allowed"
                                :disabled="!votingOpen || busy === 'close'"
                                @click="action('close', '/admin/close')"
                            >
                                ⏹ Encerrar
                            </button>
                            <button
                                class="rounded-2xl px-3 py-3 font-black text-white bg-gradient-to-r from-emerald-500 to-teal-500 hover:brightness-110 active:scale-95 transition disabled:opacity-30 disabled:grayscale disabled:cursor-not-allowed shadow-emerald-500/20"
                                :class="voting && busy !== 'reveal' ? 'ring-2 ring-white/40 shadow-lg ' + (votingClosed ? 'animate-attn' : '') : ''"
                                :disabled="!voting || busy === 'reveal'"
                                @click="action('reveal', '/admin/reveal')"
                            >
                                📊 Revelar
                            </button>
                        </div>

                        <!-- depois de revelar: seguir para a próxima rodada -->
                        <button
                            class="w-full rounded-2xl px-4 py-4 font-black text-white text-lg bg-gradient-to-r from-fuchsia-500 to-purple-500 hover:brightness-110 active:scale-95 transition disabled:opacity-30 disabled:grayscale disabled:cursor-not-allowed"
                            :class="revealed && busy !== 'next' ? 'ring-2 ring-white/40 shadow-lg animate-attn' : ''"
                            :disabled="!revealed || busy === 'next'"
                            @click="action('next', '/admin/next')"
                        >
                            ⏭ Próxima rodada
                        </button>

                        <!-- corrigir uma revelação precoce -->
                        <button
                            v-if="revealed"
                            class="w-full rounded-xl px-4 py-2 text-xs font-semibold text-slate-400 hover:text-sky-300 transition"
                            @click="action('unreveal', '/admin/unreveal')"
                        >
                            ↩ Reabrir votação (desfazer revelação)
                        </button>
                    </template>
                </div>

                <!-- a virada de fase -->
                <div class="rounded-3xl bg-amber-500/10 ring-2 ring-amber-500/40 p-5 space-y-2">
                    <p class="text-[10px] uppercase tracking-widest text-amber-300 font-black">A virada de fase</p>
                    <button
                        class="w-full rounded-2xl px-4 py-3 font-black text-white bg-gradient-to-r from-amber-500 to-yellow-500 hover:brightness-110 active:scale-95 transition"
                        @click="action('missions', event?.missions_revealed ? '/admin/missions/hide' : '/admin/missions/reveal')"
                    >
                        {{ event?.missions_revealed ? '🙈 Ocultar placar por missão' : '🎭 Revelar placar por missão' }}
                    </button>
                    <button
                        class="w-full rounded-2xl px-4 py-3 font-bold text-white bg-gradient-to-r from-indigo-500 to-violet-500 hover:brightness-110 active:scale-95 transition"
                        @click="action('phase', '/admin/next-phase')"
                    >
                        ➡ Ir para a Fase {{ (event?.phase ?? 1) + 1 }}
                    </button>
                </div>

                <div class="rounded-3xl bg-slate-900/70 ring-1 ring-white/10 p-5 space-y-2">
                    <button
                        class="w-full rounded-2xl px-4 py-2.5 text-sm font-bold text-white bg-slate-700 hover:bg-slate-600 transition"
                        @click="action('bonus', '/admin/bonus-round')"
                    >
                        🎲 Carregar rodada de desempate
                    </button>
                    <button
                        class="w-full rounded-2xl px-4 py-2.5 text-sm font-bold text-white bg-rose-600 hover:brightness-110 transition"
                        @click="action('end', '/admin/end')"
                    >
                        🏁 Finalizar evento
                    </button>
                    <button
                        class="w-full rounded-2xl px-4 py-2 text-xs font-semibold text-slate-400 hover:text-rose-300 transition"
                        @click="action('reset', '/admin/reset-round')"
                    >
                        Zerar votos da rodada
                    </button>

                    <div class="pt-2 mt-1 border-t border-white/10">
                        <button
                            class="w-full rounded-2xl px-4 py-3 text-sm font-black text-white bg-gradient-to-r from-rose-600 to-orange-600 hover:brightness-110 active:scale-95 transition disabled:opacity-50 disabled:cursor-wait"
                            :disabled="busy === 'resetEvent'"
                            @click="resetEvent"
                        >
                            ♻️ {{ busy === 'resetEvent' ? 'Resetando…' : 'Resetar evento (novo do zero)' }}
                        </button>
                        <p class="mt-1 text-[10px] text-slate-500 text-center leading-tight">
                            Apaga participantes, votos e mesas e recria o evento em rascunho.
                        </p>
                    </div>
                </div>

                <p v-if="actionError" class="rounded-xl bg-rose-500/15 text-rose-300 text-sm px-4 py-2">
                    {{ actionError }}
                </p>
            </aside>

            <!-- coluna 2: pergunta + mapa -->
            <section class="relative flex flex-col gap-3 min-h-0">
                <div v-if="question" class="rounded-2xl bg-slate-900/70 ring-1 ring-white/10 p-4">
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-widest bg-amber-500/20 text-amber-200">
                            {{ question.label }}
                        </span>
                        <span v-if="question.is_bonus" class="px-2 py-0.5 rounded-full text-[10px] font-black bg-rose-500 text-white">
                            DESEMPATE
                        </span>
                    </div>
                    <p class="text-white font-bold mt-1.5">{{ question.title }}</p>

                    <!-- gabarito e viés: notas de condução, nunca vão ao telão -->
                    <div class="mt-2 grid grid-cols-2 gap-1.5">
                        <div
                            v-for="(option, i) in question.options" :key="option.id"
                            class="text-[11px] px-2 py-1 rounded-lg flex justify-between gap-2"
                            :class="option.points >= 150 ? 'bg-emerald-500/20 text-emerald-200' : 'bg-slate-800 text-slate-300'"
                        >
                            <span class="truncate">{{ 'ABCD'[i] }}) {{ option.text }}</span>
                            <span class="font-black tabular-nums shrink-0">
                                {{ option.points > 0 ? '+' : '' }}{{ option.points }}
                            </span>
                        </div>
                    </div>
                    <p v-if="question.bias_note" class="mt-2 text-[11px] text-amber-300/80 italic leading-snug">
                        Viés: {{ question.bias_note }}
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <h2 class="font-bold text-white text-sm">Auditório</h2>
                    <template v-if="editing">
                        <button
                            class="ml-auto rounded-xl bg-emerald-500 px-3 py-1 text-xs font-bold text-white"
                            @click="saveLayout"
                        >Salvar</button>
                        <button class="rounded-xl bg-slate-700 px-3 py-1 text-xs font-bold text-slate-200" @click="editing = false">
                            Cancelar
                        </button>
                    </template>
                    <button
                        v-else
                        class="ml-auto rounded-xl bg-slate-800 px-3 py-1 text-xs font-bold text-slate-200 hover:bg-slate-700 transition"
                        @click="editing = true"
                    >✥ Layout</button>
                </div>

                <AuditoriumMap
                    :tables="tables"
                    :mode="event?.phase_mode ?? 'individual'"
                    compact
                    interactive
                    :editable="editing"
                    :selected-id="selected"
                    class="flex-1 min-h-0"
                    @select="selected = selected === $event.id ? null : $event.id"
                    @move="onMove"
                />

                <Transition name="slide">
                    <div
                        v-if="selectedTable"
                        class="absolute bottom-2 right-2 w-80 rounded-3xl bg-slate-900/95 ring-1 ring-white/15 p-4 shadow-2xl backdrop-blur"
                    >
                        <div class="flex items-center gap-2">
                            <span class="text-2xl">{{ selectedTable.icon }}</span>
                            <h3 class="font-black text-white">{{ selectedTable.name }}</h3>
                            <button class="ml-auto text-slate-500 hover:text-white" @click="selected = null">✕</button>
                        </div>
                        <ul class="mt-2 space-y-1 max-h-52 overflow-y-auto">
                            <li
                                v-for="person in selectedTable.participants" :key="person.id"
                                class="flex items-center gap-2 text-sm"
                            >
                                <PixelAvatar :seed="person.avatar_seed" :gender="person.gender" :size="24" :dim="!person.online" />
                                <span class="text-slate-200 truncate">{{ person.name }}</span>
                                <span v-if="person.voted" class="text-emerald-400 text-xs">✔</span>
                                <button
                                    v-if="!individual"
                                    class="ml-auto text-[10px] font-bold px-2 py-0.5 rounded-lg transition"
                                    :class="person.is_representative ? 'bg-amber-500 text-white' : 'bg-slate-800 text-slate-400 hover:bg-slate-700'"
                                    @click="setRepresentative(person.is_representative ? null : person.id)"
                                >
                                    {{ person.is_representative ? '👑' : 'tornar' }}
                                </button>
                            </li>
                        </ul>
                    </div>
                </Transition>
            </section>

            <!-- coluna 3: as três camadas de placar -->
            <aside class="flex flex-col gap-3 min-h-0">
                <div class="flex gap-1 rounded-2xl bg-slate-900/70 ring-1 ring-white/10 p-1">
                    <button
                        v-for="tab in [
                            { key: 'tables', label: 'Mesas' },
                            { key: 'missions', label: 'Missões' },
                            { key: 'people', label: 'Individual' },
                        ]"
                        :key="tab.key"
                        class="flex-1 rounded-xl px-2 py-2 text-xs font-bold transition"
                        :class="layer === tab.key ? 'bg-indigo-500 text-white' : 'text-slate-400 hover:text-white'"
                        @click="layer = tab.key"
                    >
                        {{ tab.label }}
                    </button>
                </div>

                <div class="flex-1 rounded-3xl bg-slate-900/70 ring-1 ring-white/10 p-4 overflow-y-auto">
                    <!-- camada 3: mesas, na ordem do critério de vitória -->
                    <table v-if="layer === 'tables'" class="w-full text-sm">
                        <thead class="text-[10px] uppercase tracking-widest text-slate-500">
                            <tr>
                                <th class="text-left pb-2">Mesa</th>
                                <th class="text-right pb-2">F1</th>
                                <th class="text-right pb-2">F2</th>
                                <th class="text-right pb-2">Total</th>
                                <th class="text-right pb-2">Evol.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in tableRanking" :key="row.table_id"
                                class="border-t border-white/5"
                                :class="row.tied_with_leader ? 'bg-rose-500/10' : ''"
                            >
                                <td class="py-1.5 text-white font-bold truncate">
                                    <span class="text-slate-500 tabular-nums mr-1">{{ row.position }}º</span>
                                    {{ row.icon }} {{ row.name }}
                                </td>
                                <td class="text-right tabular-nums text-slate-400">{{ row.phase_one_points }}</td>
                                <td class="text-right tabular-nums text-slate-300">{{ row.phase_two_points }}</td>
                                <td class="text-right tabular-nums font-black text-white">{{ row.total_points }}</td>
                                <td
                                    class="text-right tabular-nums font-bold"
                                    :class="row.evolution > 0 ? 'text-emerald-400' : row.evolution < 0 ? 'text-rose-400' : 'text-slate-500'"
                                >
                                    {{ row.evolution > 0 ? '+' : '' }}{{ row.evolution }}
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- camada 2: o viés por missão -->
                    <div v-else-if="layer === 'missions'" class="space-y-3">
                        <p class="text-[10px] uppercase tracking-widest text-slate-500">
                            Média de pontos por voto
                        </p>
                        <div
                            v-for="row in missionRanking" :key="row.mission_id"
                            class="rounded-2xl p-3 ring-1 ring-white/10"
                            :style="{ background: `linear-gradient(90deg, ${row.color}22, transparent)` }"
                        >
                            <div class="flex items-center gap-2">
                                <span class="text-lg">{{ row.icon }}</span>
                                <span class="font-bold text-white text-sm truncate">{{ row.name }}</span>
                                <span class="ml-auto font-black tabular-nums text-white">{{ row.average }}</span>
                            </div>
                            <p class="text-[11px] text-slate-400 mt-0.5">
                                {{ row.participants }} pessoas · {{ row.votes }} votos · {{ row.points }} pts
                            </p>
                        </div>
                    </div>

                    <!-- camada 1: ranking individual -->
                    <table v-else class="w-full text-sm">
                        <tbody>
                            <tr v-for="row in individualRanking" :key="row.participant_id" class="border-t border-white/5">
                                <td class="py-1 text-slate-500 tabular-nums w-8">{{ row.position }}º</td>
                                <td class="py-1">
                                    <PixelAvatar :seed="row.avatar_seed" :gender="row.gender" :size="22" />
                                </td>
                                <td class="py-1 text-white truncate">{{ row.name }}</td>
                                <td class="py-1 text-[10px] truncate" :style="{ color: row.mission_color }">
                                    {{ row.mission }}
                                </td>
                                <td class="py-1 text-right font-black tabular-nums text-white">{{ row.points }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- resultado da rodada revelada, para o facilitador narrar -->
                <div v-if="revealed && results" class="rounded-3xl bg-slate-900/70 ring-1 ring-white/10 p-4 space-y-1.5">
                    <p class="text-[10px] uppercase tracking-widest text-fuchsia-300 font-bold">Rodada revelada</p>
                    <div
                        v-for="option in results.options" :key="option.option_id"
                        class="flex items-center gap-2 text-xs"
                    >
                        <span class="truncate flex-1 text-slate-300">{{ option.text }}</span>
                        <span class="tabular-nums text-slate-400">{{ option.votes }}</span>
                        <span
                            class="tabular-nums font-black w-10 text-right"
                            :class="option.points > 0 ? 'text-emerald-400' : option.points < 0 ? 'text-rose-400' : 'text-slate-500'"
                        >
                            {{ option.points > 0 ? '+' : '' }}{{ option.points }}
                        </span>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</template>
