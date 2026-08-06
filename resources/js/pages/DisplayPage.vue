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

// a rodada final: uma missão só, sem alternativas, pontuada pelo facilitador
const isFinalRound = computed(() => question.value?.manual_scoring === true)
const finalRound = computed(() => state.value?.final_round ?? null)
const finalScores = computed(() =>
    (finalRound.value?.scores ?? [])
        .filter((row) => row.scored)
        .sort((a, b) => b.points - a.points),
)

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
    // fim da Fase 2 com empate na liderança: a sala precisa ver *quem* empatou
    // antes de entender a rodada a mais. Sem pontuação — ela sai no fecho.
    if (needsTieBreak.value && event.value.phase >= event.value.last_phase) return 'tiebreak'
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

// as mesas ainda empatadas — só os nomes, sem pontuação: é o que faz a sala
// entender por que existe uma rodada a mais
const tiedTables = computed(() => state.value?.tied_tables ?? [])
const needsTieBreak = computed(() => state.value?.needs_tie_break ?? false)

// quem somou mais no evento inteiro: a própria decisão mais a da mesa
const bestCombined = computed(() => {
    const rows = state.value?.individual_ranking ?? []

    return [...rows].sort((a, b) => b.combined_points - a.combined_points)[0] ?? null
})

// --- premiação -----------------------------------------------------------

// O pódio na ordem em que se olha para ele: 2º à esquerda, campeã ao centro.
//
// A altura é **mínima**, não fixa. Com `h-*`, os 80px do terceiro lugar menos
// os 40px de padding deixavam 40px para medalha + posição + nome + pontos:
// o nome da mesa era empurrado para fora da caixa e sumia do telão. A escada
// do pódio continua — só deixou de cortar o que ela deveria anunciar.
const podium = computed(() => {
    const [first, second, third] = tableRanking.value ?? []

    return [
        { medal: '🥈', place: 2, row: second, height: 'min-h-32', tone: 'text-slate-200' },
        { medal: '🏆', place: 1, row: first, height: 'min-h-44', tone: 'text-amber-300' },
        { medal: '🥉', place: 3, row: third, height: 'min-h-28', tone: 'text-orange-300' },
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

// Só as mesas que jogaram. Numa sala em que nem toda mesa encheu, as vazias
// entram no ranking com zero e empurram o placar real para fora da tela.
const scoredTables = computed(() =>
    (tableRanking.value ?? []).filter((row) => row.phase_one_votes > 0 || row.phase_two_votes > 0),
)

// Cores das barras da revelação. A cor real da alternativa vem da régua de
// pontos (verde = melhor, vermelho = pior), então antes do gabarito ela não
// pode aparecer: estas quatro só diferenciam A/B/C/D, sem sugerir certo ou
// errado.
const NEUTRAL_BARS = ['#6366f1', '#22d3ee', '#a78bfa', '#f472b6']
const barColor = (option, i) => option.color ?? NEUTRAL_BARS[i % NEUTRAL_BARS.length]

// gabarito e comparativo só chegam no payload depois do clique que os libera
const answerKey = computed(() => state.value?.answer_key ?? [])
const comparison = computed(() => state.value?.phase_comparison ?? null)

// O fecho em números. As duas fases jogam os mesmos cinco cenários, então os
// dois lados têm acerto e média por decisão — a rodada final, pontuada à mão,
// fica fora das duas medidas (entra só nos pontos).
const closing = computed(() => {
    if (!comparison.value) return null

    return {
        accuracy: comparison.value.individual.accuracy,
        tableAccuracy: comparison.value.table.accuracy,
        individual: comparison.value.individual.average,
        table: comparison.value.table.average,
        delta: comparison.value.average_delta,
        won: comparison.value.consensus_won,
    }
})
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
                DESEMPATE. Só quem empatou, sem número nenhum: a sala precisa
                entender por que há uma rodada a mais, e o placar que explicaria
                isso é justamente o que ainda não pode aparecer.
            -->
            <main v-else-if="view === 'tiebreak'" key="tiebreak" class="flex-1 grid place-items-center p-10">
                <div class="w-full max-w-5xl text-center space-y-8">
                    <div class="space-y-2">
                        <p class="text-sm font-black uppercase tracking-[0.4em] text-rose-300">Empate na liderança</p>
                        <h2 class="text-5xl font-black text-white">
                            {{ tiedTables.length }} mesas terminaram iguais
                        </h2>
                        <p class="text-slate-400 text-lg">
                            Vamos ao desempate. Mesma régua, decisão da mesa.
                        </p>
                    </div>

                    <div class="flex flex-wrap justify-center gap-5">
                        <div
                            v-for="mesa in tiedTables"
                            :key="mesa.table_id"
                            class="rounded-3xl px-8 py-7 ring-2 bg-slate-900/70 animate-pop"
                            :style="{ borderColor: mesa.color }"
                            :class="'ring-white/15'"
                        >
                            <p class="text-6xl">{{ mesa.icon }}</p>
                            <p class="text-2xl font-black text-white mt-2">{{ mesa.name }}</p>
                        </div>
                    </div>

                    <p class="text-slate-500 text-sm">A pontuação sai no fecho.</p>
                </div>
            </main>

            <!--
                O COMPARATIVO — o fecho da dinâmica. A Fase 1 tem gabarito, a
                rodada final não: o que se compara entre elas é valor gerado por
                decisão, sozinho contra junto.
            -->
            <main v-else-if="view === 'comparison'" key="comparison" class="flex-1 grid place-items-center p-8 min-h-0">
                <div class="w-full max-w-6xl flex flex-col gap-6 min-h-0">
                    <div class="text-center space-y-1">
                        <p class="text-sm font-bold uppercase tracking-[0.4em] text-emerald-300">O comparativo</p>
                        <h2 class="text-4xl font-black text-white">Sozinho e depois em mesa</h2>
                        <p class="text-slate-400">
                            Os mesmos cinco cenários, decididos sozinho e depois em mesa.
                            Só mudou quem decidiu.
                        </p>
                    </div>

                    <!-- o número grande: valor gerado por decisão -->
                    <div v-if="closing" class="grid grid-cols-[1fr_auto_1fr] gap-6 items-center">
                        <div class="rounded-3xl bg-slate-900/70 ring-1 ring-white/10 p-7 text-center">
                            <p class="text-xs font-bold uppercase tracking-widest text-sky-300">Decidindo sozinho</p>
                            <p class="text-7xl font-black text-white tabular-nums mt-1">{{ closing.individual }}</p>
                            <p class="text-sm text-slate-500 mt-1">
                                pts por decisão · Fase 1
                                <span v-if="closing.accuracy !== null"> · ✔ {{ closing.accuracy }}% de acerto</span>
                            </p>
                        </div>

                        <div class="text-center">
                            <p class="text-5xl text-slate-600">→</p>
                            <p
                                class="text-3xl font-black tabular-nums mt-1"
                                :class="closing.delta > 0 ? 'text-emerald-300' : closing.delta < 0 ? 'text-rose-300' : 'text-slate-400'"
                            >
                                {{ closing.delta > 0 ? '+' : '' }}{{ closing.delta }}
                            </p>
                        </div>

                        <div
                            class="rounded-3xl p-7 text-center ring-2"
                            :class="closing.won ? 'bg-emerald-500/10 ring-emerald-400/60' : 'bg-slate-900/70 ring-white/10'"
                        >
                            <p class="text-xs font-bold uppercase tracking-widest text-emerald-300">Decidindo em mesa</p>
                            <p
                                class="text-7xl font-black tabular-nums mt-1"
                                :class="closing.won ? 'text-emerald-300' : 'text-white'"
                            >
                                {{ closing.table }}
                            </p>
                            <p class="text-sm text-emerald-200/60 mt-1">
                                pts por decisão · Fase 2
                                <span v-if="closing.tableAccuracy !== null"> · ✔ {{ closing.tableAccuracy }}% de acerto</span>
                            </p>
                        </div>
                    </div>

                    <!--
                        PONTUAÇÃO em cima, nas duas colunas que a sala procura —
                        "onde eu fiquei" e "onde minha mesa ficou" —, e o
                        GABARITO embaixo, ocupando a largura toda: ele é leitura
                        corrida, cinco linhas de texto, e espremido numa lateral
                        virava reticências.
                    -->
                    <div class="grid grid-cols-2 gap-5 shrink-0">
                        <section class="rounded-3xl bg-slate-900/60 ring-1 ring-white/10 p-4 flex flex-col">
                            <p class="text-[11px] font-black uppercase tracking-[0.3em] text-sky-300 shrink-0">
                                Individual
                            </p>
                            <div class="mt-2 space-y-0.5">
                                <div
                                    v-for="person in topIndividuals"
                                    :key="person.participant_id"
                                    class="flex items-center gap-2.5 text-sm rounded-lg px-2 py-1"
                                    :class="person.position === 1 ? 'bg-sky-500/15' : ''"
                                >
                                    <span class="w-6 text-right tabular-nums text-slate-500 shrink-0">{{ person.position }}º</span>
                                    <PixelAvatar :seed="person.avatar_seed" :gender="person.gender" :size="22" />
                                    <span class="flex-1 min-w-0 truncate text-slate-200 font-bold">{{ person.name }}</span>
                                    <span class="tabular-nums font-black text-white shrink-0">{{ person.points }}</span>
                                </div>
                                <p v-if="!topIndividuals.length" class="text-sm text-slate-500 italic">Sem votos.</p>
                            </div>
                        </section>

                        <section class="rounded-3xl bg-slate-900/60 ring-1 ring-white/10 p-4 flex flex-col">
                            <p class="text-[11px] font-black uppercase tracking-[0.3em] text-emerald-300 shrink-0">
                                Mesa
                            </p>
                            <div class="mt-2 space-y-0.5">
                                <div
                                    v-for="row in scoredTables.slice(0, 6)"
                                    :key="row.table_id"
                                    class="flex items-center gap-2.5 text-sm rounded-lg px-2 py-1"
                                    :class="row.position === 1 ? 'bg-emerald-500/15' : ''"
                                >
                                    <span class="w-6 text-right tabular-nums text-slate-500 shrink-0">{{ row.position }}º</span>
                                    <span class="shrink-0 text-lg">{{ row.icon }}</span>
                                    <span class="flex-1 min-w-0 truncate text-slate-200 font-bold">{{ row.name }}</span>
                                    <span class="tabular-nums font-black text-white shrink-0">{{ row.total_points }}</span>
                                </div>
                                <p v-if="!scoredTables.length" class="text-sm text-slate-500 italic">Sem decisões de mesa.</p>
                            </div>
                        </section>
                    </div>

                    <section class="flex-1 rounded-3xl bg-slate-900/60 ring-1 ring-white/10 p-4 flex flex-col min-h-0">
                        <div class="flex items-baseline gap-3 shrink-0">
                            <p class="text-[11px] font-black uppercase tracking-[0.3em] text-amber-300">Gabarito</p>
                            <p class="text-[11px] text-slate-500">a melhor decisão de cada cenário</p>
                            <div class="ml-auto flex items-center gap-3 text-[10px] uppercase tracking-widest">
                                <span class="text-slate-500">Índice de respostas</span>
                                <span class="text-sky-300">sozinho</span>
                                <span class="text-emerald-300">em mesa</span>
                            </div>
                        </div>

                        <div class="flex-1 min-h-0 overflow-y-auto mt-3 space-y-2 pr-1">
                            <div
                                v-for="row in answerKey"
                                :key="row.round"
                                class="rounded-2xl bg-slate-950/50 ring-1 ring-white/10 px-4 py-2.5 grid grid-cols-[1fr_auto] gap-4 items-center"
                            >
                                <div class="min-w-0">
                                    <p class="text-[10px] font-black uppercase tracking-widest text-amber-300">
                                        Rodada {{ row.round }} · {{ row.label }}
                                    </p>
                                    <p class="text-sm text-white font-bold truncate mt-0.5">
                                        ✅ {{ row.best_text }}
                                        <span class="text-emerald-300 tabular-nums ml-1">+{{ row.best_points }}</span>
                                    </p>
                                </div>

                                <div class="flex items-center gap-4 shrink-0">
                                    <div class="text-right">
                                        <p class="text-lg font-black tabular-nums text-sky-300 leading-none">
                                            {{ row.individual_accuracy ?? '—' }}<span class="text-xs">%</span>
                                        </p>
                                        <p class="text-[9px] uppercase tracking-widest text-slate-600 mt-0.5">sozinho</p>
                                    </div>
                                    <div class="text-right w-16">
                                        <p
                                            class="text-lg font-black tabular-nums leading-none"
                                            :class="row.table_accuracy !== null && row.individual_accuracy !== null
                                                && row.table_accuracy > row.individual_accuracy
                                                ? 'text-emerald-300' : 'text-slate-300'"
                                        >
                                            {{ row.table_accuracy ?? '—' }}<span class="text-xs">%</span>
                                        </p>
                                        <p class="text-[9px] uppercase tracking-widest text-slate-600 mt-0.5">em mesa</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

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
                            {{ question?.label }} · {{ results.manual ? 'a pontuação da rodada' : 'como a sala decidiu' }}
                        </p>
                        <h2 class="text-3xl font-black text-white">{{ question?.title }}</h2>
                    </div>

                    <!-- rodada final: sem alternativas, o que se revela é o placar -->
                    <div v-if="results.manual" class="grid grid-cols-2 gap-3">
                        <div
                            v-for="(row, i) in results.tables.filter((t) => t.scored)"
                            :key="row.table_id"
                            class="rounded-2xl p-4 ring-1 grid grid-cols-[auto_1fr_auto] gap-3 items-center"
                            :class="i === 0 ? 'bg-amber-400/10 ring-2 ring-amber-400/60' : 'bg-slate-900/50 ring-white/10'"
                        >
                            <span class="text-slate-500 font-black tabular-nums">{{ i + 1 }}º</span>
                            <p class="font-black text-white text-lg truncate">{{ row.icon }} {{ row.name }}</p>
                            <p class="text-3xl font-black tabular-nums" :class="i === 0 ? 'text-amber-300' : 'text-white'">
                                {{ row.points }}
                            </p>
                        </div>
                        <p v-if="!results.total_votes" class="col-span-2 text-center text-slate-500 italic">
                            Nenhuma mesa pontuada ainda.
                        </p>
                    </div>

                    <div v-else class="space-y-3">
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
                        {{
                            results.manual
                                ? 'Pontuação lançada pelo facilitador — a mesa decidiu livremente.'
                                : 'Qual era a melhor decisão para o hotel? Só no placar final.'
                        }}
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
                        <!-- na rodada final o cenário é a própria missão, e ela
                             já ocupa o painel maior à direita -->
                        <p v-if="question?.context && !isFinalRound" class="text-sm text-slate-400 leading-relaxed">
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
                    <!--
                        rodada final: não há alternativas. O que fica no telão é
                        a missão que todas as mesas receberam.
                    -->
                    <div
                        v-if="isFinalRound"
                        class="rounded-3xl bg-gradient-to-br from-amber-500/15 to-fuchsia-500/10 ring-2 ring-amber-400/40 p-6 space-y-2"
                    >
                        <p class="text-[11px] font-black uppercase tracking-[0.3em] text-amber-300">
                            A missão de todas as mesas
                        </p>
                        <p class="text-2xl font-black text-white leading-snug">“{{ question?.context }}”</p>
                        <p class="text-sm text-slate-400">
                            Sem alternativas: cada mesa decide livremente, e a pontuação é do facilitador.
                        </p>
                    </div>

                    <div v-else class="grid grid-cols-2 gap-3">
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
                                <!-- os cinco cenários de mesa mais a rodada
                                     final, que o facilitador pontuou à mão -->
                                <p class="text-xl font-black text-emerald-300 tabular-nums">
                                    {{ winner.phase_two_points }} pts
                                </p>
                                <p class="text-[9px] uppercase tracking-widest text-slate-500">decidindo em mesa</p>
                            </div>
                            <div class="ml-auto text-right">
                                <BrandLogo size="sm" />
                            </div>
                        </div>
                    </section>

                    <aside class="flex flex-col gap-5 min-h-0">
                        <!--
                            Um nome de cada. Uma lista de seis não premia
                            ninguém: no telão o olho não percorre ranking, ele
                            procura o vencedor. E a lista de "demais mesas"
                            existia para as pessoas se acharem — mas o que elas
                            querem achar é a própria mesa no pódio, não a 14ª
                            linha de uma tabela.
                        -->
                        <section class="rounded-3xl bg-slate-900/60 ring-1 ring-sky-400/30 p-6 flex-1 flex flex-col justify-center">
                            <p class="text-[11px] font-black uppercase tracking-[0.3em] text-sky-300">
                                Melhor decisão individual
                            </p>
                            <div v-if="topIndividuals[0]" class="mt-4 flex items-center gap-4">
                                <PixelAvatar
                                    :seed="topIndividuals[0].avatar_seed"
                                    :gender="topIndividuals[0].gender"
                                    :size="72"
                                />
                                <div class="min-w-0">
                                    <p class="text-2xl font-black text-white truncate">{{ topIndividuals[0].name }}</p>
                                    <p class="text-xs text-slate-400 truncate">
                                        {{ topIndividuals[0].table_icon }} {{ topIndividuals[0].table }}
                                        · ✔ {{ topIndividuals[0].correct }}/{{ topIndividuals[0].answered }}
                                    </p>
                                    <p class="text-3xl font-black text-sky-300 tabular-nums mt-1">
                                        {{ topIndividuals[0].points }} <span class="text-sm text-slate-500">pts sozinho</span>
                                    </p>
                                </div>
                            </div>
                            <p v-else class="text-sm text-slate-500 italic mt-3">Ninguém votou.</p>
                        </section>

                        <!--
                            A soma que ninguém vê durante o evento: o que a
                            pessoa fez sozinha mais o que a mesa dela fez junto.
                            É o número que responde "e eu, no fim das contas?".
                        -->
                        <section class="rounded-3xl bg-slate-900/60 ring-1 ring-amber-400/30 p-6 flex-1 flex flex-col justify-center">
                            <p class="text-[11px] font-black uppercase tracking-[0.3em] text-amber-300">
                                Maior pontuação total
                            </p>
                            <p class="text-[10px] text-slate-500 mt-0.5">decisão própria + decisão da mesa</p>
                            <div v-if="bestCombined" class="mt-4 flex items-center gap-4">
                                <PixelAvatar
                                    :seed="bestCombined.avatar_seed"
                                    :gender="bestCombined.gender"
                                    :size="72"
                                />
                                <div class="min-w-0">
                                    <p class="text-2xl font-black text-white truncate">{{ bestCombined.name }}</p>
                                    <p class="text-xs text-slate-400 truncate">
                                        {{ bestCombined.table_icon }} {{ bestCombined.table }}
                                    </p>
                                    <p class="text-3xl font-black text-amber-300 tabular-nums mt-1">
                                        {{ bestCombined.combined_points }} <span class="text-sm text-slate-500">pts</span>
                                    </p>
                                    <p class="text-[11px] text-slate-500 tabular-nums">
                                        {{ bestCombined.points }} sozinho + {{ bestCombined.table_points }} em mesa
                                    </p>
                                </div>
                            </div>
                            <p v-else class="text-sm text-slate-500 italic mt-3">Sem pontuação ainda.</p>
                        </section>
                    </aside>
                </div>
            </main>

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
