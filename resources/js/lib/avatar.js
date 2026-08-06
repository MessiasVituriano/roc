/**
 * 16-bit style pixel avatars, assembled by the participant.
 *
 * The choices are encoded into the avatar_seed string itself ("a1-2-5-7"), so
 * the avatar is fully reproducible from the database column with no extra
 * schema. Seeds that carry no choices — demo data, older rows — fall back to
 * colours derived from a hash, so every seed always renders something.
 */

export const SKINS = ['#f9d5b4', '#f1c27d', '#e0ac69', '#c68642', '#8d5524', '#5c3317']
export const HAIRS = ['#2b1b12', '#4a2c17', '#8b5a2b', '#c99b3f', '#e8c07d', '#b83227', '#3b3b58', '#6d28d9']
export const CLOTHES = ['#6366f1', '#ec4899', '#f59e0b', '#10b981', '#06b6d4', '#ef4444', '#8b5cf6', '#0ea5e9', '#14b8a6', '#f97316']

const BACKGROUNDS = ['#1e1b4b', '#312e81', '#4c1d95', '#0f766e', '#7c2d12', '#164e63']
const EYE = '#111827'
const SCLERA = '#f8fafc'
const MOUTH = '#b3455a'

/**
 * O que a pessoa escolhe: masculino ou feminino. O campo só seleciona o sprite.
 *
 * O sprite `custom` continua existindo abaixo, mas fora da escolha: ele é o
 * fallback de qualquer gender desconhecido — dados antigos, o default da
 * coluna, uma linha criada fora do cadastro. Tirá-lo quebraria o desenho
 * dessas linhas; tirá-lo *daqui* é o que fecha a escolha em duas.
 */
export const STYLES = [
    { key: 'male', label: 'Masculino' },
    { key: 'female', label: 'Feminino' },
]

/** A escolha é fechada: qualquer outro valor cai no primeiro estilo. */
export const isChoosableStyle = (gender) => STYLES.some((s) => s.key === gender)

// 12x12 sprite sheets. '.' transparent, H hair, S skin, E pupil, W sclera,
// M mouth, C clothes, A accessory.
const TEMPLATES = {
    male: [
        '............',
        '...HHHHHH...',
        '..HHHHHHHH..',
        '..HHHHHHHH..',
        '..HSSSSSSH..',
        '..SWESSEWS..',
        '..SSSSSSSS..',
        '..SSSMMSSS..',
        '...SSSSSS...',
        '...CCCCCC...',
        '..CCCCCCCC..',
        '.CC.CCCC.CC.',
    ],
    female: [
        '............',
        '...HHHHHH...',
        '..HHHHHHHH..',
        '.HHHHHHHHHH.',
        '.HHSSSSSSHH.',
        '.HSWESSEWSH.',
        '.HSSSSSSSSH.',
        '.HSSSMMSSSH.',
        '..HSSSSSSH..',
        '...CCCCCC...',
        '..CCCCCCCC..',
        '.CC.CCCC.CC.',
    ],
    custom: [
        '............',
        '..AAAAAAAA..',
        '..AAAAAAAA..',
        '..HHHHHHHH..',
        '..SSSSSSSS..',
        '..SWESSEWS..',
        '..SSSSSSSS..',
        '..SSSMMSSS..',
        '...SSSSSS...',
        '...CCCCCC...',
        '..CCCCCCCC..',
        '.CC.CCCC.CC.',
    ],
}

/** FNV-1a — small, stable, and identical across reloads. */
function hash(seed) {
    let h = 0x811c9dc5
    const str = String(seed ?? '')
    for (let i = 0; i < str.length; i++) {
        h ^= str.charCodeAt(i)
        h = Math.imul(h, 0x01000193) >>> 0
    }
    return h
}

/** "a1-<skin>-<hair>-<clothes>" */
export function encodeAvatar({ skin, hair, clothes }) {
    return `a1-${skin}-${hair}-${clothes}`
}

export function decodeAvatar(seed) {
    const match = /^a1-(\d+)-(\d+)-(\d+)$/.exec(String(seed ?? ''))

    if (!match) return null

    return {
        skin: Number(match[1]) % SKINS.length,
        hair: Number(match[2]) % HAIRS.length,
        clothes: Number(match[3]) % CLOTHES.length,
    }
}

export function randomAvatarParts() {
    return {
        skin: Math.floor(Math.random() * SKINS.length),
        hair: Math.floor(Math.random() * HAIRS.length),
        clothes: Math.floor(Math.random() * CLOTHES.length),
    }
}

export function avatarPalette(seed, gender = 'custom') {
    const chosen = decodeAvatar(seed)
    const h = hash(seed)

    const parts = chosen ?? {
        skin: h % SKINS.length,
        hair: (h >>> 3) % HAIRS.length,
        clothes: (h >>> 7) % CLOTHES.length,
    }

    return {
        skin: SKINS[parts.skin],
        hair: HAIRS[parts.hair],
        clothes: CLOTHES[parts.clothes],
        // the backdrop follows the shirt, so the card always has contrast
        background: BACKGROUNDS[parts.clothes % BACKGROUNDS.length],
        gender,
    }
}

/**
 * @returns {{ pixels: Array<{x:number,y:number,fill:string}>, size: number, background: string }}
 */
export function buildAvatar(seed, gender = 'custom') {
    const palette = avatarPalette(seed, gender)
    const rows = TEMPLATES[gender] ?? TEMPLATES.custom

    const colors = {
        H: palette.hair,
        S: palette.skin,
        E: EYE,
        W: SCLERA,
        M: MOUTH,
        C: palette.clothes,
        // the cap takes the shirt colour, otherwise it disappears into dark hair
        A: palette.clothes,
    }

    const pixels = []

    rows.forEach((row, y) => {
        row.split('').forEach((char, x) => {
            if (colors[char]) {
                pixels.push({ x, y, fill: colors[char] })
            }
        })
    })

    return { pixels, size: 12, background: palette.background }
}
