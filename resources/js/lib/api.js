/**
 * Thin fetch wrapper over the REST API.
 *
 * Every screen talks to the backend only through here, so replacing polling
 * with a websocket transport later is a change confined to this file plus
 * usePolling — no component needs to know.
 */

const BASE = '/api'

function authHeaders() {
    const headers = { Accept: 'application/json' }
    const participant = localStorage.getItem('lc:token')
    const master = localStorage.getItem('lc:master')

    if (participant) headers['X-Participant-Token'] = participant
    if (master) headers['X-Master-Token'] = master

    return headers
}

// A versão de assets com que esta aba subiu. Se o servidor passar a responder
// outra, a SPA em memória ficou velha e precisa recarregar — senão ela
// interpreta payloads novos com código antigo e falha em silêncio.
let bootVersion = null

function checkVersion(response) {
    const version = response.headers.get('X-App-Version')

    if (!version) return

    if (bootVersion === null) {
        bootVersion = version
        return
    }

    if (version === bootVersion) return

    // guarda contra loop de reload caso duas instâncias sirvam versões
    // diferentes: recarrega no máximo uma vez a cada 30s
    const last = Number(sessionStorage.getItem('lc:reloaded') ?? 0)

    if (Date.now() - last < 30_000) return

    sessionStorage.setItem('lc:reloaded', String(Date.now()))
    window.location.reload()
}

async function request(method, path, body) {
    const response = await fetch(BASE + path, {
        method,
        headers: {
            ...authHeaders(),
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    })

    checkVersion(response)

    const payload = response.status === 204 ? null : await response.json().catch(() => null)

    if (!response.ok) {
        const error = new Error(payload?.message || `Erro ${response.status}`)
        error.status = response.status
        error.payload = payload
        throw error
    }

    return payload
}

export const api = {
    get: (path) => request('GET', path),
    post: (path, body) => request('POST', path, body),
    patch: (path, body) => request('PATCH', path, body),
    delete: (path) => request('DELETE', path),
}

export const participantToken = {
    get: () => localStorage.getItem('lc:token'),
    set: (token) => localStorage.setItem('lc:token', token),
    clear: () => localStorage.removeItem('lc:token'),
}

export const masterToken = {
    get: () => localStorage.getItem('lc:master'),
    set: (token) => localStorage.setItem('lc:master', token),
    clear: () => localStorage.removeItem('lc:master'),
}
