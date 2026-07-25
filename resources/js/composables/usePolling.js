import { onMounted, onUnmounted, ref } from 'vue'

/**
 * Polls a fetcher on an interval, never letting two requests overlap and
 * pausing while the tab is hidden (a projector tab stays visible, a phone in a
 * pocket does not).
 */
export function usePolling(fetcher, { interval = 1000, immediate = true } = {}) {
    const data = ref(null)
    const error = ref(null)
    const loading = ref(immediate)
    const online = ref(true)

    let timer = null
    let inFlight = false
    let stopped = false

    async function tick() {
        if (inFlight || stopped || document.hidden) return

        inFlight = true

        try {
            data.value = await fetcher()
            error.value = null
            online.value = true
        } catch (e) {
            error.value = e
            // a single dropped poll on venue wifi should not blank the screen
            online.value = false
        } finally {
            inFlight = false
            loading.value = false
        }
    }

    function start() {
        stopped = false
        if (!timer) timer = setInterval(tick, interval)
    }

    function stop() {
        stopped = true
        clearInterval(timer)
        timer = null
    }

    function onVisibility() {
        if (!document.hidden) tick()
    }

    onMounted(() => {
        // immediate: false lets a caller (e.g. the gated master panel) start later
        if (immediate) {
            tick()
            start()
        }
        document.addEventListener('visibilitychange', onVisibility)
    })

    onUnmounted(() => {
        stop()
        document.removeEventListener('visibilitychange', onVisibility)
    })

    return { data, error, loading, online, refresh: tick, start, stop }
}
