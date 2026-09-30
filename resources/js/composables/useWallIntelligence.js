import { ref, watch, onScopeDispose } from 'vue'
import axios from 'axios'

const cache = new Map()
export function wallScope(levels) {
  return JSON.stringify([levels?.symbol, levels?.timeframe, levels?.data_date, levels?.view_context ?? null,
    [...(levels?.expiration_dates ?? [])].sort()])
}

export function useWallIntelligence(source, enabled) {
  const data = ref(null), loading = ref(false), error = ref('')
  let controller, generation = 0
  async function load(force = false) {
    const run = ++generation
    controller?.abort()
    data.value = null
    error.value = ''
    loading.value = false
    const levels = source()
    if (!enabled() || !levels?.symbol || !levels?.timeframe || !levels?.data_date) return
    const key = wallScope(levels) + JSON.stringify(levels.strike_data)
    if (!force && cache.get(key)?.expires > Date.now()) {
      data.value = cache.get(key).data
      return
    }
    controller = new AbortController()
    loading.value = true
    try {
      const { data: result } = await axios.get('/api/wall-intelligence', {
        params: {
          symbol: levels.symbol, timeframe: levels.timeframe,
          ...(levels.view_context?.view ? { view: levels.view_context.view } : {}),
          ...(levels.view_context?.session_date ? { session_date: levels.view_context.session_date } : {}),
        }, signal: controller.signal, timeout: 30000,
      })
      if (run !== generation) return
      if (wallScope(result) !== wallScope(levels) || !result?.walls) throw new Error('scope')
      for (const wall of Object.values(result.walls).flat()) {
        const original = levels.strike_data?.find(row => Number(row.strike) === wall.strike)
        if (!original || !Number.isFinite(wall.net_gex) || Math.abs(wall.net_gex - Number(original.net_gex) * .01) > Math.max(.01, Math.abs(wall.net_gex) * 1e-8)) throw new Error('scope')
      }
      data.value = result
      cache.set(key, { data: result, expires: Date.now() + 120000 })
      if (cache.size > 8) cache.delete(cache.keys().next().value)
    } catch (cause) {
      if (run !== generation || cause?.code === 'ERR_CANCELED') return
      error.value = cause?.response?.status === 409 || cause?.message === 'scope'
        ? 'The levels have updated. Refresh the dashboard to compare them.'
        : 'Wall details could not load. Your levels remain available.'
    } finally {
      if (run === generation) loading.value = false
    }
  }
  watch(() => [source(), enabled()], () => load(), { immediate: true })
  onScopeDispose(() => { generation++; controller?.abort() })
  return { data, loading, error, retry: () => load(true) }
}
