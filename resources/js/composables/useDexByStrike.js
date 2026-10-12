import { ref, watch, onScopeDispose } from 'vue'
import axios from 'axios'
import { wallScope } from './useWallIntelligence'

const cache = new Map()
export function useDexByStrike(source, enabled) {
  const data = ref(null), loading = ref(false), error = ref('')
  let controller, generation = 0
  async function load(force = false) {
    const run = ++generation
    controller?.abort(); data.value = null; error.value = ''; loading.value = false
    const levels = source()
    if (!enabled() || !levels?.symbol || !levels?.data_date) return
    const key = wallScope(levels) + JSON.stringify(levels.strike_data)
    if (!force && cache.get(key)?.expires > Date.now()) { data.value = cache.get(key).data; return }
    controller = new AbortController(); loading.value = true
    try {
      const { data: result } = await axios.get('/api/dex-by-strike', {
        params: { symbol: levels.symbol, timeframe: levels.timeframe,
          ...(levels.view_context?.view ? { view: levels.view_context.view } : {}),
          ...(levels.view_context?.session_date ? { session_date: levels.view_context.session_date } : {}) },
        signal: controller.signal, timeout: 30000,
      })
      if (run !== generation) return
      if (result?.schema_version !== 'dex-by-strike.v1' || wallScope(result) !== wallScope(levels)
        || result.dex_unit !== 'share equivalents' || !Array.isArray(result.strikes)
        || result.strikes.length !== levels.strike_data?.length || !result.summary) throw new Error('scope')
      const originals = new Map(levels.strike_data.map(row => [Number(row.strike), row]))
      const seen = new Set()
      for (const row of result.strikes) {
        const original = originals.get(row.strike)
        if (!original || seen.has(row.strike)) throw new Error('scope')
        seen.add(row.strike)
        for (const field of ['net_gex', 'call_gex', 'put_gex']) {
          const value = Number(original[field]) * .01
          if (original[field] == null || !Number.isFinite(value) || !Number.isFinite(row[field])
            || Math.abs(value - row[field]) > Math.max(.01, Math.abs(value) * 1e-8)) throw new Error('scope')
        }
        for (const field of ['net_dex', 'call_dex', 'put_dex']) {
          if (row[field] !== null && !Number.isFinite(row[field])) throw new Error('scope')
        }
      }
      data.value = result
      cache.set(key, { data: result, expires: Date.now() + 120000 })
      if (cache.size > 4) cache.delete(cache.keys().next().value)
    } catch (cause) {
      if (run !== generation || cause?.code === 'ERR_CANCELED') return
      error.value = cause?.response?.status === 429 ? 'Please wait a moment, then retry delta exposure.'
        : cause?.response?.status === 409 || cause?.message === 'scope' ? 'The levels have updated. Refresh the dashboard to compare delta exposure.'
          : 'Delta exposure could not load. Your other strike charts remain available.'
    } finally { if (run === generation) loading.value = false }
  }
  watch(() => [source(), enabled()], () => load(), { immediate: true })
  onScopeDispose(() => { generation++; controller?.abort() })
  return { data, loading, error, retry: () => load(true) }
}
