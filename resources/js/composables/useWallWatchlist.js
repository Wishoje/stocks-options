import { computed, inject, onScopeDispose, ref, shallowRef, toValue, watch } from 'vue'
import { accountWallWatchlistFeed, createWallWatchlistFeed, WALL_WATCHLIST_OWNER } from '@/Support/wallWatchlistFeed'

export function useWallWatchlist({ owner = inject(WALL_WATCHLIST_OWNER, ref(null)), active = ref(true) } = {}) {
  const demo = ref(false), feed = shallowRef(null)
  let release, privateFeed, demoFeed
  const stop = watch(() => [toValue(owner), toValue(active), demo.value], ([id, enabled, example]) => {
    release?.(); release = null; feed.value = null
    if (!enabled) return
    const source = example ? (demoFeed ||= createWallWatchlistFeed(true))
      : id ? accountWallWatchlistFeed(String(id)) : (privateFeed ||= createWallWatchlistFeed())
    feed.value = source
    release = source.subscribe()
  }, { immediate: true, flush: 'sync' })
  const field = name => computed(() => feed.value?.[name].value ?? (name === 'data' ? null : name === 'error' ? '' : false))
  const load = options => feed.value?.load(options)
  const chooseDemo = value => { demo.value = value; return feed.value?.load() }
  const exitDemo = () => { if (demo.value) chooseDemo(false) }
  window.addEventListener('watchlist-updated', exitDemo)
  onScopeDispose(() => { stop(); release?.(); window.removeEventListener('watchlist-updated', exitDemo) })
  return { data: field('data'), loading: field('loading'), refreshing: field('refreshing'), error: field('error'),
    revision: field('revision'), demo, load, chooseDemo }
}
