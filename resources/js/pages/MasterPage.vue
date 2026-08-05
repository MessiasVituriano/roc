<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, masterToken } from '../lib/api'
import { usePolling } from '../composables/usePolling'
import AuditoriumMap from '../components/AuditoriumMap.vue'
import CountdownTimer from '../components/CountdownTimer.vue'
import ProgressBar from '../components/ProgressBar.vue'
import PixelAvatar from '../components/PixelAvatar.vue'
import BrandLogo from '../components/BrandLogo.vue'
import LoadingScreen from '../components/LoadingScreen.vue'

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

const { data: state, error: stateError, online, refresh, start } = usePolling(
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

// O servidor ordena pela decisão individual (é o que o telão premia). Aqui o
// facilitador precisa das três leituras, então a reordenação é no cliente —
// a lista já está toda no payload, não vale uma ida ao servidor por clique.
const PERSON_SORTS = [
    { key: 'points', label: 'Individual' },
    { key: 'table_points', label: 'Mesa' },
    { key: 'combined_points', label: 'Total' },
]
const personSort = ref('points')

// Bloqueado sai do páreo, não da lista: ele vai para o fim, esmaecido e sem
// numeração, porque é dali que se desbloqueia quem foi bloqueado por engano.
const peopleRanking = computed(() =>
    [...individualRanking.value]
        .filter((row) => !row.blocked)
        .sort((a, b) => b[personSort.value] - a[personSort.value])
        .map((row, i) => ({ ...row, rank: i + 1 })),
)
const blockedPeople = computed(() => individualRanking.value.filter((row) => row.blocked))
const needsTieBreak = computed(() => state.value?.needs_tie_break ?? false)

const individual = computed(() => event.value?.phase_mode === 'individual')
const revealed = computed(() => event.value?.round_status === 'revealed')
const voting = computed(() => event.value?.round_status === 'voting')

// --- rodada final --------------------------------------------------------
// Sem alternativas, sem régua: a mesa decide livremente e o facilitador lança
// a pontuação aqui. É o único placar do evento digitado à mão.
const isFinalRound = computed(() => question.value?.manual_scoring === true)
const finalScores = computed(() => state.value?.final_round?.scores ?? [])
// enquanto o facilitador digita, o rascunho local manda — senão o polling de
// 1s reescreveria o campo embaixo do dedo dele
const scoreDraft = ref({})
const RULER = [150, 80, 0, -50]

async function saveScore(tableId, points) {
    busy.value = `score-${tableId}`
    actionError.value = ''

    try {
        state.value = await api.post('/admin/final-score', {
            event_table_id: tableId,
            points: points === '' || points === null ? null : Number(points),
        })
        // salvo: o servidor volta a ser a fonte do valor exibido
        delete scoreDraft.value[tableId]
    } catch (e) {
        actionError.value = e.message
    } finally {
        busy.value = ''
    }
}

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

// Voltar rodada: travado durante a votação aberta, para um clique torto não
// derrubar a rodada que está correndo — ⏹ Encerrar primeiro, e aí volta.
const backLeavesPhase = computed(() => (event.value?.round ?? 1) === 1 && (event.value?.phase ?? 1) > 1)
const canGoBack = computed(
    () => !isDraft.value
        && !votingOpen.value
        && ((event.value?.round ?? 1) > 1 || backLeavesPhase.value),
)

// Avançar não exige revelar: dá para pular uma rodada que não vai ser jogada e
// para seguir com a votação fechada quando a conversa já resolveu a rodada. A
// trava é a mesma do voltar — votação aberta não é atropelada por um clique.
const canGoNext = computed(() => !isDraft.value && !isFinished.value && !votingOpen.value)
// nada revelado no telão é o caso em que o clique pula a revelação
const nextSkipsReveal = computed(() => canGoNext.value && !revealed.value)

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

/**
 * Voltar uma rodada. Na primeira da fase o passo é maior — volta a fase
 * inteira —, e aí vale perguntar antes: é o desfazer de um "Ir para a Fase 2"
 * clicado sem querer, não um passo de roteiro.
 */
async function previousRound() {
    if (backLeavesPhase.value) {
        const ok = window.confirm(
            `Voltar para a Fase ${(event.value?.phase ?? 2) - 1}?\n\nO evento retoma na última rodada dela. Nenhum voto é apagado.`,
        )

        if (!ok) return
    }

    await action('previous', '/admin/previous')
}

/**
 * Rodar a rodada atual de novo, do zero. Apaga voto — e voto apagado no meio
 * do evento não volta —, então pergunta antes.
 */
async function reloadRound() {
    const votes = progress.value.answered
    const ok = window.confirm(
        votes > 0
            ? `Recarregar a rodada ${event.value?.round}?\n\nOs ${votes} votos já registrados nela serão apagados e a rodada volta para o ponto de abrir a votação. As outras rodadas não são afetadas.`
            : `Recarregar a rodada ${event.value?.round}?\n\nEla volta para o ponto de abrir a votação.`,
    )

    if (ok) await action('reset', '/admin/reset-round')
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

/**
 * Tira a pessoa do ranking individual — o único placar que vai ao telão com
 * nome e avatar. Os votos dela continuam somando para a mesa e para o grupo de
 * missão, e o celular dela não muda de comportamento.
 */
function setBlocked(participantId, blocked) {
    action(`block-${participantId}`, `/admin/participants/${participantId}/block`, { blocked })
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
            <div class="grid place-items-center pb-2">
                <BrandLogo size="lg" stacked />
            </div>
            <h1 class="text-2xl font-black text-white text-center">Painel Master 🎛️</h1>
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

    <!-- autenticado, mas o primeiro /admin/overview ainda não voltou -->
    <LoadingScreen
        v-else-if="!state"
        :label="stateError ? 'Sem conexão com o servidor — reconectando…' : 'Abrindo o painel master…'"
    />

    <div v-else class="min-h-dvh flex flex-col">
        <header class="px-6 py-3 flex flex-wrap items-center gap-4 bg-slate-950/80 ring-1 ring-white/5">
            <BrandLogo size="xs" :tagline="false" />
            <span class="w-px h-6 bg-white/10" />
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

                        <!--
                            Seguir para a próxima rodada. Depois de revelar é o
                            passo do roteiro — e é aí que ele pisca. Sem revelar
                            continua clicável, só sem destaque: é o pulo de uma
                            rodada que não vai ser jogada e o atalho de quando a
                            conversa já resolveu a rodada no fechamento.
                        -->
                        <button
                            class="w-full rounded-2xl px-4 py-4 font-black text-white text-lg bg-gradient-to-r from-fuchsia-500 to-purple-500 hover:brightness-110 active:scale-95 transition disabled:opacity-30 disabled:grayscale disabled:cursor-not-allowed"
                            :class="revealed && busy !== 'next' ? 'ring-2 ring-white/40 shadow-lg animate-attn' : ''"
                            :disabled="!canGoNext || busy === 'next'"
                            :title="nextSkipsReveal
                                ? 'Segue sem revelar esta rodada no telão — nenhum voto é apagado, e ⏮ traz ela de volta'
                                : 'Carrega a rodada seguinte'"
                            @click="action('next', '/admin/next')"
                        >
                            ⏭ Próxima rodada{{ nextSkipsReveal ? ' (sem revelar)' : '' }}
                        </button>

                        <!--
                            Voltar. Discreto de propósito: é conserto de clique
                            torto e recurso de narrativa (rever uma rodada no
                            telão durante a conversa), não passo do roteiro.
                            A rodada que já foi jogada volta revelada.
                        -->
                        <button
                            class="w-full rounded-xl px-4 py-2 text-xs font-semibold text-slate-400 hover:text-fuchsia-300 transition disabled:opacity-30 disabled:cursor-not-allowed disabled:hover:text-slate-400"
                            :disabled="!canGoBack || busy === 'previous'"
                            :title="backLeavesPhase
                                ? 'Volta para a última rodada da fase anterior'
                                : 'Volta para a rodada anterior — nenhum voto é apagado'"
                            @click="previousRound"
                        >
                            ⏮ {{ backLeavesPhase ? `Voltar para a Fase ${(event?.phase ?? 2) - 1}` : 'Rodada anterior' }}
                        </button>

                        <!-- corrigir uma revelação precoce -->
                        <button
                            v-if="revealed"
                            class="w-full rounded-xl px-4 py-2 text-xs font-semibold text-slate-400 hover:text-sky-300 transition"
                            @click="action('unreveal', '/admin/unreveal')"
                        >
                            ↩ Reabrir votação (desfazer revelação)
                        </button>

                        <!--
                            Rodar esta rodada de novo, do zero. Fica aqui, com
                            os outros controles de rodada, porque é onde o
                            facilitador procura quando a rodada deu errado.
                        -->
                        <button
                            class="w-full rounded-xl px-4 py-2 text-xs font-semibold text-slate-400 hover:text-rose-300 transition disabled:opacity-30 disabled:cursor-not-allowed"
                            :disabled="isDraft || busy === 'reset'"
                            title="Apaga os votos desta rodada e volta para o ponto de abrir a votação"
                            @click="reloadRound"
                        >
                            🔄 Recarregar a rodada (zera os votos)
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

                <!--
                    O fecho. Separado de "Encerrar" de propósito: encerrar joga
                    os celulares na tela de obrigado, este clique mantém a sala
                    inteira olhando para o telão.
                -->
                <div class="rounded-3xl bg-emerald-500/10 ring-2 ring-emerald-500/40 p-5 space-y-2">
                    <p class="text-[10px] uppercase tracking-widest text-emerald-300 font-black">O fecho</p>
                    <button
                        class="w-full rounded-2xl px-4 py-3 font-black text-white bg-gradient-to-r from-emerald-500 to-teal-500 hover:brightness-110 active:scale-95 transition"
                        @click="action('answers', event?.answers_revealed ? '/admin/answers/hide' : '/admin/answers/reveal')"
                    >
                        {{ event?.answers_revealed ? '🙈 Ocultar gabarito' : '🔓 Revelar gabarito + comparativo' }}
                    </button>
                    <p class="text-[11px] text-emerald-200/60 leading-snug">
                        Abre a melhor decisão de cada rodada da Fase 1, a pontuação da rodada
                        final e o comparativo entre as duas. Antes disso o telão só mostra
                        distribuição.
                    </p>
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

                    <!-- rodada final: a missão no lugar do gabarito, porque não
                         há alternativa nem régua a conferir -->
                    <p
                        v-if="isFinalRound"
                        class="mt-2 rounded-xl bg-amber-500/10 ring-1 ring-amber-400/30 px-3 py-2 text-[11px] text-amber-100 leading-snug"
                    >
                        “{{ question.context }}”
                    </p>

                    <!-- gabarito e viés: notas de condução, nunca vão ao telão -->
                    <div v-else class="mt-2 grid grid-cols-2 gap-1.5">
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

                <!--
                    LANÇAMENTO DA RODADA FINAL. A única pontuação do evento que
                    não sai de uma alternativa: a mesa decide livremente e o
                    facilitador atribui o valor. Os atalhos seguem a régua da
                    dinâmica; o campo aceita qualquer número.
                -->
                <div
                    v-if="isFinalRound"
                    class="rounded-2xl bg-amber-500/10 ring-2 ring-amber-500/40 p-4 flex flex-col min-h-0 max-h-72"
                >
                    <div class="flex items-baseline gap-2 shrink-0">
                        <p class="text-[10px] uppercase tracking-widest text-amber-300 font-black">
                            Pontuação da rodada final
                        </p>
                        <p class="text-[10px] text-amber-200/60">
                            {{ finalScores.filter((row) => row.scored).length }}/{{ finalScores.length }} mesas lançadas
                            · Enter salva · ✕ apaga
                        </p>
                    </div>

                    <div class="flex-1 min-h-0 overflow-y-auto mt-2 space-y-1 pr-1">
                        <div
                            v-for="row in finalScores"
                            :key="row.table_id"
                            class="flex items-center gap-2 rounded-xl px-2 py-1"
                            :class="row.scored ? 'bg-emerald-500/10' : 'bg-slate-900/60'"
                        >
                            <span class="shrink-0">{{ row.icon }}</span>
                            <span class="flex-1 min-w-0 truncate text-xs font-bold text-white">{{ row.name }}</span>

                            <button
                                v-for="value in RULER" :key="value"
                                class="rounded-lg px-2 py-1 text-[10px] font-black tabular-nums transition shrink-0"
                                :class="row.points === value
                                    ? 'bg-amber-500 text-white'
                                    : 'bg-slate-800 text-slate-300 hover:bg-slate-700'"
                                :disabled="busy === `score-${row.table_id}`"
                                @click="saveScore(row.table_id, value)"
                            >
                                {{ value > 0 ? '+' : '' }}{{ value }}
                            </button>

                            <input
                                type="number"
                                class="w-16 shrink-0 rounded-lg bg-slate-950 px-2 py-1 text-xs text-white text-right tabular-nums ring-1 ring-white/10 focus:ring-amber-400 outline-none"
                                :value="scoreDraft[row.table_id] ?? row.points ?? ''"
                                @input="scoreDraft[row.table_id] = $event.target.value"
                                @keyup.enter="saveScore(row.table_id, scoreDraft[row.table_id] ?? '')"
                                @blur="scoreDraft[row.table_id] !== undefined && saveScore(row.table_id, scoreDraft[row.table_id])"
                            >
                            <button
                                class="shrink-0 text-slate-500 hover:text-rose-300 text-xs px-1"
                                :disabled="!row.scored"
                                @click="saveScore(row.table_id, null)"
                            >✕</button>
                        </div>
                    </div>
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
                                :class="person.blocked ? 'opacity-50' : ''"
                            >
                                <PixelAvatar :seed="person.avatar_seed" :gender="person.gender" :size="24" :dim="!person.online" />
                                <span class="min-w-0">
                                    <span class="block truncate text-slate-200" :class="person.blocked ? 'line-through' : ''">
                                        {{ person.name }}
                                        <span v-if="person.voted" class="text-emerald-400 text-xs">✔</span>
                                    </span>
                                    <span v-if="person.hotel" class="block text-[10px] text-slate-500 truncate">
                                        🏨 {{ person.hotel }}
                                    </span>
                                </span>
                                <div class="ml-auto flex items-center gap-1 shrink-0">
                                    <!-- fora do ranking individual; o voto dele
                                         continua somando para a mesa -->
                                    <button
                                        class="text-[10px] font-bold px-2 py-0.5 rounded-lg transition"
                                        :class="person.blocked
                                            ? 'bg-rose-500 text-white'
                                            : 'bg-slate-800 text-slate-400 hover:bg-slate-700 hover:text-rose-300'"
                                        :disabled="busy === `block-${person.id}`"
                                        :title="person.blocked
                                            ? 'Bloqueado no ranking individual — clique para liberar'
                                            : 'Tirar do ranking individual (os votos seguem contando)'"
                                        @click="setBlocked(person.id, !person.blocked)"
                                    >
                                        {{ person.blocked ? '🚫' : 'bloquear' }}
                                    </button>
                                    <!-- bloqueado não é elegível: bloquear já
                                         libera o posto, e o servidor recusa -->
                                    <button
                                        v-if="!individual && !person.blocked"
                                        class="text-[10px] font-bold px-2 py-0.5 rounded-lg transition"
                                        :class="person.is_representative ? 'bg-amber-500 text-white' : 'bg-slate-800 text-slate-400 hover:bg-slate-700'"
                                        @click="setRepresentative(person.is_representative ? null : person.id)"
                                    >
                                        {{ person.is_representative ? '👑' : 'tornar' }}
                                    </button>
                                </div>
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
                                <th class="text-right pb-2" title="Acertos da mesa na Fase 1 (votos individuais dos membros)">✔ F1</th>
                                <th class="text-right pb-2" title="A rodada final não tem alternativa certa: é pontuada à mão">✔ F2</th>
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
                                <td class="text-right tabular-nums text-slate-400">
                                    {{ row.phase_one_correct }}/{{ row.phase_one_votes }}
                                    <span v-if="row.phase_one_accuracy !== null" class="text-[10px] text-slate-500 ml-0.5">
                                        {{ row.phase_one_accuracy }}%
                                    </span>
                                </td>
                                <td
                                    class="text-right tabular-nums"
                                    :class="row.phase_two_accuracy > row.phase_one_accuracy ? 'text-emerald-400 font-bold' : 'text-slate-300'"
                                >
                                    <!-- null = rodada sem régua (a final) -->
                                    <template v-if="row.phase_two_correct !== null">
                                        {{ row.phase_two_correct }}/{{ row.phase_two_votes }}
                                        <span v-if="row.phase_two_accuracy !== null" class="text-[10px] opacity-70 ml-0.5">
                                            {{ row.phase_two_accuracy }}%
                                        </span>
                                    </template>
                                    <span v-else class="text-slate-600">—</span>
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
                            <p v-if="row.accuracy !== null" class="text-[11px] mt-0.5 font-bold text-slate-300">
                                ✔ {{ row.correct }} acertos · {{ row.accuracy }}%
                            </p>
                        </div>
                    </div>

                    <!--
                        camada 1: a pessoa por inteiro. A Fase 2 não tem voto
                        individual — a mesa decide por todos —, então "F2" é o
                        que a mesa dela fez, repetido em cada membro.
                    -->
                    <div v-else class="space-y-2">
                        <div class="flex items-center gap-1 rounded-xl bg-slate-950/60 p-1">
                            <button
                                v-for="tab in PERSON_SORTS" :key="tab.key"
                                class="flex-1 rounded-lg px-2 py-1 text-[11px] font-bold transition"
                                :class="personSort === tab.key ? 'bg-slate-700 text-white' : 'text-slate-400 hover:text-white'"
                                @click="personSort = tab.key"
                            >
                                {{ tab.label }}
                            </button>
                        </div>

                        <table class="w-full text-sm">
                            <thead class="text-[10px] uppercase tracking-widest text-slate-500">
                                <tr>
                                    <th class="text-left pb-1" colspan="2">Pessoa</th>
                                    <th class="text-right pb-1" title="Pontos da própria decisão, Fase 1">F1</th>
                                    <th class="text-right pb-1" title="Pontos da mesa desta pessoa, Fase 2">F2</th>
                                    <th class="text-right pb-1">Total</th>
                                    <th class="pb-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in peopleRanking" :key="row.participant_id"
                                    class="border-t border-white/5"
                                >
                                    <td class="py-1 text-slate-500 tabular-nums w-7 align-top">{{ row.rank }}º</td>
                                    <td class="py-1 pr-2">
                                        <div class="flex items-center gap-1.5 min-w-0">
                                            <PixelAvatar :seed="row.avatar_seed" :gender="row.gender" :size="20" />
                                            <span class="min-w-0">
                                                <span class="flex items-center gap-1">
                                                    <span
                                                        class="w-1.5 h-1.5 rounded-full shrink-0"
                                                        :style="{ background: row.mission_color }"
                                                        :title="row.mission"
                                                    />
                                                    <span class="text-white truncate text-xs font-bold">{{ row.name }}</span>
                                                </span>
                                                <span class="block text-[10px] text-slate-500 truncate">
                                                    {{ row.table_icon }} {{ row.table }} · ✔ {{ row.correct }}/{{ row.answered }}
                                                </span>
                                                <!-- o hotel é o que distingue dois "Ana S." na sala -->
                                                <span v-if="row.hotel" class="block text-[10px] text-slate-400 truncate">
                                                    🏨 {{ row.hotel }}
                                                </span>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="py-1 text-right tabular-nums text-xs text-slate-300 align-top">
                                        {{ row.points }}
                                    </td>
                                    <td class="py-1 text-right tabular-nums text-xs align-top"
                                        :class="row.table_points ? 'text-emerald-300' : 'text-slate-600'">
                                        {{ row.table_points }}
                                    </td>
                                    <td class="py-1 text-right font-black tabular-nums text-white align-top">
                                        {{ row.combined_points }}
                                    </td>
                                    <td class="py-1 pl-1 align-top">
                                        <button
                                            class="text-[10px] text-slate-600 hover:text-rose-300 transition"
                                            :disabled="busy === `block-${row.participant_id}`"
                                            title="Tirar do ranking individual (os votos seguem contando para a mesa e para a missão)"
                                            @click="setBlocked(row.participant_id, true)"
                                        >🚫</button>
                                    </td>
                                </tr>
                                <tr v-if="!peopleRanking.length">
                                    <td colspan="6" class="py-3 text-center text-xs text-slate-500 italic">
                                        Ninguém votou ainda.
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <!--
                            Os bloqueados. Continuam votando e continuam somando
                            para a mesa e para o grupo de missão — o que o
                            bloqueio tira é o lugar no pódio individual, que é o
                            único placar que vai ao telão com nome e avatar.
                        -->
                        <div v-if="blockedPeople.length" class="pt-2 mt-1 border-t border-white/10 space-y-1">
                            <p class="text-[10px] uppercase tracking-widest text-slate-500">
                                Fora do ranking ({{ blockedPeople.length }})
                            </p>
                            <div
                                v-for="row in blockedPeople" :key="row.participant_id"
                                class="flex items-center gap-1.5 text-xs opacity-60"
                            >
                                <PixelAvatar :seed="row.avatar_seed" :gender="row.gender" :size="18" />
                                <span class="min-w-0">
                                    <span class="block truncate text-slate-300 line-through">
                                        {{ row.table_icon }} {{ row.name }}
                                    </span>
                                    <span v-if="row.hotel" class="block text-[10px] text-slate-500 truncate">
                                        🏨 {{ row.hotel }}
                                    </span>
                                </span>
                                <span class="ml-auto tabular-nums text-slate-500">{{ row.points }}</span>
                                <button
                                    class="text-[10px] font-bold px-2 py-0.5 rounded-lg bg-slate-800 text-slate-300 hover:bg-slate-700 transition"
                                    :disabled="busy === `block-${row.participant_id}`"
                                    @click="setBlocked(row.participant_id, false)"
                                >
                                    liberar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- resultado da rodada revelada, para o facilitador narrar -->
                <div v-if="revealed && results" class="rounded-3xl bg-slate-900/70 ring-1 ring-white/10 p-4 space-y-1.5">
                    <p class="text-[10px] uppercase tracking-widest text-fuchsia-300 font-bold">Rodada revelada</p>

                    <!-- a rodada final revela o placar, não a distribuição -->
                    <template v-if="results.manual">
                        <div
                            v-for="(row, i) in results.tables.filter((t) => t.scored)" :key="row.table_id"
                            class="flex items-center gap-2 text-xs"
                        >
                            <span class="text-slate-500 tabular-nums w-5">{{ i + 1 }}º</span>
                            <span class="truncate flex-1 text-slate-300">{{ row.icon }} {{ row.name }}</span>
                            <span class="tabular-nums font-black w-10 text-right text-white">{{ row.points }}</span>
                        </div>
                        <p v-if="!results.total_votes" class="text-xs text-slate-500 italic">
                            Nenhuma mesa pontuada ainda.
                        </p>
                    </template>

                    <div
                        v-for="option in results.manual ? [] : results.options" :key="option.option_id"
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
