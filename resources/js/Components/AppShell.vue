<template>
  <div class="gex-ui dashboard-shell" data-theme="dark" data-density="compact">
    <div class="dashboard-shell__frame">
      <aside class="dashboard-shell__sidebar" aria-label="Watchlist">
        <LeftPanel
          :watchlist="watchlistItems"
          :pin-map="pinMap"
          :ua-map="uaMap"
          :selected-symbol="selectedSymbol"
          :selecting-symbol="selectingSymbol"
          :loading="watchlistLoading"
          :refreshing="watchlistRefreshing"
          :error="watchlistError"
          :removing-ids="removingIds"
          @select="handleSelectSymbol"
          @add="reloadWatchlist"
          @remove="handleRemoveFromWatchlist"
          @refresh="reloadWatchlist"
        />
      </aside>

      <Transition name="watchlist-drawer">
        <div v-if="showMobileWatchlist" class="watchlist-drawer" @keydown="handleDrawerKeydown">
          <div class="watchlist-drawer__backdrop" aria-hidden="true" @click="closeMobileWatchlist()" />

          <aside
            id="mobile-watchlist-drawer"
            ref="mobileWatchlistDrawer"
            class="watchlist-drawer__panel"
            role="dialog"
            aria-modal="true"
            aria-label="Watchlist"
          >
            <button
              ref="drawerCloseButton"
              type="button"
              class="watchlist-drawer__close"
              aria-label="Close watchlist"
              @click="closeMobileWatchlist()"
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18 18 6M6 6l12 12" />
              </svg>
            </button>

            <LeftPanel
              :watchlist="watchlistItems"
              :pin-map="pinMap"
              :ua-map="uaMap"
              :selected-symbol="selectedSymbol"
              :selecting-symbol="selectingSymbol"
              :loading="watchlistLoading"
              :refreshing="watchlistRefreshing"
              :error="watchlistError"
              :removing-ids="removingIds"
              @select="handleSelectSymbol"
              @add="reloadWatchlist"
              @remove="handleRemoveFromWatchlist"
              @refresh="reloadWatchlist"
            />
          </aside>
        </div>
      </Transition>

      <div id="dashboard-main-content" class="dashboard-shell__main">
        <div class="dashboard-shell__mobile-tools">
          <button
            ref="mobileWatchlistButton"
            type="button"
            class="dashboard-shell__watchlist-trigger"
            aria-controls="mobile-watchlist-drawer"
            aria-haspopup="dialog"
            :aria-expanded="showMobileWatchlist ? 'true' : 'false'"
            @click="openMobileWatchlist"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h10" />
            </svg>
            <span>Watchlist</span>
            <span class="dashboard-shell__watchlist-count">{{ watchlistItems.length }}</span>
          </button>
        </div>

        <slot />
      </div>
    </div>
  </div>
</template>

<script setup>
import { nextTick, onMounted, onUnmounted, ref } from 'vue'
import axios from 'axios'
import LeftPanel from './LeftPanel.vue'
import {
  APP_SHELL_UA_CONCURRENCY,
  loadUnusualActivityBadges,
} from '@/Support/app-shell-activity-loader.js'
import { selectionWarmupPlan } from '@/Support/symbol-bootstrap-state.js'

const watchlistItems = ref([])
const pinMap = ref({})
const uaMap = ref({})
const selectedSymbol = ref('')
const selectingSymbol = ref('')
const watchlistLoading = ref(true)
const watchlistRefreshing = ref(false)
const watchlistError = ref('')
const removingIds = ref(new Set())
const showMobileWatchlist = ref(false)
const mobileWatchlistButton = ref(null)
const mobileWatchlistDrawer = ref(null)
const drawerCloseButton = ref(null)
let activeReloadController = null
let reloadSequence = 0
let componentUnmounted = false
let activeSelection = null
let bodyOverflowBeforeDrawer = ''
let drawerBodyLocked = false

function normalizeSymbol(symbol) {
  return String(symbol || '').trim().toUpperCase()
}

function setSelectedSymbol(symbol) {
  const normalized = normalizeSymbol(symbol)
  if (normalized) selectedSymbol.value = normalized
}

function syncSelectedSymbolFromEvent(event) {
  setSelectedSymbol(event?.detail?.symbol)
}

function syncSelectedSymbolFromLocation() {
  if (typeof window === 'undefined') return
  const locationSymbol = new URLSearchParams(window.location.search).get('symbol')
  if (locationSymbol) setSelectedSymbol(locationSymbol)
}

function handleWatchlistUpdated() {
  reloadWatchlist()
}

async function reloadWatchlist() {
  const sequence = ++reloadSequence
  activeReloadController?.abort()

  const controller = new AbortController()
  activeReloadController = controller
  watchlistError.value = ''
  watchlistLoading.value = watchlistItems.value.length === 0
  watchlistRefreshing.value = watchlistItems.value.length > 0

  try {
    const { data } = await axios.get('/api/watchlist', { signal: controller.signal })

    if (!isCurrentReload(sequence, controller)) return

    const items = Array.isArray(data) ? data : []
    watchlistItems.value = items
    await loadPinsAndUA(items, sequence, controller)
  } catch (error) {
    if (!controller.signal.aborted && isCurrentReload(sequence, controller)) {
      watchlistError.value = 'We could not load your saved symbols. Your current list is unchanged.'
    }
  } finally {
    if (activeReloadController === controller) {
      activeReloadController = null
      watchlistLoading.value = false
      watchlistRefreshing.value = false
    }
  }
}

function isCurrentReload(sequence, controller) {
  return !componentUnmounted
    && reloadSequence === sequence
    && activeReloadController === controller
    && !controller.signal.aborted
}

async function loadPinsAndUA(items, sequence, controller) {
  const symbols = [...new Set(items.map((item) => item.symbol).filter(Boolean))]

  if (symbols.length === 0) {
    if (isCurrentReload(sequence, controller)) {
      pinMap.value = {}
      uaMap.value = {}
    }
    return
  }

  try {
    const { data } = await axios.get('/api/expiry-pressure/batch', {
      params: { symbols, days: 3 },
      signal: controller.signal,
    })

    if (isCurrentReload(sequence, controller)) {
      pinMap.value = data?.items || {}
    }
  } catch {
    if (!controller.signal.aborted && isCurrentReload(sequence, controller)) {
      pinMap.value = {}
    }
  }

  if (!isCurrentReload(sequence, controller)) return

  const out = await loadUnusualActivityBadges(
    symbols,
    async (symbol, signal) => {
      const { data } = await axios.get('/api/ua', { params: { symbol }, signal })
      return data
    },
    { concurrency: APP_SHELL_UA_CONCURRENCY, signal: controller.signal },
  )

  if (isCurrentReload(sequence, controller)) {
    uaMap.value = out
  }
}

function handleSelectSymbol(symbol) {
  if (componentUnmounted) return Promise.resolve()

  const normalized = normalizeSymbol(symbol)
  if (!normalized) return Promise.resolve()

  setSelectedSymbol(normalized)
  closeMobileWatchlist()

  if (activeSelection?.symbol === normalized) return activeSelection.promise

  activeSelection?.controller.abort()
  const selection = { symbol: normalized, controller: new AbortController(), promise: null }
  activeSelection = selection
  selectingSymbol.value = normalized
  selection.promise = selectSymbol(selection).finally(() => {
    if (activeSelection === selection) {
      activeSelection = null
      selectingSymbol.value = ''
    }
  })
  return selection.promise
}

function isCurrentSelection(selection) {
  return !componentUnmounted
    && activeSelection === selection
    && !selection.controller.signal.aborted
}

async function selectSymbol(selection) {
  const { symbol, controller } = selection
  let statusResponse = null
  try {
    statusResponse = await axios.get('/api/symbol/status', {
      params: { symbol, timeframe: '14d' },
      validateStatus: () => true,
      signal: controller.signal,
    })
  } catch {
    // A bounded prime request remains the durable fallback when status is unavailable.
  }

  if (!isCurrentSelection(selection)) return

  const plan = selectionWarmupPlan(
    statusResponse?.data || { status: 'missing' },
    statusResponse?.status || 0,
  )
  let bootstrapStart = null
  const requests = [axios.post('/api/prime-calculator', { symbol })]

  if (plan.startIntraday) {
    requests.push(axios.post('/api/intraday/pull', { symbols: [symbol] }))
  } else if (plan.startPrime) {
    requests.push(
      axios.post('/api/prime', { symbol, timeframe: '14d' })
        .then((response) => {
          bootstrapStart = response?.data || null
        }),
    )
  }

  await Promise.allSettled(requests)

  if (!isCurrentSelection(selection)) return

  window.dispatchEvent(new CustomEvent('select-symbol', {
    detail: {
      symbol,
      symbolStatus: statusResponse?.data || null,
      symbolStatusHttpStatus: statusResponse?.status || null,
      bootstrapStart,
    },
  }))
}

async function handleRemoveFromWatchlist(id) {
  if (removingIds.value.has(id)) return

  removingIds.value = new Set([...removingIds.value, id])
  watchlistError.value = ''
  try {
    await axios.delete(`/api/watchlist/${id}`)
    await reloadWatchlist()
  } catch {
    watchlistError.value = 'We could not remove that symbol. Try again.'
  } finally {
    const next = new Set(removingIds.value)
    next.delete(id)
    removingIds.value = next
  }
}

async function openMobileWatchlist() {
  if (showMobileWatchlist.value) return
  bodyOverflowBeforeDrawer = document.body.style.overflow
  document.body.style.overflow = 'hidden'
  drawerBodyLocked = true
  showMobileWatchlist.value = true
  await nextTick()
  mobileWatchlistDrawer.value?.querySelector('[data-watchlist-search]')?.focus()
}

function closeMobileWatchlist(restoreFocus = true) {
  if (!showMobileWatchlist.value) return
  showMobileWatchlist.value = false
  if (drawerBodyLocked) {
    document.body.style.overflow = bodyOverflowBeforeDrawer
    drawerBodyLocked = false
  }
  if (restoreFocus) nextTick(() => mobileWatchlistButton.value?.focus())
}

function handleDrawerKeydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault()
    closeMobileWatchlist()
    return
  }

  if (event.key !== 'Tab') return
  const focusable = [...(mobileWatchlistDrawer.value?.querySelectorAll(
    'button:not([disabled]), input:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])',
  ) || [])].filter((element) => element.getClientRects().length > 0 || import.meta.env.MODE === 'test')
  if (!focusable.length) return

  const first = focusable[0]
  const last = focusable[focusable.length - 1]
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

function closeDrawerOnEscape(event) {
  if (showMobileWatchlist.value && event.key === 'Escape') {
    event.preventDefault()
    closeMobileWatchlist()
  }
}

onMounted(() => {
  componentUnmounted = false
  setSelectedSymbol(typeof window !== 'undefined' ? localStorage.getItem('calculator_last_symbol') : '')
  syncSelectedSymbolFromLocation()
  reloadWatchlist()
  window.addEventListener('watchlist-updated', handleWatchlistUpdated)
  window.addEventListener('select-symbol', syncSelectedSymbolFromEvent)
  window.addEventListener('dashboard-symbol-changed', syncSelectedSymbolFromEvent)
  window.addEventListener('popstate', syncSelectedSymbolFromLocation)
  document.addEventListener('keydown', closeDrawerOnEscape)
})

onUnmounted(() => {
  componentUnmounted = true
  reloadSequence += 1
  activeReloadController?.abort()
  activeReloadController = null
  activeSelection?.controller.abort()
  activeSelection = null
  if (drawerBodyLocked) document.body.style.overflow = bodyOverflowBeforeDrawer
  drawerBodyLocked = false
  window.removeEventListener('watchlist-updated', handleWatchlistUpdated)
  window.removeEventListener('select-symbol', syncSelectedSymbolFromEvent)
  window.removeEventListener('dashboard-symbol-changed', syncSelectedSymbolFromEvent)
  window.removeEventListener('popstate', syncSelectedSymbolFromLocation)
  document.removeEventListener('keydown', closeDrawerOnEscape)
})
</script>
