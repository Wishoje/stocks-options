import { ref, watch, onScopeDispose } from 'vue'
import axios from 'axios'

const cache = new Map()
export function useIntradayVolatility(symbol, session, expiry) {
  const data = ref(null), loading = ref(false), error = ref('')
  let generation = 0, controller, timer
  async function load(force = false, retain = false) {
    const run = ++generation
    controller?.abort(); clearTimeout(timer)
    if (!retain) data.value = null
    loading.value = false; error.value = ''
    const requested = { symbol: symbol(), ...(session() ? { session: session() } : {}), ...(expiry() ? { expiry: expiry() } : {}) }
    if (!requested.symbol) return
    const key = JSON.stringify(requested)
    try {
      let result
      if (!force && cache.get(key)?.expires > Date.now()) result = cache.get(key).data
      else {
        controller = new AbortController(); loading.value = true
        result = (await axios.get('/api/intraday/volatility', { params: requested, signal: controller.signal, timeout: 30000 })).data
      }
      if (run !== generation) return
      if (result?.schema_version !== 'intraday-volatility.v1' || result.symbol !== requested.symbol
        || (requested.session && result.session !== requested.session) || (requested.expiry && result.expiry !== requested.expiry)
        || !Array.isArray(result.readings) || !Array.isArray(result.expiries)) throw new Error('scope')
      for (const row of result.readings) {
        if (!Number.isFinite(Date.parse(row.recorded_at)) || ['iv', 'skew', 'call_iv', 'put_iv', 'iv_change', 'skew_change']
          .some(field => row[field] !== null && !Number.isFinite(row[field]))) throw new Error('scope')
      }
      data.value = result
      cache.set(key, { data: result, expires: Date.now() + 90000 })
      if (cache.size > 12) cache.delete(cache.keys().next().value)
    } catch (cause) {
      if (run !== generation || cause?.code === 'ERR_CANCELED') return
      error.value = cause?.response?.status === 429 ? 'Please wait a moment, then refresh IV and skew.' : 'IV and skew could not load. Try refreshing this panel.'
    } finally {
      if (run === generation) {
        loading.value = false
        if (!requested.session) timer = setTimeout(() => { if (document.visibilityState !== 'hidden') load(true, true) }, 120000)
      }
    }
  }
  function visible() { if (document.visibilityState === 'visible' && !session()) load(false, true) }
  document.addEventListener('visibilitychange', visible)
  watch(() => [symbol(), session(), expiry()], () => load(), { immediate: true })
  onScopeDispose(() => { generation++; controller?.abort(); clearTimeout(timer); document.removeEventListener('visibilitychange', visible) })
  return { data, loading, error, refresh: () => load(true, true) }
}
