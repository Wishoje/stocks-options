import { ref, onScopeDispose } from 'vue'
import axios from 'axios'

export function useWallWatchlist() {
  const data = ref(null), loading = ref(false), refreshing = ref(false), error = ref(''), demo = ref(false)
  let controller, generation = 0, lastAttempt = 0, retryAfter = 0
  async function load({ reset = false } = {}) {
    if (!reset && (loading.value || refreshing.value || Date.now() < retryAfter)) return
    const run = ++generation
    controller?.abort()
    controller = new AbortController()
    if (reset) data.value = null
    error.value = ''
    loading.value = !data.value; refreshing.value = !!data.value; lastAttempt = Date.now()
    try {
      const { data: result } = await axios.get('/api/intraday/wall-watchlist', {
        params: demo.value ? { demo: 1 } : {}, signal: controller.signal, timeout: 20000,
      })
      if (run !== generation) return
      if (result?.schema_version !== 'wall-watchlist.v1' || result.timeframe !== '7d' || !Array.isArray(result.items)
        || (demo.value ? result.state !== 'demonstration' : result.state === 'demonstration'
          || result.session !== new Intl.DateTimeFormat('en-CA', { timeZone: 'America/New_York', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date()))) throw new Error('scope')
      data.value = result
    } catch (cause) {
      if (run !== generation || cause?.code === 'ERR_CANCELED') return
      if (cause?.response?.status === 429) {
        const raw = cause.response.headers?.['retry-after'], seconds = Number(raw)
        retryAfter = Math.max(Date.now() + 60000, Number.isFinite(seconds) ? Date.now() + seconds * 1000 : Date.parse(raw) || 0)
      }
      error.value = cause?.response?.status === 429 ? 'Please wait a moment before refreshing.' : 'The wall watchlist could not load. Try again.'
    } finally {
      if (run === generation) { loading.value = false; refreshing.value = false }
    }
  }
  function refreshVisible() {
    if (document.visibilityState !== 'visible' || demo.value || Date.now() - lastAttempt < 60000
      || !(Date.parse(data.value?.refresh_until) > Date.now() || Date.parse(data.value?.next_refresh_at) <= Date.now())) return
    load()
  }
  function watchlistChanged() { demo.value = false; load({ reset: true }) }
  function chooseDemo(value) { demo.value = value; return load({ reset: true }) }
  const timer = setInterval(refreshVisible, 60000)
  document.addEventListener('visibilitychange', refreshVisible)
  window.addEventListener('watchlist-updated', watchlistChanged)
  load()
  onScopeDispose(() => {
    generation++; controller?.abort(); clearInterval(timer)
    document.removeEventListener('visibilitychange', refreshVisible)
    window.removeEventListener('watchlist-updated', watchlistChanged)
  })
  return { data, loading, refreshing, error, demo, load, chooseDemo }
}
