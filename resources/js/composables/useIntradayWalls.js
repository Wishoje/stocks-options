import { ref, watch, onScopeDispose } from 'vue'
import axios from 'axios'

export function useIntradayWalls(symbol) {
  const data = ref(null), loading = ref(false), error = ref(''), demo = ref(false), session = ref('')
  let controller, generation = 0
  async function load() {
    const run = ++generation
    controller?.abort()
    data.value = null
    error.value = ''
    const selected = symbol()
    if (!selected) { loading.value = false; return }
    controller = new AbortController()
    loading.value = true
    try {
      const response = await axios.get('/api/intraday/walls', {
        params: { symbol: selected, ...(session.value ? { session: session.value } : {}), ...(demo.value ? { demo: 1 } : {}) },
        signal: controller.signal, timeout: 20000,
      })
      if (run !== generation) return
      const result = response.data
      if (result?.symbol !== selected || result?.schema_version !== 'intraday-walls.v1'
        || !Array.isArray(result.segments) || (demo.value ? result.dataset !== 'synthetic_review' : result.dataset !== 'intraday_capture')
        || (session.value && result.session !== session.value)) throw new Error('scope')
      data.value = result
    } catch (cause) {
      if (run !== generation || cause?.code === 'ERR_CANCELED') return
      error.value = cause?.response?.status === 429
        ? 'Please wait a moment before refreshing the wall timeline.'
        : 'The wall timeline could not load. Try again.'
    } finally {
      if (run === generation) loading.value = false
    }
  }
  watch(symbol, () => { session.value = ''; load() }, { immediate: true })
  // Explicit controls avoid background polling and watchlist request fan-out.
  function chooseSession(value) { session.value = value; return load() }
  function chooseDemo(value) { demo.value = value; session.value = ''; return load() }
  onScopeDispose(() => { generation++; controller?.abort() })
  return { data, loading, error, demo, load, chooseSession, chooseDemo }
}
