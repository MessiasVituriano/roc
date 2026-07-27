<script setup>
import { computed } from 'vue'
import { api } from '../lib/api'
import { usePolling } from '../composables/usePolling'
import AuditoriumMap from '../components/AuditoriumMap.vue'
import CountdownTimer from '../components/CountdownTimer.vue'
import ProgressBar from '../components/ProgressBar.vue'
import ScoreBars from '../components/ScoreBars.vue'
import JoinQrCode from '../components/JoinQrCode.vue'
import BrandLogo from '../components/BrandLogo.vue'
import LoadingScreen from '../components/LoadingScreen.vue'
import PixelAvatar from '../components/PixelAvatar.vue'

const { data: state, error: stateError, online } = usePolling(() => api.get('/display'), { interval: 1000 })

const event = computed(() => state.value?.event ?? null)
const tables = computed(() => state.value?.tables ?? [])
const question = computed(() => state.value?.question ?? null)
const timer = computed(() => state.value?.timer ?? { remaining: 0, duration: 1 })
const progress = computed(() => state.value?.progress ?? { answered: 0, total: 0, percent: 0, unit: 'participants' })
const stats = computed(() => state.value?.stats ?? { participants: 0, connected: 0, tables: 0 })
const results = computed(() => state.value?.results ?? null)
const missionRanking = computed(() => state.value?.mission_ranking ?? null)
const tableRanking = computed(() => state.value?.table_ranking ?? [])

const individual = computed(() => event.value?.phase_mode === 'individual')
const unitLabel = computed(() => (progress.value.unit === 'participants' ? 'votos' : 'mesas'))

const view = computed(() => {
    if (!event.value) return 'lobby'
    if (event.value.status === 'finished') return 'final'
    // a rodada tem prioridade: votando ou recém-revelada, o telão acompanha a
    // rodada. Os dois momentos do facilitador — placar por missão e comparativo
    // entre as fases — só aparecem ENTRE rodadas (rodada parada), nunca
    // mascarando a revelação que acabou de acontecer.
    if (event.value.round_status === 'voting') return 'voting'
    if (event.value.round_status === 'revealed') return 'reveal'
    if (event.value.answers_revealed) return 'comparison'
    if (event.value.missions_revealed) return 'missions'
    return 'lobby'
})

// escala as barras pela maior média, para o contraste entre missões saltar
const missionRows = computed(() => {
    const rows = missionRanking.value ?? []
    const max = Math.max(1, ...rows.map((r) => Math.abs(r.average)))

    return rows.map((r) => ({
        key: r.mission_id,
        label: r.name,
        icon: r.icon,
        color: r.color,
        value: r.average,
        sublabel: r.accuracy !== null
            ? `${r.participants} pessoas · ✔ ${r.accuracy}% de acerto · ${r.points} pts`
            : `${r.participants} pessoas · ${r.points} pts`,
        percent: Math.round((Math.abs(r.average) / max) * 100),
    }))
})

const winner = computed(() => tableRanking.value?.[0] ?? null)

// --- premiação -----------------------------------------------------------

// o pódio na ordem em que se olha para ele: 2º à esquerda, campeã ao centro
const podium = computed(() => {
    const [first, second, third] = tableRanking.value ?? []

    return [
        { medal: '🥈', place: 2, row: second, height: 'h-28', tone: 'text-slate-200' },
        { medal: '🏆', place: 1, row: first, height: 'h-40', tone: 'text-amber-300' },
        { medal: '🥉', place: 3, row: third, height: 'h-20', tone: 'text-orange-300' },
    ].filter((slot) => slot.row)
})

// quem exatamente ganhou: os integrantes vêm do mapa do auditório, que o
// payload já carrega — não precisa de chamada nova
const champions = computed(() => {
    if (!winner.value) return []

    return tables.value.find((t) => t.id === winner.value.table_id)?.participants ?? []
})

// as pessoas que mais acertaram sozinhas, independente de mesa
const topIndividuals = computed(() => (state.value?.individual_ranking ?? []).slice(0, 6))

// da 4ª mesa em diante: quem não subiu ao pódio ainda quer se achar na lista
const runnersUp = computed(() => (tableRanking.value ?? []).slice(3))

// Cores das barras da revelação. A cor real da alternativa vem da régua de
// pontos (verde = melhor, vermelho = pior), então antes do gabarito ela não
// pode aparecer: estas quatro só diferenciam A/B/C/D, sem sugerir certo ou
// errado.
const NEUTRAL_BARS = ['#6366f1', '#22d3ee', '#a78bfa', '#f472b6']
const barColor = (option, i) => option.color ?? NEUTRAL_BARS[i % NEUTRAL_BARS.length]

// gabarito e comparativo só chegam no payload depois do clique que os libera
const answerKey = computed(() => state.value?.answer_key ?? [])
const comparison = computed(() => state.value?.phase_comparison ?? null)

// a tese da dinâmica em dois números, calculados no servidor sobre todos os
// votos das duas fases
const accuracy = computed(() => {
    if (!comparison.value) return null

    return {
        individual: comparison.value.individual.accuracy,
        table: comparison.value.table.accuracy,
        delta: comparison.value.accuracy_delta,
        won: comparison.value.consensus_won,
    }
})

// escala as duas barras de cada rodada pela mesma régua (0–100%)
const comparisonRows = computed(() =>
    answerKey.value.map((row) => ({
        ...row,
        delta: row.individual_accuracy !== null && row.table_accuracy !== null
            ? row.table_accuracy - row.individual_accuracy
            : null,
    })),
)
</script>

<template>
    <!-- telão: até o primeiro /display voltar, a marca segura a tela -->
    <LoadingScreen
        v-if="!state"
        :label="stateError ? 'Sem conexão com o servidor — reconectando…' : 'Conectando ao telão…'"
    />

    <div v-else class="min-h-dvh flex flex-col overflow-hidden">
        <header class="px-8 py-4 flex items-center gap-5 bg-slate-950/70 ring-1 ring-white/5">
            <BrandLogo size="sm" />
            <span class="w-px h-8 bg-white/10" />
            <h1 class="text-2xl font-black bg-gradient-to-r from-amber-200 via-amber-300 to-amber-100 bg-clip-text text-transparent">
                {{ event?.title ?? 'Sala de Decisões ROC' }}
            </h1>
            <div v-if="event" class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest">
                <span class="px-3 py-1 rounded-full bg-indigo-500/20 text-indigo-200">
                    Fase {{ event.phase }} de {{ event.last_phase }}
                </span>
                <span
                    class="px-3 py-1 rounded-full"
                    :class="individual ? 'bg-sky-500/20 text-sky-200' : 'bg-amber-500/20 text-amber-200'"
                >
                    {{ individual ? '👤 Decisão individual' : '🤝 Decisão da mesa' }}
                </span>
                <span v-if="event.total_rounds" class="px-3 py-1 rounded-full bg-slate-700/50 text-slate-200">
                    Rodada {{ event.round }}/{{ event.total_rounds }}
                </span>
            </div>
            <div class="ml-auto flex items-center gap-6 text-slate-300">
                <div class="text-center">
                    <p class="text-2xl font-black tabular-nums text-white">{{ stats.connected }}</p>
                    <p class="text-[10px] uppercase tracking-widest text-slate-500">Conectados</p>
                </div>
                <span
                    class="w-3 h-3 rounded-full"
                    :class="online ? 'bg-emerald-400 shadow-[0_0_12px] shadow-emerald-400' : 'bg-rose-500 animate-pulse'"
                />
            </div>
        </header>

        <Transition name="fade" mode="out-in">
            <!-- A VIRADA DE FASE: o viés de cada missão aparece no placar -->
            <main v-if="view === 'missions'" key="missions" class="flex-1 grid place-items-center p-10">
                <div class="w-full max-w-5xl space-y-8">
                    <div class="text-center space-y-2">
                        <p class="text-sm font-bold uppercase tracking-[0.4em] text-amber-300">A virada</p>
                        <h2 class="text-4xl font-black text-white">Média de valor gerado por missão</h2>
                        <p class="text-slate-400">
                            Mesma régua, missões diferentes — e decisões diferentes.
                        </p>
                    </div>
                    <ScoreBars :rows="missionRows" suffix=" pts" />
                    <p class="text-center text-lg text-slate-300 italic max-w-3xl mx-auto">
                        “Se eu retirasse o nome das missões e mostrasse só os números do hotel,
                        você ainda tomaria a mesma decisão?”
                    </p>
                </div>
            </main>

            <!--
                O COMPARATIVO — o fecho da dinâmica. As duas fases fizeram as
                mesmas perguntas, então a diferença de acerto isola uma variável
                só: decidir sozinho contra decidir junto.
            -->
            <main v-else-if="view === 'comparison'" key="comparison" class="flex-1 grid place-items-center p-8 min-h-0">
                <div class="w-full max-w-6xl flex flex-col gap-6 min-h-0">
                    <div class="text-center space-y-1">
                        <p class="text-sm font-bold uppercase tracking-[0.4em] text-emerald-300">O comparativo</p>
                        <h2 class="text-4xl font-black text-white">Mesma pergunta, decisão diferente</h2>
                        <p class="text-slate-400">
                            As cinco rodadas da Fase 2 foram as mesmas da Fase 1. Só mudou quem decidiu.
                        </p>
                    </div>

                    <!-- o número grande -->
                    <div v-if="accuracy" class="grid grid-cols-[1fr_auto_1fr] gap-6 items-center">
                        <div class="rounded-3xl bg-slate-900/70 ring-1 ring-white/10 p-7 text-center">
                            <p class="text-xs font-bold uppercase tracking-widest text-sky-300">Decidindo sozinho</p>
                            <p class="text-7xl font-black text-white tabular-nums mt-1">{{ accuracy.individual ?? '—' }}%</p>
                            <p class="text-sm text-slate-500 mt-1">de acerto · Fase 1</p>
                        </div>

                        <div class="text-center">
                            <p class="text-5xl text-slate-600">→</p>
                            <p
                                v-if="accuracy.delta !== null"
                                class="text-3xl font-black tabular-nums mt-1"
                                :class="accuracy.delta > 0 ? 'text-emerald-300' : accuracy.delta < 0 ? 'text-rose-300' : 'text-slate-400'"
                            >
                                {{ accuracy.delta > 0 ? '+' : '' }}{{ accuracy.delta }}pp
                            </p>
                        </div>

                        <div
                            class="rounded-3xl p-7 text-center ring-2"
                            :class="accuracy.won ? 'bg-emerald-500/10 ring-emerald-400/60' : 'bg-slate-900/70 ring-white/10'"
                        >
                            <p class="text-xs font-bold uppercase tracking-widest text-emerald-300">Decidindo em mesa</p>
                            <p
                                class="text-7xl font-black tabular-nums mt-1"
                                :class="accuracy.won ? 'text-emerald-300' : 'text-white'"
                            >
                                {{ accuracy.table ?? '—' }}%
                            </p>
                            <p class="text-sm text-emerald-200/60 mt-1">de acerto · Fase 2</p>
                        </div>
                    </div>

                    <!-- rodada a rodada, as duas barras na mesma régua -->
                    <div class="flex-1 min-h-0 overflow-y-auto space-y-2 pr-1">
                        <div
                            v-for="row in comparisonRows"
                            :key="row.round"
                            class="rounded-2xl bg-slate-900/60 ring-1 ring-white/10 px-4 py-3 grid grid-cols-[minmax(0,20rem)_1fr_auto] gap-4 items-center"
                        >
                            <div class="min-w-0">
                                <p class="text-[10px] font-black uppercase tracking-widest text-amber-300">
                                    Rodada {{ row.round }} · {{ row.label }}
                                </p>
                                <p class="text-xs text-white font-bold truncate mt-0.5">✅ {{ row.best_text }}</p>
                            </div>

                            <div class="space-y-1.5">
                                <div class="flex items-center gap-2">
                                    <span class="text-[9px] uppercase tracking-widest text-slate-500 w-14 shrink-0">sozinho</span>
                                    <div class="flex-1 h-3 rounded-full bg-slate-800 overflow-hidden">
                                        <div class="h-full rounded-full bg-sky-400/70" :style="{ width: (row.individual_accuracy ?? 0) + '%' }" />
                                    </div>
                                    <span class="text-xs tabular-nums text-slate-300 w-9 text-right shrink-0">{{ row.individual_accuracy ?? '—' }}%</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-[9px] uppercase tracking-widest text-slate-500 w-14 shrink-0">em mesa</span>
                                    <div class="flex-1 h-3 rounded-full bg-slate-800 overflow-hidden">
                                        <div class="h-full rounded-full bg-emerald-400" :style="{ width: (row.table_accuracy ?? 0) + '%' }" />
                                    </div>
                                    <span class="text-xs tabular-nums text-emerald-300 font-bold w-9 text-right shrink-0">{{ row.table_accuracy ?? '—' }}%</span>
                                </div>
                            </div>

                            <p
                                v-if="row.delta !== null"
                                class="text-xl font-black tabular-nums w-16 text-right"
                                :class="row.delta > 0 ? 'text-emerald-300' : row.delta < 0 ? 'text-rose-300' : 'text-slate-500'"
                            >
                                {{ row.delta > 0 ? '+' : '' }}{{ row.delta }}
                            </p>
                        </div>
                    </div>

                    <p class="text-center text-lg text-slate-300 italic">
                        “Se eu retirasse o nome das missões e mostrasse só os números do hotel,
                        você ainda tomaria a mesma decisão?”
                    </p>
                </div>
            </main>

            <!--
                REVELAÇÃO da rodada: só a divergência da sala.
                Nada de melhor alternativa, pontos ou efeito — a Fase 2 repete
                estas perguntas, e o gabarito aqui entregaria a resposta de lá.
            -->
            <main v-else-if="view === 'reveal' && results" key="reveal" class="flex-1 grid place-items-center p-8">
                <div class="w-full max-w-6xl space-y-6">
                    <div class="text-center space-y-1">
                        <p class="text-xs font-bold uppercase tracking-[0.4em] text-fuchsia-300">
                            {{ question?.label }} · como a sala decidiu
                        </p>
                        <h2 class="text-3xl font-black text-white">{{ question?.title }}</h2>
                    </div>

                    <div class="space-y-3">
                        <div
                            v-for="(option, i) in results.options"
                            :key="option.option_id"
                            class="rounded-2xl p-4 ring-1 ring-white/10 bg-slate-900/50 grid grid-cols-[1fr_auto] gap-4 items-center"
                        >
                            <div class="min-w-0">
                                <p class="font-black text-white text-lg">
                                    <span class="text-amber-300 mr-1">{{ 'ABCD'[i] }})</span>
                                    {{ option.text }}
                                </p>
                                <div class="mt-2 h-2.5 rounded-full bg-slate-800 overflow-hidden">
                                    <div
                                        class="h-full rounded-full transition-all duration-1000"
                                        :style="{ width: option.percent + '%', background: barColor(option, i) }"
                                    />
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-3xl font-black tabular-nums text-white">{{ option.percent }}%</p>
                                <p class="text-sm text-slate-400 tabular-nums">
                                    {{ option.votes }} {{ results.unit === 'tables' ? 'mesas' : 'votos' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <p class="text-center text-sm text-slate-500">
                        Qual era a melhor decisão para o hotel? Só no placar final.
                    </p>
                </div>
            </main>

            <!-- VOTAÇÃO: pergunta, tempo e votos chegando. Nunca a distribuição. -->
            <main v-else-if="view === 'voting'" key="voting" class="flex-1 grid grid-cols-[minmax(0,26rem)_1fr] gap-6 p-6 min-h-0">
                <aside class="flex flex-col gap-5">
                    <div class="rounded-3xl bg-slate-900/70 ring-1 ring-white/10 p-6 space-y-4">
                        <span class="inline-block px-3 py-1 rounded-full text-[11px] font-black uppercase tracking-widest bg-amber-500/20 text-amber-200">
                            {{ question?.label }}
                        </span>
                        <h2 class="text-2xl font-black text-white leading-snug">{{ question?.title }}</h2>
                        <p v-if="question?.context" class="text-sm text-slate-400 leading-relaxed">
                            {{ question.context }}
                        </p>
                        <div class="grid place-items-center pt-2">
                            <CountdownTimer :remaining="timer.remaining" :duration="timer.duration" :size="150" />
                        </div>
                    </div>

                    <div class="rounded-3xl bg-slate-900/70 ring-1 ring-white/10 p-6 space-y-3 mt-auto">
                        <ProgressBar :value="progress.percent" height="h-5" />
                        <p class="text-center text-2xl font-black text-white tabular-nums">
                            {{ progress.answered }} de {{ progress.total }}
                            <span class="text-slate-400 font-semibold text-base">{{ unitLabel }}</span>
                        </p>
                    </div>
                </aside>

                <!-- as alternativas sem nenhuma pista de pontuação -->
                <div class="flex flex-col gap-4 min-h-0">
                    <div class="grid grid-cols-2 gap-3">
                        <div
                            v-for="(option, i) in question?.options"
                            :key="option.id"
                            class="rounded-2xl bg-slate-900/70 ring-1 ring-white/10 p-4"
                        >
                            <p class="text-white font-bold">
                                <span class="text-amber-300 font-black mr-1">{{ 'ABCD'[i] }})</span>
                                {{ option.text }}
                            </p>
                        </div>
                    </div>
                    <AuditoriumMap :tables="tables" :mode="event?.phase_mode ?? 'individual'" compact class="flex-1 min-h-0" />
                </div>
            </main>

            <!--
                PREMIAÇÃO. O gabarito e os percentuais têm tela própria (o
                comparativo), então aqui não se repete número: aqui se premia.
                Pódio das mesas, quem exatamente ganhou, e quem decidiu melhor
                sozinho.
            -->
            <main v-else-if="view === 'final'" key="final" class="flex-1 flex flex-col gap-5 p-6 min-h-0">
                <!-- PÓDIO: 2º à esquerda, campeã ao centro e mais alta -->
                <div class="grid grid-cols-3 gap-5 items-end shrink-0">
                    <div
                        v-for="slot in podium"
                        :key="slot.place"
                        class="rounded-3xl ring-1 flex flex-col justify-end p-5 text-center"
                        :class="[
                            slot.height,
                            slot.place === 1
                                ? 'bg-amber-400/10 ring-2 ring-amber-400/60 shadow-lg shadow-amber-500/20'
                                : 'bg-slate-900/70 ring-white/10',
                        ]"
                    >
                        <p class="leading-none" :class="slot.place === 1 ? 'text-5xl animate-bounce-soft' : 'text-3xl'">
                            {{ slot.medal }}
                        </p>
                        <p class="text-[10px] font-black uppercase tracking-[0.3em] mt-2" :class="slot.tone">
                            {{ slot.place }}º lugar
                        </p>
                        <h2
                            class="font-black text-white truncate"
                            :class="slot.place === 1 ? 'text-3xl mt-1' : 'text-xl mt-0.5'"
                        >
                            {{ slot.row.icon }} {{ slot.row.name }}
                        </h2>
                        <p
                            class="font-black tabular-nums"
                            :class="slot.place === 1 ? 'text-2xl text-amber-300' : 'text-lg text-slate-300'"
                        >
                            {{ slot.row.total_points }} pts
                        </p>
                    </div>
                </div>

                <div class="flex-1 grid grid-cols-[1fr_minmax(0,26rem)] gap-5 min-h-0">
                    <!-- OS CAMPEÕES: quem exatamente ganhou -->
                    <section class="rounded-3xl bg-slate-900/60 ring-1 ring-white/10 p-5 flex flex-col min-h-0">
                        <p class="text-[11px] font-black uppercase tracking-[0.3em] text-amber-300 shrink-0">
                            Os campeões
                            <span v-if="winner" class="text-slate-500 tracking-normal font-bold ml-1">
                                · {{ winner.icon }} {{ winner.name }}
                            </span>
                        </p>

                        <!-- centralizado: uma mesa tem 4 a 8 pessoas, e alinhado
                             ao topo isso deixa metade do painel vazia -->
                        <div class="flex-1 min-h-0 overflow-y-auto mt-4 pr-1 grid place-content-center">
                            <div class="flex flex-wrap justify-center gap-x-7 gap-y-6">
                                <div
                                    v-for="person in champions"
                                    :key="person.id"
                                    class="w-24 text-center animate-pop"
                                >
                                    <div class="grid place-items-center">
                                        <PixelAvatar :seed="person.avatar_seed" :gender="person.gender" :size="72" />
                                    </div>
                                    <p class="text-sm font-bold text-white truncate mt-2">{{ person.name }}</p>
                                </div>
                            </div>
                            <p v-if="!champions.length" class="text-sm text-slate-500 italic">
                                Nenhum participante nesta mesa.
                            </p>
                        </div>

                        <div v-if="winner" class="shrink-0 mt-4 pt-4 border-t border-white/10 flex items-center gap-6">
                            <div>
                                <p class="text-xl font-black text-white tabular-nums">
                                    ✔ {{ winner.phase_one_correct }}/{{ winner.phase_one_votes }}
                                </p>
                                <p class="text-[9px] uppercase tracking-widest text-slate-500">sozinhos</p>
                            </div>
                            <div>
                                <p class="text-xl font-black text-emerald-300 tabular-nums">
                                    ✔ {{ winner.phase_two_correct }}/{{ winner.phase_two_votes }}
                                </p>
                                <p class="text-[9px] uppercase tracking-widest text-slate-500">em mesa</p>
                            </div>
                            <div class="ml-auto text-right">
                                <BrandLogo size="sm" />
                            </div>
                        </div>
                    </section>

                    <aside class="flex flex-col gap-5 min-h-0">
                        <!-- MELHORES DECISÕES INDIVIDUAIS -->
                        <section class="rounded-3xl bg-slate-900/60 ring-1 ring-white/10 p-5 flex flex-col min-h-0 flex-1">
                            <p class="text-[11px] font-black uppercase tracking-[0.3em] text-sky-300 shrink-0">
                                Melhores decisões individuais
                            </p>
                            <div class="flex-1 min-h-0 overflow-y-auto mt-3 space-y-1.5 pr-1">
                                <div
                                    v-for="person in topIndividuals"
                                    :key="person.participant_id"
                                    class="flex items-center gap-2.5 rounded-xl px-2.5 py-1.5"
                                    :class="person.position === 1 ? 'bg-sky-500/15 ring-1 ring-sky-400/40' : ''"
                                >
                                    <span
                                        class="w-6 text-right tabular-nums font-black shrink-0"
                                        :class="person.position === 1 ? 'text-sky-300' : 'text-slate-500'"
                                    >
                                        {{ person.position }}º
                                    </span>
                                    <PixelAvatar :seed="person.avatar_seed" :gender="person.gender" :size="26" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-bold text-white truncate leading-tight">
                                            {{ person.name }}
                                        </span>
                                        <span class="block text-[10px] text-slate-500 truncate">
                                            {{ person.table_icon }} {{ person.table }} · ✔ {{ person.correct }}/{{ person.rounds }}
                                        </span>
                                    </span>
                                    <span class="text-sm font-black tabular-nums text-white shrink-0">
                                        {{ person.points }}
                                    </span>
                                </div>
                                <p v-if="!topIndividuals.length" class="text-sm text-slate-500 italic">
                                    Sem votos registrados.
                                </p>
                            </div>
                        </section>

                        <!-- da 4ª mesa em diante, para todo mundo se achar -->
                        <section
                            v-if="runnersUp.length"
                            class="rounded-3xl bg-slate-900/60 ring-1 ring-white/10 p-4 flex flex-col min-h-0 max-h-56"
                        >
                            <p class="text-[10px] font-black uppercase tracking-[0.3em] text-slate-500 shrink-0">
                                Demais mesas
                            </p>
                            <div class="flex-1 min-h-0 overflow-y-auto mt-2 space-y-1 pr-1">
                                <div
                                    v-for="row in runnersUp"
                                    :key="row.table_id"
                                    class="flex items-center gap-2 text-xs"
                                >
                                    <span class="w-6 text-right tabular-nums text-slate-500 shrink-0">{{ row.position }}º</span>
                                    <span class="shrink-0">{{ row.icon }}</span>
                                    <span class="flex-1 min-w-0 truncate text-slate-300 font-bold">{{ row.name }}</span>
                                    <span class="tabular-nums text-slate-400 shrink-0">{{ row.total_points }} pts</span>
                                </div>
                            </div>
                        </section>
                    </aside>
                </div>
            </main>

            <!-- LOBBY -->
            <main v-else key="lobby" class="flex-1 grid grid-cols-[minmax(0,24rem)_1fr] gap-6 p-6 min-h-0">
                <aside class="flex flex-col gap-6">
                    <!-- a sala enchendo é o momento de maior exposição da marca -->
                    <div class="rounded-3xl bg-slate-900/70 ring-1 ring-white/10 p-6 grid place-items-center">
                        <BrandLogo size="lg" stacked />
                    </div>
                    <div class="rounded-3xl bg-slate-900/70 ring-1 ring-white/10 p-6 text-center space-y-4">
                        <h2 class="text-2xl font-black text-white">Entre na sala</h2>
                        <p class="text-slate-400 text-sm">Aponte a câmera do celular para o QR Code.</p>
                        <JoinQrCode :size="190" />
                    </div>
                    <div class="rounded-3xl bg-slate-900/70 ring-1 ring-white/10 p-6 text-center mt-auto">
                        <p class="text-5xl font-black text-white tabular-nums">{{ stats.participants }}</p>
                        <p class="text-xs uppercase tracking-widest text-slate-500">participantes na sala</p>
                    </div>
                </aside>
                <AuditoriumMap :tables="tables" :mode="event?.phase_mode ?? 'individual'" class="min-h-0" />
            </main>
        </Transition>
    </div>
</template>
