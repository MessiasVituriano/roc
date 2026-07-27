<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api, participantToken } from '../lib/api'
import { usePolling } from '../composables/usePolling'
import PixelAvatar from '../components/PixelAvatar.vue'
import CountdownTimer from '../components/CountdownTimer.vue'
import MissionCard from '../components/MissionCard.vue'
import ProgressBar from '../components/ProgressBar.vue'
import BrandLogo from '../components/BrandLogo.vue'
import LoadingScreen from '../components/LoadingScreen.vue'

const router = useRouter()

const selected = ref(null)
const sending = ref(false)
const voteError = ref('')

const { data: state, error: stateError, online } = usePolling(() => api.get('/status'), { interval: 1000 })

const me = computed(() => state.value?.me ?? null)
const table = computed(() => state.value?.my_table ?? null)
const event = computed(() => state.value?.event ?? null)
const question = computed(() => state.value?.question ?? null)
const timer = computed(() => state.value?.timer ?? { remaining: 0, duration: 1 })
const results = computed(() => state.value?.results ?? null)
const mission = computed(() => me.value?.mission ?? null)

const individual = computed(() => event.value?.phase_mode === 'individual')
const hasVoted = computed(() => me.value?.has_voted ?? false)
const canAnswer = computed(() => me.value?.can_answer ?? false)
const needsRepresentative = computed(() => !individual.value && !table.value?.representative_id)

const myOption = computed(
    () => question.value?.options.find((o) => o.id === me.value?.voted_option_id) ?? null,
)
const myResult = computed(
    () => results.value?.options.find((o) => o.option_id === me.value?.voted_option_id) ?? null,
)

// a cor real da alternativa é derivada dos pontos — só chega junto com o
// gabarito. Antes disso, estas quatro apenas separam A/B/C/D.
const NEUTRAL_BARS = ['#6366f1', '#22d3ee', '#a78bfa', '#f472b6']
const barColor = (option, i) => option.color ?? NEUTRAL_BARS[i % NEUTRAL_BARS.length]

const screen = computed(() => {
    if (!event.value) return 'closed'
    if (event.value.status === 'finished') return 'finished'
    // cadastrado antes da abertura: sala de espera até o facilitador abrir
    if (event.value.status === 'draft') return 'lobby'
    if (event.value.round_status === 'revealed') return 'revealed'
    if (event.value.round_status === 'voting') {
        if (!event.value.voting_open) return 'waiting-reveal'
        if (needsRepresentative.value) return 'claim'
        if (!canAnswer.value) return 'watching'
        return hasVoted.value ? 'voted' : 'voting'
    }
    return 'waiting'
})

// a nova rodada limpa a escolha — observando o id, nunca o objeto, que é
// recriado a cada poll
watch(() => question.value?.id, () => {
    selected.value = null
    voteError.value = ''
})

watch(
    () => Boolean(state.value?.event && !state.value?.me),
    (orphaned) => {
        if (orphaned) {
            participantToken.clear()
            router.replace('/')
        }
    },
)

async function confirm() {
    if (!selected.value || sending.value) return

    sending.value = true
    voteError.value = ''

    try {
        const payload = await api.post('/vote', { option_id: selected.value })
        state.value = payload.status

        if (!payload.accepted) {
            voteError.value = individual.value
                ? 'Você já havia votado nesta rodada.'
                : 'A mesa já registrou o consenso desta rodada.'
        }
    } catch (e) {
        voteError.value = e.message
    } finally {
        sending.value = false
    }
}

async function claim() {
    sending.value = true
    voteError.value = ''

    try {
        const payload = await api.post('/claim-representative', {})
        state.value = payload.status
        if (!payload.claimed) voteError.value = 'Outra pessoa assumiu primeiro.'
    } catch (e) {
        voteError.value = e.message
    } finally {
        sending.value = false
    }
}

function leave() {
    participantToken.clear()
    router.replace('/')
}
</script>

<template>
    <!-- o /status ainda não voltou: a marca segura enquanto o celular conecta -->
    <LoadingScreen
        v-if="!state"
        :label="stateError ? 'Sem conexão — tentando de novo…' : 'Entrando na sala…'"
    />

    <div v-else class="min-h-dvh flex flex-col">
        <!-- a marca fica na primeira linha, sempre; a mesa entra abaixo quando existe -->
        <header
            class="sticky top-0 z-20 bg-slate-950/85 backdrop-blur ring-1 ring-white/5"
            :style="table ? { borderBottom: `2px solid ${table.color}` } : {}"
        >
            <div class="px-4 pt-2.5 pb-2 flex items-center gap-3">
                <BrandLogo size="xs" :tagline="false" />
                <span
                    class="ml-auto w-2.5 h-2.5 rounded-full transition-colors"
                    :class="online ? 'bg-emerald-400 shadow-[0_0_10px] shadow-emerald-400' : 'bg-rose-500 animate-pulse'"
                />
            </div>
            <div v-if="table" class="px-4 pb-2.5 pt-2 flex items-center gap-3 border-t border-white/5">
                <span class="text-2xl">{{ table.icon }}</span>
                <div class="min-w-0">
                    <p class="font-black text-white leading-tight truncate">{{ table.name }}</p>
                    <p class="text-[11px] text-slate-400 truncate">
                        {{ me?.name }}
                        <span v-if="me?.is_representative" class="text-amber-300 font-bold">· representante</span>
                    </p>
                </div>
                <PixelAvatar v-if="me" :seed="me.avatar_seed" :gender="me.gender" :size="34" class="ml-auto" />
            </div>
        </header>

        <main class="flex-1 p-5 flex flex-col">
            <Transition name="slide" mode="out-in">
                <!-- cadastrado, aguardando o facilitador abrir o evento -->
                <section v-if="screen === 'lobby'" key="lobby" class="flex-1 flex flex-col justify-center gap-6">
                    <div class="text-center space-y-3">
                        <div class="text-6xl animate-bounce-soft">🎟️</div>
                        <h2 class="text-2xl font-black text-emerald-300">Você está dentro!</h2>
                        <p class="text-slate-300 text-sm px-6">
                            Seu lugar está reservado. Assim que o evento abrir, a primeira rodada
                            aparece aqui automaticamente — pode deixar o celular à mão.
                        </p>
                        <p class="inline-flex items-center gap-2 text-xs text-slate-400">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" />
                            Aguardando o início
                        </p>
                    </div>
                    <MissionCard v-if="mission && individual" :mission="mission" />
                </section>

                <!-- entre rodadas: a missão fica sempre à vista -->
                <section v-else-if="screen === 'waiting'" key="waiting" class="flex-1 flex flex-col justify-center gap-6">
                    <MissionCard v-if="mission && individual" :mission="mission" />
                    <div class="text-center space-y-3">
                        <div class="text-6xl animate-bounce-soft">⏳</div>
                        <h2 class="text-xl font-black text-white">
                            {{ individual ? 'Aguarde a próxima rodada' : 'Aguarde a rodada da mesa' }}
                        </h2>
                        <p class="text-slate-400 text-sm px-4">
                            Fase {{ event?.phase }} · rodada {{ event?.round }} de {{ event?.total_rounds }}
                        </p>
                    </div>
                </section>

                <!-- fase 2 sem representante definido -->
                <section v-else-if="screen === 'claim'" key="claim" class="flex-1 grid place-items-center text-center">
                    <div class="space-y-5 max-w-xs">
                        <div class="text-7xl">🙋</div>
                        <h2 class="text-2xl font-black text-white">Quem registra pela mesa?</h2>
                        <p class="text-slate-400">
                            Agora a decisão é conjunta. Combinem e uma pessoa registra a resposta da mesa.
                        </p>
                        <p v-if="voteError" class="rounded-xl bg-rose-500/15 text-rose-300 text-sm px-4 py-2">
                            {{ voteError }}
                        </p>
                        <button
                            class="w-full rounded-2xl px-5 py-4 font-black text-white bg-gradient-to-r from-amber-500 to-orange-500 hover:brightness-110 active:scale-95 transition disabled:opacity-50"
                            :disabled="sending"
                            @click="claim"
                        >
                            {{ sending ? '…' : 'Sou eu 🙋' }}
                        </button>
                    </div>
                </section>

                <!-- votando -->
                <section v-else-if="screen === 'voting'" key="voting" class="flex-1 flex flex-col gap-4">
                    <div class="flex items-center gap-3">
                        <CountdownTimer :remaining="timer.remaining" :duration="timer.duration" :size="64" />
                        <div class="flex-1 min-w-0">
                            <p class="text-[11px] font-bold uppercase tracking-widest" :class="individual ? 'text-sky-300' : 'text-amber-300'">
                                {{ question?.label }}
                            </p>
                            <p class="text-white font-black">
                                Rodada {{ event.round }} de {{ event.total_rounds }}
                            </p>
                        </div>
                    </div>

                    <MissionCard v-if="mission && individual" :mission="mission" compact />

                    <h2 class="text-lg font-black text-white leading-snug">{{ question?.title }}</h2>
                    <p v-if="question?.context" class="text-xs text-slate-400 leading-relaxed -mt-2">
                        {{ question.context }}
                    </p>

                    <div class="grid gap-2.5">
                        <button
                            v-for="option in question?.options"
                            :key="option.id"
                            class="rounded-2xl px-4 py-3.5 text-left font-bold text-white text-sm ring-2 transition-all duration-200 active:scale-[0.98]"
                            :class="selected === option.id ? 'scale-[1.02] ring-white' : 'ring-white/10 bg-slate-800/70'"
                            :style="selected === option.id ? { background: 'linear-gradient(90deg,#334155,#475569)' } : {}"
                            @click="selected = option.id"
                        >
                            {{ option.text }}
                        </button>
                    </div>

                    <p v-if="voteError" class="rounded-xl bg-rose-500/15 text-rose-300 text-sm px-4 py-2 text-center">
                        {{ voteError }}
                    </p>

                    <button
                        class="mt-auto rounded-2xl px-5 py-4 font-black text-white bg-gradient-to-r from-emerald-500 to-teal-400 disabled:opacity-30 hover:brightness-110 active:scale-95 transition shadow-lg shadow-emerald-500/25"
                        :disabled="!selected || sending"
                        @click="confirm"
                    >
                        {{ sending ? 'Registrando…' : individual ? 'Confirmar minha decisão ✔' : 'Confirmar decisão da mesa ✔' }}
                    </button>
                </section>

                <!-- votou, aguardando os demais -->
                <section v-else-if="screen === 'voted'" key="voted" class="flex-1 grid place-items-center text-center">
                    <div class="space-y-5">
                        <div class="text-7xl animate-pop">✅</div>
                        <h2 class="text-2xl font-black text-emerald-300">Decisão registrada</h2>
                        <p v-if="myOption" class="text-white font-bold px-6">“{{ myOption.text }}”</p>
                        <!-- nada de pontos aqui: o placar é revelado no telão -->
                        <p class="text-slate-500 text-sm">Olhe para o telão.</p>
                        <CountdownTimer :remaining="timer.remaining" :duration="timer.duration" :size="80" />
                    </div>
                </section>

                <!-- fase 2: quem não é representante -->
                <section v-else-if="screen === 'watching'" key="watching" class="flex-1 grid place-items-center text-center">
                    <div class="space-y-4 max-w-xs">
                        <div class="text-6xl">🗣️</div>
                        <h2 class="text-xl font-black text-white">Decidam juntos</h2>
                        <p class="text-slate-400">
                            <strong class="text-white">{{ table?.representative_name }}</strong>
                            registra a decisão da mesa.
                        </p>
                        <p v-if="table?.has_voted" class="text-emerald-300 font-bold">✔ Já registrada</p>
                    </div>
                </section>

                <!-- tempo esgotado, revelação ainda não veio -->
                <section v-else-if="screen === 'waiting-reveal'" key="waiting-reveal" class="flex-1 grid place-items-center text-center">
                    <div class="space-y-4">
                        <div class="text-7xl">⏱️</div>
                        <h2 class="text-2xl font-black text-white">Tempo encerrado</h2>
                        <p class="text-slate-400">Olhe para o telão!</p>
                    </div>
                </section>

                <!--
                    Revelação: a sala, não o gabarito. Sem pontos da rodada e
                    sem total acumulado — os dois entregariam a resposta, que
                    volta a valer nas rodadas da Fase 2.
                -->
                <section v-else-if="screen === 'revealed'" key="revealed" class="flex-1 flex flex-col gap-4">
                    <p class="text-[11px] font-bold uppercase tracking-widest text-fuchsia-300">
                        Como a sala decidiu · rodada {{ event.round }}
                    </p>
                    <h2 class="text-lg font-black text-white leading-snug">{{ question?.title }}</h2>

                    <div v-if="myResult" class="rounded-2xl bg-slate-800/70 ring-1 ring-white/15 p-4 text-center">
                        <p class="text-[11px] uppercase tracking-widest text-slate-400">Sua decisão</p>
                        <p class="text-white font-bold mt-1 leading-snug">{{ myResult.text }}</p>
                    </div>
                    <p v-else class="text-center text-slate-500 text-sm">Você não votou nesta rodada.</p>

                    <div class="space-y-2.5">
                        <div v-for="(option, i) in results?.options" :key="option.option_id">
                            <div class="flex items-baseline gap-2 text-sm">
                                <span class="text-amber-300 font-black">{{ 'ABCD'[i] }})</span>
                                <span
                                    class="flex-1 min-w-0 leading-snug"
                                    :class="option.option_id === me?.voted_option_id ? 'text-white font-bold' : 'text-slate-400'"
                                >
                                    {{ option.text }}
                                </span>
                                <span class="tabular-nums font-black text-white shrink-0">{{ option.percent }}%</span>
                            </div>
                            <div class="mt-1 h-2 rounded-full bg-slate-800 overflow-hidden">
                                <div
                                    class="h-full rounded-full transition-all duration-1000"
                                    :style="{ width: option.percent + '%', background: barColor(option, i) }"
                                />
                            </div>
                        </div>
                    </div>

                    <p class="mt-auto rounded-2xl bg-slate-900/70 ring-1 ring-white/10 px-4 py-3 text-center text-xs text-slate-400">
                        Qual era a melhor decisão para o hotel — e quantos pontos você fez —
                        só no <strong class="text-slate-200">placar final</strong>.
                    </p>
                </section>

                <section v-else-if="screen === 'finished'" key="finished" class="flex-1 grid place-items-center text-center">
                    <div class="space-y-4">
                        <div class="text-7xl animate-bounce-soft">🏆</div>
                        <h2 class="text-3xl font-black bg-gradient-to-r from-amber-200 to-fuchsia-300 bg-clip-text text-transparent">
                            Obrigado!
                        </h2>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="rounded-2xl bg-slate-900/70 ring-1 ring-white/10 px-5 py-4">
                                <p class="text-[10px] uppercase tracking-widest text-slate-400">Seus acertos</p>
                                <p class="text-3xl font-black text-emerald-300 tabular-nums">
                                    {{ me?.correct ?? 0 }}<span class="text-slate-500 text-lg">/{{ me?.rounds ?? 5 }}</span>
                                </p>
                                <p class="text-[10px] text-slate-500 mt-0.5">decidindo sozinho</p>
                            </div>
                            <div class="rounded-2xl bg-slate-900/70 ring-1 ring-white/10 px-5 py-4">
                                <p class="text-[10px] uppercase tracking-widest text-slate-400">Valor gerado</p>
                                <p class="text-3xl font-black text-white tabular-nums">{{ me?.total_points ?? 0 }}</p>
                                <p class="text-[10px] text-slate-500 mt-0.5">pontos na Fase 1</p>
                            </div>
                        </div>
                        <button class="text-xs text-slate-500 underline pt-2" @click="leave">Sair</button>
                        <div class="grid place-items-center pt-4">
                            <BrandLogo size="sm" stacked />
                        </div>
                    </div>
                </section>

                <section v-else key="closed" class="flex-1 grid place-items-center text-center">
                    <div class="space-y-3">
                        <div class="text-6xl">📴</div>
                        <p class="text-slate-400">Nenhum evento em andamento.</p>
                    </div>
                </section>
            </Transition>
        </main>
    </div>
</template>
