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
import AvatarBuilder from '../components/AvatarBuilder.vue'
import ContactField from '../components/ContactField.vue'
import { STYLES, decodeAvatar, encodeAvatar, isChoosableStyle, randomAvatarParts } from '../lib/avatar'
import { contactLooksValid, contactPayload } from '../lib/contact'

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

// A decisão pode ser trocada enquanto o cronômetro corre: as alternativas
// continuam na tela depois de confirmar, com a escolhida marcada. Quem fecha a
// linha é o fim do tempo (ou o facilitador), não o primeiro toque.
const votedOptionId = computed(() => me.value?.voted_option_id ?? null)
const willChange = computed(
    () => hasVoted.value && selected.value !== null && selected.value !== votedOptionId.value,
)
const canSubmit = computed(
    () => selected.value !== null && (!hasVoted.value || willChange.value),
)
const selectedLetter = computed(() => {
    const index = question.value?.options.findIndex((o) => o.id === selected.value) ?? -1

    return index < 0 ? '' : 'ABCD'[index]
})
const submitLabel = computed(() => {
    if (sending.value) return 'Registrando…'
    if (willChange.value) return `Trocar para a ${selectedLetter.value} ✔`
    if (hasVoted.value) return 'Decisão registrada ✔'

    return individual.value ? 'Confirmar minha decisão ✔' : 'Confirmar decisão da mesa ✔'
})

// A rodada final não tem alternativas: a mesa decide livremente e o facilitador
// lança a pontuação. O celular vira o cartão da missão, e nada mais.
const finalRound = computed(() => question.value?.manual_scoring === true)
const finalScores = computed(() => results.value?.tables ?? [])
const myScore = computed(() => {
    const index = finalScores.value.findIndex((row) => row.table_id === table.value?.id)

    return index < 0 ? null : { ...finalScores.value[index], position: index + 1 }
})

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
    // a rodada final tem tela própria: sem alternativas, sem representante
    if (finalRound.value) {
        if (event.value.round_status === 'revealed') return 'final-score'
        if (event.value.round_status === 'voting') return 'final-mission'
        return 'waiting'
    }
    if (event.value.round_status === 'revealed') return 'revealed'
    if (event.value.round_status === 'voting') {
        if (!event.value.voting_open) return 'waiting-reveal'
        // quem não responde vai para a mesma tela, seja porque outra pessoa
        // assumiu a mesa ou porque o servidor não o deixa assumir
        if (!canAnswer.value) return 'watching'
        if (needsRepresentative.value) return 'claim'
        // quem já votou continua aqui: é o que permite trocar de ideia
        return 'voting'
    }
    return 'waiting'
})

// a nova rodada limpa a escolha — observando o id, nunca o objeto, que é
// recriado a cada poll
watch(() => question.value?.id, () => {
    selected.value = null
    voteError.value = ''
})

// quem recarrega a página no meio da rodada volta com a própria escolha
// marcada. Só preenche o vazio: uma seleção ainda não confirmada é mais nova
// que o voto no servidor e não pode ser sobrescrita pelo poll.
watch(votedOptionId, (id) => {
    if (id && selected.value === null) selected.value = id
}, { immediate: true })

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
    if (!canSubmit.value || sending.value) return

    sending.value = true
    voteError.value = ''

    try {
        const payload = await api.post('/vote', { option_id: selected.value })
        state.value = payload.status
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

// --- editar os próprios dados -------------------------------------------
// Só na sala de espera: o nome é digitado em pé, num celular, e sai torto. Uma
// vez aberto o evento, o nome já está no telão e o avatar já é como a mesa
// reconhece a pessoa — o servidor recusa a partir daí.
const editing = ref(false)
const savingProfile = ref(false)
const editError = ref('')
const form = ref({ name: '', hotel: '', gender: 'male', contactType: 'email', email: '', phone: '' })
const formParts = ref(randomAvatarParts())
const formSeed = computed(() => encodeAvatar(formParts.value))

const canEditProfile = computed(() => event.value?.status === 'draft')
const formReady = computed(
    () => form.value.name.trim().length >= 2
        && form.value.hotel.trim().length >= 2
        && contactLooksValid(form.value.contactType, form.value.email, form.value.phone),
)

async function openEdit() {
    editing.value = true
    editError.value = ''

    try {
        // o contato não trafega no poll de 1s: vem daqui, sob demanda
        const mine = await api.get('/me')

        form.value = {
            name: mine.name ?? '',
            hotel: mine.hotel ?? '',
            // gender fora da escolha (linha antiga, default da coluna) abriria
            // o formulário sem nenhum botão marcado — e o salvar seria recusado
            gender: isChoosableStyle(mine.gender) ? mine.gender : STYLES[0].key,
            // o tipo abre no que a pessoa usou para entrar
            contactType: mine.phone ? 'phone' : 'email',
            email: mine.email ?? '',
            phone: mine.phone ?? '',
        }
        // seed que não veio do montador (o fallback "p{id}" de quem entrou sem
        // escolher) não decodifica: o formulário abre num sorteio, e o avatar
        // só muda se a pessoa salvar
        formParts.value = decodeAvatar(mine.avatar_seed ?? '') ?? randomAvatarParts()
    } catch (e) {
        editError.value = e.message
    }
}

async function saveProfile() {
    if (!formReady.value || savingProfile.value) return

    savingProfile.value = true
    editError.value = ''

    try {
        const payload = await api.post('/update-profile', {
            name: form.value.name.trim(),
            ...contactPayload(form.value.contactType, form.value.email, form.value.phone),
            hotel: form.value.hotel.trim(),
            gender: form.value.gender,
            avatar_seed: formSeed.value,
        })

        state.value = payload.status
        editing.value = false
    } catch (e) {
        editError.value = e.payload?.errors
            ? Object.values(e.payload.errors).flat()[0]
            : e.message
    } finally {
        savingProfile.value = false
    }
}

// --- trocar de mesa ------------------------------------------------------
// Sentou na errada, o colega estava na outra, a mesa do cadastro encheu antes
// de ele chegar nela. A lista vem do /bootstrap, que é quem sabe a lotação de
// todas as mesas — o /status só carrega a mesa da própria pessoa.
const switching = ref(false)
const switchTables = ref([])
const switchMax = ref(10)
const switchError = ref('')
const switchingTo = ref(null)

// Com a votação correndo a troca é recusada pelo servidor: sair da mesa
// devolve o posto de representante, e fazer isso no meio da rodada levaria a
// mesa junto. O botão some antes de a pessoa tentar.
const canSwitchTable = computed(
    () => Boolean(event.value)
        && event.value.status !== 'finished'
        && !(event.value.round_status === 'voting' && event.value.voting_open),
)

async function openSwitch() {
    switching.value = true
    switchError.value = ''

    try {
        const payload = await api.get('/bootstrap')
        switchTables.value = payload.tables ?? []
        switchMax.value = payload.max_participants ?? 10
    } catch (e) {
        switchError.value = e.message
    }
}

async function switchTo(id) {
    switchingTo.value = id
    switchError.value = ''

    try {
        const payload = await api.post('/change-table', { table_id: id })
        state.value = payload.status
        switching.value = false
    } catch (e) {
        switchError.value = e.message
        // a lista pode ter envelhecido entre abrir e tocar: recarrega para a
        // mesa que encheu aparecer cheia
        openSwitch()
    } finally {
        switchingTo.value = null
    }
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
                    <!--
                        Enquanto o facilitador não abre, dá para consertar o que
                        saiu torto do cadastro feito em pé, no auditório.
                    -->
                    <div class="flex flex-col items-center gap-2">
                        <button
                            v-if="canEditProfile"
                            class="rounded-2xl px-5 py-3 text-sm font-bold text-slate-200 bg-white/5 hover:bg-white/10 ring-1 ring-white/10 transition"
                            @click="openEdit"
                        >
                            ✏️ Editar meus dados
                        </button>
                        <button
                            v-if="canSwitchTable"
                            class="text-xs text-slate-500 underline hover:text-slate-300 transition"
                            @click="openSwitch"
                        >
                            Sentei na mesa errada — trocar
                        </button>
                    </div>
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
                        <button
                            v-if="canSwitchTable"
                            class="text-xs text-slate-500 underline hover:text-slate-300 transition"
                            @click="openSwitch"
                        >
                            Trocar de mesa
                        </button>
                    </div>
                </section>

                <!--
                    RODADA FINAL: uma missão só, igual para todas as mesas, sem
                    alternativas. Não há o que registrar aqui — a mesa conversa
                    e o facilitador lança a pontuação no painel.
                -->
                <section v-else-if="screen === 'final-mission'" key="final-mission" class="flex-1 flex flex-col gap-5">
                    <div class="flex items-center gap-3">
                        <CountdownTimer :remaining="timer.remaining" :duration="timer.duration" :size="64" />
                        <div class="flex-1 min-w-0">
                            <p class="text-[11px] font-bold uppercase tracking-widest text-amber-300">
                                {{ question?.label }}
                            </p>
                            <p class="text-white font-black">Decidam juntos</p>
                        </div>
                    </div>

                    <div class="rounded-3xl bg-gradient-to-br from-amber-500/15 to-fuchsia-500/10 ring-2 ring-amber-400/40 p-5 space-y-3">
                        <p class="text-[10px] font-black uppercase tracking-[0.3em] text-amber-300">
                            A missão da mesa
                        </p>
                        <p class="text-white font-bold text-lg leading-snug">“{{ question?.context }}”</p>
                    </div>

                    <p class="text-sm text-slate-400 leading-relaxed">
                        As quatro missões da Fase 1 viraram uma só. Cheguem a uma posição de mesa —
                        não há alternativa para marcar aqui: quem pontua esta rodada é o facilitador.
                    </p>

                    <p class="mt-auto rounded-2xl bg-slate-900/70 ring-1 ring-white/10 px-4 py-3 text-center text-xs text-slate-400">
                        Olhe para o telão quando o tempo acabar.
                    </p>
                </section>

                <!-- rodada final revelada: a pontuação lançada pelo facilitador -->
                <section v-else-if="screen === 'final-score'" key="final-score" class="flex-1 flex flex-col justify-center gap-5 text-center">
                    <div class="space-y-2">
                        <div class="text-6xl">🤝</div>
                        <h2 class="text-xl font-black text-white">Rodada final</h2>
                    </div>

                    <div v-if="myScore" class="rounded-3xl bg-slate-900/70 ring-1 ring-white/15 p-6">
                        <p class="text-[11px] uppercase tracking-widest text-slate-400">
                            {{ table?.icon }} {{ table?.name }}
                        </p>
                        <p class="text-5xl font-black text-amber-300 tabular-nums mt-1">
                            {{ myScore.points }}<span class="text-slate-500 text-xl"> pts</span>
                        </p>
                        <p class="text-xs text-slate-500 mt-1">{{ myScore.position }}º na rodada final</p>
                    </div>
                    <p v-else class="text-slate-400 text-sm px-6">
                        A pontuação da sua mesa ainda não foi lançada.
                    </p>

                    <p class="text-slate-500 text-sm">Olhe para o telão.</p>
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

                    <!-- já votou: a confirmação vira uma faixa, e as
                         alternativas continuam na tela para poder trocar -->
                    <p
                        v-if="hasVoted"
                        class="rounded-2xl bg-emerald-500/15 ring-1 ring-emerald-400/40 px-4 py-2.5 text-center"
                    >
                        <span class="block text-sm font-black text-emerald-300">
                            ✔ {{ individual ? 'Sua decisão está registrada' : 'Decisão da mesa registrada' }}
                        </span>
                        <span class="block text-[11px] text-emerald-200/70">
                            Dá para trocar até o tempo acabar.
                        </span>
                    </p>

                    <h2 class="text-lg font-black text-white leading-snug">{{ question?.title }}</h2>
                    <p v-if="question?.context" class="text-xs text-slate-400 leading-relaxed -mt-2">
                        {{ question.context }}
                    </p>

                    <div class="grid gap-2.5">
                        <button
                            v-for="(option, i) in question?.options"
                            :key="option.id"
                            class="rounded-2xl px-4 py-3.5 text-left font-bold text-white text-sm ring-2 transition-all duration-200 active:scale-[0.98] flex items-baseline gap-2"
                            :class="[
                                selected === option.id ? 'scale-[1.02] ring-white' : 'ring-white/10',
                                option.id === votedOptionId ? 'bg-emerald-500/15' : 'bg-slate-800/70',
                            ]"
                            :style="selected === option.id && option.id !== votedOptionId
                                ? { background: 'linear-gradient(90deg,#334155,#475569)' }
                                : {}"
                            @click="selected = option.id"
                        >
                            <span class="text-amber-300 font-black shrink-0">{{ 'ABCD'[i] }})</span>
                            <span class="flex-1 min-w-0">{{ option.text }}</span>
                            <span
                                v-if="option.id === votedOptionId"
                                class="shrink-0 text-[10px] font-black uppercase tracking-widest text-emerald-300"
                            >
                                ✔ sua
                            </span>
                        </button>
                    </div>

                    <p v-if="voteError" class="rounded-xl bg-rose-500/15 text-rose-300 text-sm px-4 py-2 text-center">
                        {{ voteError }}
                    </p>

                    <button
                        class="mt-auto rounded-2xl px-5 py-4 font-black text-white transition shadow-lg disabled:opacity-30 hover:brightness-110 active:scale-95"
                        :class="willChange
                            ? 'bg-gradient-to-r from-amber-500 to-orange-500 shadow-amber-500/25'
                            : 'bg-gradient-to-r from-emerald-500 to-teal-400 shadow-emerald-500/25'"
                        :disabled="!canSubmit || sending"
                        @click="confirm"
                    >
                        {{ submitLabel }}
                    </button>
                </section>

                <!--
                    Fase 2: quem não registra pela mesa. Chega aqui quem não
                    assumiu o posto — e, sem diferença alguma na tela, quem o
                    facilitador bloqueou.
                -->
                <section v-else-if="screen === 'watching'" key="watching" class="flex-1 grid place-items-center text-center">
                    <div class="space-y-4 max-w-xs">
                        <div class="text-6xl">🗣️</div>
                        <h2 class="text-xl font-black text-white">Decidam juntos</h2>
                        <p v-if="table?.representative_name" class="text-slate-400">
                            <strong class="text-white">{{ table.representative_name }}</strong>
                            registra a decisão da mesa.
                        </p>
                        <p v-else class="text-slate-400">
                            Cheguem a uma posição de mesa — uma pessoa registra a decisão por todos.
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

        <!--
            Editar os próprios dados. Mesmos campos do cadastro, montados dos
            mesmos componentes — se a regra do contato mudar, muda nos dois.
        -->
        <div
            v-if="editing"
            class="fixed inset-0 z-30 bg-slate-950/85 backdrop-blur-sm overflow-y-auto p-5"
            @click.self="editing = false"
        >
            <div class="mx-auto my-auto w-full max-w-md rounded-3xl bg-slate-900 ring-1 ring-white/10 p-5 space-y-4">
                <div class="flex items-center gap-4">
                    <PixelAvatar :seed="formSeed" :gender="form.gender" :size="64" />
                    <div class="min-w-0">
                        <h2 class="text-lg font-black text-white truncate">
                            {{ form.name.trim() || 'Meus dados' }}
                        </h2>
                        <p class="text-xs text-slate-400">Só até o evento começar</p>
                    </div>
                </div>

                <p v-if="editError" class="rounded-xl bg-rose-500/15 text-rose-300 text-sm px-4 py-2">
                    {{ editError }}
                </p>

                <div class="space-y-1.5">
                    <label class="text-xs font-bold uppercase tracking-widest text-slate-400">Nome</label>
                    <input
                        v-model="form.name"
                        type="text"
                        maxlength="60"
                        autocomplete="name"
                        class="w-full rounded-2xl bg-slate-800/80 px-4 py-3.5 text-white placeholder-slate-500 ring-2 ring-transparent focus:ring-indigo-400 outline-none transition"
                    >
                </div>

                <ContactField
                    v-model:type="form.contactType"
                    v-model:email="form.email"
                    v-model:phone="form.phone"
                />

                <div class="space-y-1.5">
                    <label class="text-xs font-bold uppercase tracking-widest text-slate-400">Hotel</label>
                    <input
                        v-model="form.hotel"
                        type="text"
                        maxlength="120"
                        autocomplete="organization"
                        class="w-full rounded-2xl bg-slate-800/80 px-4 py-3.5 text-white placeholder-slate-500 ring-2 ring-transparent focus:ring-indigo-400 outline-none transition"
                    >
                </div>

                <AvatarBuilder v-model:parts="formParts" v-model:gender="form.gender" />

                <div class="grid grid-cols-2 gap-2 pt-1">
                    <button
                        class="rounded-2xl px-4 py-3 font-bold text-slate-300 bg-white/5 hover:bg-white/10 transition"
                        @click="editing = false"
                    >
                        Cancelar
                    </button>
                    <button
                        class="rounded-2xl px-4 py-3 font-black text-white bg-gradient-to-r from-emerald-500 to-teal-400 hover:brightness-110 active:scale-95 transition disabled:opacity-40 disabled:cursor-not-allowed"
                        :disabled="!formReady || savingProfile"
                        @click="saveProfile"
                    >
                        {{ savingProfile ? 'Salvando…' : 'Salvar' }}
                    </button>
                </div>
            </div>
        </div>

        <!--
            Trocar de mesa. Sobreposto em vez de virar uma tela do fluxo: a
            troca é um conserto, e sair dela tem de devolver a pessoa
            exatamente onde ela estava — inclusive no meio de uma revelação.
        -->
        <div
            v-if="switching"
            class="fixed inset-0 z-30 bg-slate-950/85 backdrop-blur-sm flex flex-col p-5"
            @click.self="switching = false"
        >
            <div class="m-auto w-full max-w-sm rounded-3xl bg-slate-900 ring-1 ring-white/10 p-5 space-y-4">
                <div>
                    <h2 class="text-lg font-black text-white">Trocar de mesa</h2>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Suas decisões já registradas continuam contando para a mesa em que
                        você as tomou.
                    </p>
                </div>

                <p v-if="switchError" class="rounded-xl bg-rose-500/15 text-rose-300 text-sm px-4 py-2">
                    {{ switchError }}
                </p>

                <div class="grid grid-cols-2 gap-2 max-h-72 overflow-y-auto pr-1">
                    <button
                        v-for="row in switchTables"
                        :key="row.id"
                        class="rounded-2xl p-2.5 ring-2 transition-all duration-200 text-left flex items-center gap-2 disabled:opacity-40 disabled:cursor-not-allowed"
                        :class="row.id === table?.id ? 'bg-white/10 ring-white/25' : 'ring-white/10 bg-slate-800/60 enabled:hover:ring-white/25'"
                        :disabled="row.full || row.id === table?.id || switchingTo !== null"
                        @click="switchTo(row.id)"
                    >
                        <span class="text-lg">{{ row.icon }}</span>
                        <span class="min-w-0">
                            <span class="block text-sm font-bold text-white truncate">{{ row.name }}</span>
                            <span
                                class="block text-[11px]"
                                :class="row.full ? 'text-amber-300 font-bold' : 'text-slate-400'"
                            >
                                <template v-if="row.id === table?.id">você está aqui</template>
                                <template v-else-if="row.full">completa</template>
                                <template v-else>{{ row.participants_count }}/{{ switchMax }}</template>
                            </span>
                        </span>
                    </button>
                    <p v-if="!switchTables.length" class="col-span-2 text-sm text-slate-500 italic py-2">
                        Carregando as mesas…
                    </p>
                </div>

                <button
                    class="w-full rounded-2xl px-4 py-3 font-bold text-slate-300 bg-white/5 hover:bg-white/10 transition"
                    @click="switching = false"
                >
                    Cancelar
                </button>
            </div>
        </div>
    </div>
</template>
