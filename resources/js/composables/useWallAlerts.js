import { computed, onScopeDispose, ref, shallowRef, toValue, watch } from 'vue'
import { useWallWatchlist } from '@/composables/useWallWatchlist'
import { createWallAlertEngine, DEFAULT_WALL_ALERT_SETTINGS, wallAlertSessionDate, wallAlertSettings } from '@/Support/wallAlerts'
import { createWallAlertInbox, mergeWallAlertHistory, wallInboxServerSnapshots, wallInboxSetupSnapshots } from '@/Support/wallAlertInbox'
import { activeWallRows, wallInboxHistoryRows, wallActivePlanKey } from '@/Support/wallLifecycle'
import { presentIdea, wallInboxIdeaRows } from '@/Support/ideaPresentation'

let retainedOwner, retainedEngine, audio, audioOwner
export function useWallAlerts(owner) {
  const settings = ref({ ...DEFAULT_WALL_ALERT_SETTINGS }), notifications = ref([]), message = ref('')
  const history = ref([]), inboxPersistent = ref(true)
  let inbox = null, feed
  const serverHistory = () => wallInboxServerSnapshots(feed?.data.value, Date.now(), settings.value.testing)
  function readInbox(withServer = true) {
    history.value = mergeWallAlertHistory([...(inbox?.read() || []), ...(withServer ? serverHistory() : [])])
    inboxPersistent.value = inbox?.persistent ?? true
  }
  const engine = shallowRef(null), clock = ref(Date.now())
  const soundReady = ref(false)
  const stopOwner = watch(() => toValue(owner), id => {
    notifications.value = []; message.value = ''
    inbox = id ? createWallAlertInbox(id) : null
    readInbox(false)
    settings.value = id ? wallAlertSettings(id) : { ...DEFAULT_WALL_ALERT_SETTINGS, enabled: false }
    if (String(id) !== retainedOwner) {
      retainedOwner = String(id)
      retainedEngine = id ? createWallAlertEngine(id) : null
    }
    engine.value = retainedEngine
    if (audioOwner !== id) { audio?.close?.(); audio = null; audioOwner = id }
    soundReady.value = audio?.state === 'running'
  }, { immediate: true, flush: 'sync' })
  // Daily history is independent of the user's popup notification preference.
  feed = useWallWatchlist({ owner, active: computed(() => !!toValue(owner)) })
  function persist() {
    try { localStorage.setItem(`gex:wall-alert-settings:v1:${toValue(owner)}`, JSON.stringify(settings.value)) }
    catch { message.value = 'Alerts are on for this visit. Your browser could not save this preference.' }
  }
  function update(changes) {
    const wasEnabled = settings.value.enabled
    settings.value = { ...settings.value, ...changes }
    if (settings.value.enabled !== wasEnabled) { engine.value?.reset(); notifications.value = [] }
    if (!settings.value.enabled) notifications.value = []
    message.value = ''
    persist()
    if (changes.sound) prepareSound()
    if (changes.sound === false) { audio?.close?.(); audio = null; soundReady.value = false }
  }
  async function prepareSound() {
    const preparingOwner = toValue(owner)
    try {
      const Audio = window.AudioContext || window.webkitAudioContext
      if (!Audio) throw new Error('unsupported')
      audio ||= new Audio()
      const context = audio
      await context.resume()
      if (preparingOwner !== toValue(owner) || audio !== context) return
      if (context.state !== 'running') throw new Error('paused')
      soundReady.value = true
    } catch {
      if (preparingOwner !== toValue(owner)) return
      settings.value = { ...settings.value, sound: false }; persist()
      message.value = 'Sound is unavailable. On-screen alerts still work.'
      soundReady.value = false
    }
  }
  function playSound() {
    if (!settings.value.sound || audio?.state !== 'running') return
    const tone = audio.createOscillator(), volume = audio.createGain()
    tone.frequency.value = 660; volume.gain.setValueAtTime(0.08, audio.currentTime)
    volume.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + 0.18)
    tone.connect(volume); volume.connect(audio.destination)
    tone.start(); tone.stop(audio.currentTime + 0.2)
  }
  function reconcileNotifications(now = Date.now()) {
    if (!notifications.value.length) return
    if (feed?.accessDenied.value) { notifications.value = []; return }
    const visible = new Set(notifications.value.map(item => item.alert_id))
    const saved = history.value.filter(item => visible.has(item.alert_id))
    const states = new Map(wallInboxIdeaRows(saved, feed?.data.value, now).map(row => [row.alert_id, row]))
    notifications.value = notifications.value
      .filter(item => now - item.received_at < 90000
        && (item.preview || wallAlertSessionDate(Date.parse(item.observed_at)) === wallAlertSessionDate(now)))
      .map(item => item.preview ? item : presentIdea({ ...item, ...states.get(item.alert_id) }))
  }
  const stopData = watch([feed.data, () => settings.value.testing], ([data]) => {
    if (!data || !inbox) { reconcileNotifications(); return }
    const candidates = settings.value.enabled && engine.value ? engine.value.consume(data, settings.value) : []
    const now = Date.now()
    const activePlans = new Set(activeWallRows(wallInboxIdeaRows(history.value, data, now))
      .filter(row => row.status_group === 'conditions_met')
      .map(wallActivePlanKey).filter(Boolean))
    const received = candidates.map(row => ({ ...row, received_at: now }))
    const notify = received.filter(row => {
      const plan = row.status === 'conditions_met' ? wallActivePlanKey(row) : null
      if (!plan) return true
      if (activePlans.has(plan)) return false
      activePlans.add(plan)
      return true
    })
    const watchlistSetups = Array.isArray(data.alert_history) ? serverHistory() : wallInboxSetupSnapshots(data, now)
    if (received.length || watchlistSetups.length) {
      history.value = inbox?.add([...received, ...watchlistSetups], now) || []
      inboxPersistent.value = inbox?.persistent ?? true
    }
    if (notify.length) notifications.value = [...notify, ...notifications.value].slice(0, 3)
    reconcileNotifications(now)
    if (notify.length) playSound()
  }, { immediate: true })
  const stopRevision = watch(feed.revision, (value, before) => {
    if (value > Number(before)) {
      engine.value?.reset()
      notifications.value = notifications.value.filter(item => !item.preview)
      reconcileNotifications()
    }
  })
  const stopError = watch([feed.error, feed.accessDenied], () => reconcileNotifications())
  function storageChanged(event) {
    if (event.key === inbox?.key || event.key === null) {
      history.value = mergeWallAlertHistory([...(inbox?.sync() || []), ...serverHistory()])
      inboxPersistent.value = inbox?.persistent ?? true
    }
    if (event.key !== `gex:wall-alert-settings:v1:${toValue(owner)}`) return
    const next = wallAlertSettings(toValue(owner))
    if (next.enabled !== settings.value.enabled || next.testing !== settings.value.testing) engine.value?.reset()
    settings.value = next; notifications.value = []
  }
  async function preview() {
    if (settings.value.sound) await prepareSound()
    notifications.value = [{ preview: true, alert_id: 'preview', symbol: 'SPY', side: 'put', strike: 765,
      close: 765.8, status: 'conditions_met', received_at: Date.now(),
      observed_at: new Date(Math.floor((Date.now() - 900000) / 300000) * 300000).toISOString(),
      setup: { direction: 'bullish', pattern: 'reversal', entry_zone: { low: 765.3825, high: 766.1475 },
        invalidation: { price: 764.6175, close_side: 'below' }, target: { strike: 770 } } }]
    playSound()
  }
  const dismiss = id => { notifications.value = id ? notifications.value.filter(item => item.alert_id !== id) : [] }
  function markRead(id = null) {
    const group = id ? wallInboxHistoryRows(wallInboxIdeaRows(history.value, feed.data.value, Date.now(), feed.accessDenied.value))
      .find(row => row.alert_id === id || row.related_alert_ids?.includes(id)) : null
    // The current server page remains readable even when it exceeds the browser cache.
    if (serverHistory().length) inbox?.add(serverHistory())
    history.value = inbox?.markRead(group?.related_alert_ids?.length ? group.related_alert_ids : id) || []
    inboxPersistent.value = inbox?.persistent ?? true
  }
  const timer = setInterval(() => {
    clock.value = Date.now()
    readInbox()
    reconcileNotifications(clock.value)
  }, 10000)
  window.addEventListener('storage', storageChanged)
  onScopeDispose(() => {
    stopOwner(); stopData(); stopRevision(); stopError(); clearInterval(timer)
    window.removeEventListener('storage', storageChanged)
  })
  return { owner: computed(() => toValue(owner)), settings, notifications, history, inboxPersistent, activity: feed.data, clock,
    unreadCount: computed(() => wallInboxHistoryRows(wallInboxIdeaRows(history.value, feed.data.value, clock.value, feed.accessDenied.value)).filter(item => !item.read_at).length), markRead,
    message, soundReady, error: feed.error, loading: feed.loading, initialized: feed.initialized, accessDenied: feed.accessDenied, update, preview, dismiss }
}
