import { ref } from 'vue'
import axios from 'axios'

export const WALL_WATCHLIST_OWNER = Symbol('wall-watchlist-owner')
const sessionToday = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/New_York', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())

// Scanner and alerts share one current-session read per signed-in account.
export function createWallWatchlistFeed(demonstration = false) {
  const data = ref(null), loading = ref(false), refreshing = ref(false), error = ref(''), revision = ref(0)
  let controller, generation = 0, lastAttempt = 0, retryAfter = 0, consumers = 0, timer, denied = false

  async function load({ reset = false } = {}) {
    if (Date.now() < retryAfter || (!reset && (loading.value || refreshing.value))) return
    const run = ++generation
    controller?.abort()
    controller = new AbortController()
    if (reset) data.value = null
    error.value = ''; denied = false
    loading.value = !data.value; refreshing.value = !!data.value; lastAttempt = Date.now()
    try {
      const { data: result } = await axios.get('/api/intraday/wall-watchlist', {
        params: demonstration ? { demo: 1 } : {}, signal: controller.signal, timeout: 20000,
      })
      if (run !== generation) return
      if (result?.schema_version !== 'wall-watchlist.v1' || result.timeframe !== '7d' || !Array.isArray(result.items)
        || (demonstration ? result.state !== 'demonstration' || !result.local_demo_available
          : result.state === 'demonstration' || result.session !== sessionToday())) throw new Error('scope')
      data.value = result
    } catch (cause) {
      if (run !== generation || cause?.code === 'ERR_CANCELED') return
      if (cause?.response?.status === 429) {
        const raw = cause.response.headers?.['retry-after'], seconds = Number(raw)
        retryAfter = Math.max(Date.now() + 60000, Number.isFinite(seconds) ? Date.now() + seconds * 1000 : Date.parse(raw) || 0)
      }
      denied = [401, 403].includes(cause?.response?.status)
      error.value = cause?.response?.status === 429 ? 'Please wait a moment before refreshing.'
        : denied ? 'Intraday access is required for wall activity.' : 'The wall watchlist could not load. Try again.'
    } finally {
      if (run === generation) { loading.value = false; refreshing.value = false }
    }
  }

  function refreshVisible() {
    if (document.visibilityState !== 'visible' || demonstration || denied || Date.now() - lastAttempt < 60000) return
    if (data.value?.session !== sessionToday()) { data.value = null; load(); return }
    if (!data.value || Date.parse(data.value.refresh_until) > Date.now() || Date.parse(data.value.next_refresh_at) <= Date.now()) load()
  }
  function watchlistChanged() { revision.value++; data.value = null; load({ reset: true }) }
  function stop() {
    generation++; controller?.abort(); clearInterval(timer)
    loading.value = false; refreshing.value = false
    document.removeEventListener('visibilitychange', refreshVisible)
    window.removeEventListener('watchlist-updated', watchlistChanged)
  }
  function subscribe() {
    if (++consumers === 1) {
      if (!demonstration) {
        timer = setInterval(refreshVisible, 60000)
        document.addEventListener('visibilitychange', refreshVisible)
        window.addEventListener('watchlist-updated', watchlistChanged)
      }
      if (!data.value || (!demonstration && (data.value.session !== sessionToday() || Date.now() - lastAttempt >= 60000))) load({ reset: data.value?.session !== sessionToday() })
    }
    let released = false
    return () => { if (!released) { released = true; if (--consumers === 0) stop() } }
  }
  return { data, loading, refreshing, error, revision, load, subscribe, stop }
}

let sharedOwner, sharedFeed
export function accountWallWatchlistFeed(owner) {
  if (sharedOwner !== owner) {
    sharedFeed?.stop()
    sharedOwner = owner
    sharedFeed = createWallWatchlistFeed()
  }
  return sharedFeed
}
