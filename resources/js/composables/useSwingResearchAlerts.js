import { computed, onScopeDispose, ref, toValue, watch } from 'vue'
import axios from 'axios'

// Research history has its own persisted identities and never follows the intraday reset.
export function useSwingResearchAlerts(owner, options = {}) {
  const endpointPrefix = options.endpointPrefix || '/api/swing-research'
  const storageNamespace = options.storageNamespace || 'swing-research'
  const isConditionsEvent = item => ['qualified', 'conditions_met', 'in_progress'].includes(item.status)
    && !item.outcome && !item.needs_update && !item.metrics?.needs_update
    && (!item.notification_type || item.notification_type === 'conditions_met')
    && (!options.allowedVariants || options.allowedVariants.includes(item.variant))
  // A popup represents the original qualification event. Later outcome polls only update history.
  const eventSnapshot = item => ({ ...item, status: 'conditions_met', notification_type: 'conditions_met' })
  const history = ref([]), notifications = ref([]), loading = ref(false), error = ref(''), truncated = ref(false), unreadCount = ref(0)
  let generation = 0, controller, primed = false, seen = new Set(), disposed = false
  const reads = new Map()
  const currentOwner = computed(() => toValue(owner) || null)
  const canPoll = () => !!currentOwner.value && document.visibilityState !== 'hidden' && navigator.onLine !== false
  const storageKey = () => `gex:${storageNamespace}-seen:v1:${currentOwner.value}`
  function remember() {
    seen = new Set([...seen].slice(-3000))
    try { localStorage.setItem(storageKey(), JSON.stringify([...seen])) } catch {}
  }
  async function refresh() {
    if (disposed || !canPoll() || controller) return
    const run = generation, expectedOwner = currentOwner.value
    const active = new AbortController()
    controller = active; loading.value = true
    try {
      const { data } = await axios.get(`${endpointPrefix}/alerts`, { signal: active.signal, timeout: 30000 })
      if (disposed || run !== generation || currentOwner.value !== expectedOwner) return
      if (!Array.isArray(data?.items) || data.items.some(item => item.experimental !== true || item.id == null)) throw new Error('Invalid research alerts')
      const allowedItems = data.items.filter(item => !options.allowedVariants || options.allowedVariants.includes(item.variant))
      const excludedUnread = data.items.filter(item => !allowedItems.includes(item) && !item.alert_read_at).length
      const previousReads = new Map(history.value.filter(item => item.alert_read_at).map(item => [String(item.id), item.alert_read_at]))
      const reconciledReads = allowedItems.filter(item => !item.alert_read_at && previousReads.has(String(item.id))).length
      const incoming = allowedItems.map(item => ({ ...item, alert_read_at: item.alert_read_at || previousReads.get(String(item.id)) || null }))
      const fresh = incoming.filter(item => primed && !seen.has(String(item.id)) && !item.alert_read_at && isConditionsEvent(item)).map(eventSnapshot)
      for (const item of incoming) seen.add(String(item.id))
      remember(); primed = true
      history.value = incoming
      unreadCount.value = Number.isInteger(data.unread_count) && data.unread_count >= 0 ? Math.max(0, data.unread_count - reconciledReads - excludedUnread) : incoming.filter(item => !item.alert_read_at).length
      const byId = new Map(incoming.map(item => [String(item.id), item]))
      notifications.value = [...fresh, ...notifications.value.map(item => ({ ...item, alert_read_at: byId.get(String(item.id))?.alert_read_at || item.alert_read_at }))]
        .filter((item, index, all) => !item.alert_read_at && all.findIndex(other => String(other.id) === String(item.id)) === index).slice(0, 3)
      truncated.value = !!data.truncated; error.value = ''
    } catch (cause) {
      if (run === generation && !disposed && cause?.code !== 'ERR_CANCELED') error.value = 'Experimental alerts could not refresh. Saved alerts remain below.'
    } finally {
      if (controller === active) { controller = null; loading.value = false }
    }
  }
  function dismiss(id) { notifications.value = notifications.value.filter(item => String(item.id) !== String(id)) }
  async function markRead(id) {
    const item = history.value.find(item => String(item.id) === String(id))
    if (disposed || !currentOwner.value || !item || item.alert_read_at || reads.has(String(id))) return
    const run = generation, active = new AbortController()
    reads.set(String(id), active)
    try {
      await axios.post(`${endpointPrefix}/alerts/${encodeURIComponent(id)}/read`, {}, { signal: active.signal, timeout: 15000 })
      if (disposed || run !== generation) return
      if (history.value.some(item => String(item.id) === String(id) && !item.alert_read_at)) unreadCount.value = Math.max(0, unreadCount.value - 1)
      history.value = history.value.map(item => String(item.id) === String(id) ? { ...item, alert_read_at: new Date().toISOString() } : item)
      dismiss(id)
    } catch (cause) {
      if (run === generation && !disposed && cause?.code !== 'ERR_CANCELED') error.value = 'Read status could not be saved. Try opening the case again.'
    } finally { if (reads.get(String(id)) === active) reads.delete(String(id)) }
  }
  const stopOwner = watch(currentOwner, () => {
    generation++; controller?.abort(); controller = null
    for (const active of reads.values()) active.abort()
    reads.clear(); history.value = []; notifications.value = []; error.value = ''; truncated.value = false; unreadCount.value = 0; loading.value = false; primed = false; seen = new Set()
    if (!currentOwner.value) return
    try { const ids = JSON.parse(localStorage.getItem(storageKey()) || '[]'); if (Array.isArray(ids)) seen = new Set(ids.map(String)) } catch {}
    refresh()
  }, { immediate: true, flush: 'sync' })
  const timer = setInterval(refresh, 60000)
  document.addEventListener('visibilitychange', refresh)
  window.addEventListener('online', refresh)
  onScopeDispose(() => {
    disposed = true; generation++; stopOwner(); controller?.abort()
    for (const active of reads.values()) active.abort()
    clearInterval(timer); document.removeEventListener('visibilitychange', refresh); window.removeEventListener('online', refresh)
  })
  return { owner: currentOwner, history, notifications, loading, error, truncated, refresh, markRead, dismiss, unreadCount }
}
