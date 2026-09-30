import { ref, watch, onScopeDispose } from 'vue'
import axios from 'axios'
import { wallScope } from './useWallIntelligence'

export function useGammaProfile(source) {
  const data = ref(null), loading = ref(false), error = ref('')
  let controller, generation = 0
  async function load() {
    const run = ++generation
    controller?.abort()
    data.value = null; error.value = ''; loading.value = false
    const levels = source()
    if (!levels?.symbol || !levels?.data_date) return
    controller = new AbortController(); loading.value = true
    try {
      const response = await axios.get('/api/gamma-profile', {
        params: { symbol: levels.symbol, timeframe: levels.timeframe,
          ...(levels.view_context?.view ? { view: levels.view_context.view } : {}),
          ...(levels.view_context?.session_date ? { session_date: levels.view_context.session_date } : {}) },
        signal: controller.signal, timeout: 30000,
      })
      if (run !== generation) return
      const result = response.data
      if (result?.schema_version !== 'gamma-profile.v1' || wallScope(result) !== wallScope(levels)
        || !Array.isArray(result.curve) || !Array.isArray(result.crossings)) throw new Error('scope')
      data.value = result
    } catch (cause) {
      if (run !== generation || cause?.code === 'ERR_CANCELED') return
      error.value = cause?.response?.status === 429 ? 'Please wait a moment, then retry the gamma profile.'
        : cause?.response?.status === 409 || cause?.message === 'scope' ? 'The selected data changed. Refresh the dashboard to load this gamma profile.'
          : 'The gamma profile could not load. Your other readings remain available.'
    } finally { if (run === generation) loading.value = false }
  }
  watch(source, load, { immediate: true })
  onScopeDispose(() => { generation++; controller?.abort() })
  return { data, loading, error, load }
}
