<script setup>
import { computed } from 'vue'
import { api } from '../lib/api'
import { usePolling } from '../composables/usePolling'
import AuditoriumMap from '../components/AuditoriumMap.vue'
import CountdownTimer from '../components/CountdownTimer.vue'
import ProgressBar from '../components/ProgressBar.vue'
import ScoreBars from '../components/ScoreBars.vue'
import JoinQrCode from '../components/JoinQrCode.vue'

const { data: state, online } = usePolling(() => api.get('/display'), { interval: 1000 })

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
    // rodada. O placar por missão é a virada de fase — só aparece ENTRE rodadas
    // (rodada parada), nunca mascarando a revelação que acabou de acontecer.
    if (event.value.round_status === 'voting') return 'voting'
    if (event.value.round_status === 'revealed') return 'reveal'
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
        sublabel: `${r.participants} pessoas · ${r.points} pts`,
        percent: Math.round((Math.abs(r.average) / max) * 100),
    }))
})

const tableRows = computed(() => {
    const rows = tableRanking.value ?? []
    const max = Math.max(1, ...rows.map((r) => Math.abs(r.total_points)))

    return rows.slice(0, 10).map((r) => ({
        key: r.table_id,
        label: r.name,
        icon: r.icon,
        color: r.color,
        value: r.total_points,
        sublabel: r.phase_two_points ? `F1 ${r.phase_one_points} · F2 ${r.phase_two_points}` : `F1 ${r.phase_one_points}`,
        percent: Math.round((Math.abs(r.total_points) / max) * 100),
    }))
})

const winner = computed(() => tableRanking.value?.[0] ?? null)
</script>

<template>
    <div class="min-h-dvh flex flex-col overflow-hidden">
        <header class="px-8 py-4 flex items-center gap-5 bg-slate-950/70 ring-1 ring-white/5">
            <h1 class="text-2xl font-black bg-gradient-to-r from-amber-200 via-amber-300 to-amber-100 bg-clip-text text-transparent">
                {{ event?.title ?? 'Sala de Decisão ROC' }}
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

            <!-- REVELAÇÃO da rodada: consequência + pontos + distribuição -->
            <main v-else-if="view === 'reveal' && results" key="reveal" class="flex-1 grid place-items-center p-8">
                <div class="w-full max-w-6xl space-y-6">
                    <div class="text-center space-y-1">
                        <p class="text-xs font-bold uppercase tracking-[0.4em] text-fuchsia-300">
                            {{ question?.label }} · resultado
                        </p>
                        <h2 class="text-3xl font-black text-white">{{ question?.title }}</h2>
                    </div>

                    <div class="space-y-3">
                        <div
                            v-for="option in results.options"
                            :key="option.option_id"
                            class="rounded-2xl p-4 ring-2 transition-all duration-500 grid grid-cols-[1fr_auto] gap-4 items-center"
                            :class="option.is_best
                                ? 'ring-emerald-400 bg-emerald-500/10 shadow-lg shadow-emerald-500/20 scale-[1.02]'
                                : 'ring-white/10 bg-slate-900/50 opacity-60'"
                        >
                            <div class="min-w-0">
                                <span
                                    v-if="option.is_best"
                                    class="inline-flex items-center gap-1.5 mb-1.5 px-2.5 py-1 rounded-full bg-emerald-400 text-emerald-950 text-xs font-black uppercase tracking-wider"
                                >
                                    ✅ Melhor decisão para o hotel
                                </span>
                                <p class="font-black text-white text-lg">
                                    {{ option.text }}
                                </p>
                                <p v-if="option.effect" class="text-sm text-slate-400 mt-1 leading-relaxed">
                                    {{ option.effect }}
                                </p>
                                <div class="mt-2 h-2 rounded-full bg-slate-800 overflow-hidden max-w-md">
                                    <div
                                        class="h-full rounded-full transition-all duration-1000"
                                        :style="{ width: option.percent + '%', background: option.color }"
                                    />
                                </div>
                            </div>
                            <div class="text-right">
                                <p
                                    class="text-3xl font-black tabular-nums"
                                    :class="option.points > 0 ? 'text-emerald-300' : option.points < 0 ? 'text-rose-300' : 'text-slate-400'"
                                >
                                    {{ option.points > 0 ? '+' : '' }}{{ option.points }}
                                </p>
                                <p class="text-sm text-slate-400 tabular-nums">
                                    {{ option.votes }} · {{ option.percent }}%
                                </p>
                            </div>
                        </div>
                    </div>
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

            <!-- PLACAR FINAL -->
            <main v-else-if="view === 'final'" key="final" class="flex-1 grid place-items-center p-10">
                <div class="w-full max-w-4xl space-y-8 text-center">
                    <div class="text-8xl animate-bounce-soft">🏆</div>
                    <div v-if="winner">
                        <p class="text-sm font-bold uppercase tracking-[0.4em] text-amber-300">Mesa vencedora</p>
                        <h2 class="text-6xl font-black text-white mt-2">{{ winner.icon }} {{ winner.name }}</h2>
                        <p class="text-3xl font-black text-amber-300 tabular-nums mt-2">
                            {{ winner.total_points }} pontos
                        </p>
                    </div>
                    <ScoreBars :rows="tableRows" compact suffix=" pts" />
                </div>
            </main>

            <!-- LOBBY -->
            <main v-else key="lobby" class="flex-1 grid grid-cols-[minmax(0,24rem)_1fr] gap-6 p-6 min-h-0">
                <aside class="flex flex-col gap-6">
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
