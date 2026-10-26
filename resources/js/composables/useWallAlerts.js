import { computed, onScopeDispose, ref, shallowRef, toValue, watch } from 'vue'
import { useWallWatchlist } from '@/composables/useWallWatchlist'
import { createWallAlertEngine, currentWallAlertRows, DEFAULT_WALL_ALERT_SETTINGS, wallAlertSettings } from '@/Support/wallAlerts'
import { createWallAlertInbox } from '@/Support/wallAlertInbox'

let retainedOwner, retainedEngine, audio, audioOwner
export function useWallAlerts(owner) {
  const settings = ref({ ...DEFAULT_WALL_ALERT_SETTINGS }), notifications = ref([]), message = ref('')
  const history = ref([]), inboxPersistent = ref(true)
  let inbox = null
  function readInbox() {
    history.value = inbox?.read() || []
    inboxPersistent.value = inbox?.persistent ?? true
  }
  const engine = shallowRef(null), clock = ref(Date.now())
  const soundReady = ref(false)
  const stopOwner = watch(() => toValue(owner), id => {
    notifications.value = []; message.value = ''
    inbox = id ? createWallAlertInbox(id) : null
    readInbox()
    settings.value = id ? wallAlertSettings(id) : { ...DEFAULT_WALL_ALERT_SETTINGS, enabled: false }
    if (String(id) !== retainedOwner) {
      retainedOwner = String(id)
      retainedEngine = id ? createWallAlertEngine(id) : null
    }
    engine.value = retainedEngine
    if (audioOwner !== id) { audio?.close?.(); audio = null; audioOwner = id }
    soundReady.value = audio?.state === 'running'
  }, { immediate: true, flush: 'sync' })
  // Resolve this account's saved choice before starting or switching its feed.
  const feed = useWallWatchlist({ owner, active: computed(() => !!toValue(owner) && settings.value.enabled) })
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
  const stopData = watch(feed.data, data => {
    if (!data || !settings.value.enabled || !engine.value) return
    const candidates = engine.value.consume(data, settings.value)
    const current = new Set(currentWallAlertRows(data).map(row => row.alert_keys[row.status === 'conditions_met' ? 'setup' : 'testing']))
    notifications.value = notifications.value.filter(item => item.preview || current.has(item.alert_id))
    if (!candidates.length) return
    const received = candidates.map(row => ({ ...row, received_at: Date.now() }))
    history.value = inbox?.add(received) || []
    inboxPersistent.value = inbox?.persistent ?? true
    notifications.value = [...received, ...notifications.value].slice(0, 3)
    playSound()
  }, { immediate: true })
  const stopRevision = watch(feed.revision, (value, before) => {
    if (value > Number(before)) { engine.value?.reset(); notifications.value = [] }
  })
  const stopError = watch(feed.error, error => { if (error) notifications.value = [] })
  function storageChanged(event) {
    if (event.key === inbox?.key || event.key === null) {
      history.value = inbox?.sync() || []
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
    history.value = inbox?.markRead(id) || []
    inboxPersistent.value = inbox?.persistent ?? true
  }
  const timer = setInterval(() => {
    clock.value = Date.now()
    readInbox()
    const current = new Set(currentWallAlertRows(feed.data.value).map(row => row.alert_keys[row.status === 'conditions_met' ? 'setup' : 'testing']))
    notifications.value = notifications.value.filter(item => clock.value - item.received_at < 90000 && (item.preview || current.has(item.alert_id)))
  }, 10000)
  window.addEventListener('storage', storageChanged)
  onScopeDispose(() => {
    stopOwner(); stopData(); stopRevision(); stopError(); clearInterval(timer)
    window.removeEventListener('storage', storageChanged)
  })
  return { owner: computed(() => toValue(owner)), settings, notifications, history, inboxPersistent, activity: feed.data, clock,
    unreadCount: computed(() => history.value.filter(item => !item.read_at).length), markRead,
    message, soundReady, error: feed.error, loading: feed.loading, update, preview, dismiss }
}
