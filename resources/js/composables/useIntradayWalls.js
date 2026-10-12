import { ref, watch, onScopeDispose } from 'vue'
import axios from 'axios'

export function useIntradayWalls(symbol) {
  const data = ref(null), loading = ref(false), refreshing = ref(false), error = ref(''), demo = ref(false)
  let controller, generation = 0, lastAttempt = 0, retryAfter = 0
  async function load({ preserve = true } = {}) {
    const run = ++generation
    controller?.abort()
    if (!preserve) data.value = null
    error.value = ''
    const selected = symbol()
    const selectedScope = '7d'
    if (!selected) { data.value = null; loading.value = false; refreshing.value = false; return }
    controller = new AbortController()
    lastAttempt = Date.now()
    loading.value = !data.value
    refreshing.value = !!data.value
    try {
      const response = await axios.get('/api/intraday/walls', {
        params: { symbol: selected, timeframe: selectedScope, ...(demo.value ? { demo: 1 } : {}) },
        signal: controller.signal, timeout: 20000,
      })
      if (run !== generation) return
      const result = response.data
      if (result?.symbol !== selected || result?.schema_version !== 'intraday-walls.v1'
        || result?.timeframe !== selectedScope
        || !Array.isArray(result.segments) || (demo.value ? result.dataset !== 'synthetic_review' : result.dataset !== 'intraday_capture')
        || (!demo.value && result.market_session_date && result.session !== result.market_session_date)) throw new Error('scope')
      data.value = result
    } catch (cause) {
      if (run !== generation || cause?.code === 'ERR_CANCELED') return
      if (cause?.response?.status === 429) {
        const header = cause.response.headers?.['retry-after']
        const delay = Number(header)
        retryAfter = Math.max(Date.now() + 60000, Number.isFinite(delay) ? Date.now() + delay * 1000 : Date.parse(header) || 0)
      }
      error.value = cause?.response?.status === 429
        ? 'Please wait a moment before refreshing the wall timeline.'
        : 'The wall timeline could not load. Try again.'
    } finally {
      if (run === generation) { loading.value = false; refreshing.value = false }
    }
  }
  watch(symbol, () => load({ preserve: false }), { immediate: true })
  function chooseDemo(value) { demo.value = value; return load({ preserve: false }) }
  // Only the mounted, visible tracker reads its own history. This does not
  // refresh provider quotes or fan requests out across the watchlist.
  function refreshVisible() {
    if (document.visibilityState !== 'visible' || demo.value || loading.value || refreshing.value
      || Date.now() - lastAttempt < 60000 || Date.now() < retryAfter
      || !(Date.parse(data.value?.refresh_until) > Date.now() || Date.parse(data.value?.next_refresh_at) <= Date.now())) return
    load()
  }
  const timer = setInterval(refreshVisible, 60000)
  document.addEventListener('visibilitychange', refreshVisible)
  onScopeDispose(() => {
    generation++; controller?.abort(); clearInterval(timer)
    document.removeEventListener('visibilitychange', refreshVisible)
  })
  return { data, loading, refreshing, error, demo, load, chooseDemo }
}
