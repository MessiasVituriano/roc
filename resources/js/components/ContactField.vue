<script setup>
import { computed } from 'vue'
import {
    CONTACT_TYPES,
    contactFilled,
    contactLooksValid,
    emailLooksValid,
    maskPhone,
    phoneDigitsOf,
    phoneLooksValid,
} from '../lib/contact'

// guardados separados: alternar o tipo e voltar não apaga o que já foi digitado
const type = defineModel('type', { type: String, required: true })
const email = defineModel('email', { type: String, required: true })
const phone = defineModel('phone', { type: String, required: true })

const masked = computed(() => maskPhone(phone.value))
const invalid = computed(
    () => contactFilled(type.value, email.value, phone.value)
        && !contactLooksValid(type.value, email.value, phone.value),
)
</script>

<template>
    <!-- contato: um campo só, o tipo é escolhido no alternador -->
    <div class="space-y-1.5">
        <div class="flex items-center gap-2">
            <label class="text-xs font-bold uppercase tracking-widest text-slate-400">Contato</label>
            <div class="ml-auto flex gap-1 rounded-xl bg-slate-800/80 p-1">
                <button
                    v-for="option in CONTACT_TYPES"
                    :key="option.key"
                    class="rounded-lg px-3 py-1 text-[11px] font-bold transition"
                    :class="type === option.key
                        ? 'bg-indigo-500 text-white'
                        : 'text-slate-400 hover:text-white'"
                    @click="type = option.key"
                >
                    {{ option.label }}
                </button>
            </div>
        </div>

        <input
            v-if="type === 'email'"
            v-model="email"
            type="email"
            maxlength="120"
            inputmode="email"
            autocapitalize="off"
            autocomplete="email"
            placeholder="voce@empresa.com"
            class="w-full rounded-2xl bg-slate-800/80 px-4 py-3.5 text-white placeholder-slate-500 ring-2 outline-none transition"
            :class="email && !emailLooksValid(email) ? 'ring-rose-500/60' : 'ring-transparent focus:ring-indigo-400'"
        >
        <input
            v-else
            :value="masked"
            type="tel"
            inputmode="tel"
            autocomplete="tel"
            placeholder="(11) 99999-9999"
            class="w-full rounded-2xl bg-slate-800/80 px-4 py-3.5 text-white placeholder-slate-500 ring-2 outline-none transition"
            :class="phone && !phoneLooksValid(phone) ? 'ring-rose-500/60' : 'ring-transparent focus:ring-indigo-400'"
            @input="phone = phoneDigitsOf($event.target.value)"
        >
        <p v-if="invalid" class="text-[11px] text-rose-300">
            {{ type === 'email' ? 'Informe um e-mail válido.' : 'Informe o DDD e o número.' }}
        </p>
    </div>
</template>
