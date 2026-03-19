<!-- AppShell.vue -->
<template>
  <div class="min-h-screen bg-gray-900 text-white">
    <div class="flex min-w-0">
      <aside
        class="hidden h-screen overflow-y-auto border-r border-gray-800 bg-gray-900/80 backdrop-blur md:sticky md:top-0 md:block md:w-[320px] lg:w-[360px]"
      >
        <LeftPanel
          :watchlist="watchlistItems"
          :pinMap="pinMap"
          :uaMap="uaMap"
          @select="handleSelectSymbol"
          @add="reloadWatchlist"
          @remove="handleRemoveFromWatchlist"
          @refresh="reloadWatchlist"
        >
          <template #chips="{ symbol }">
            <span
              v-if="pinMap[symbol]?.headline_pin != null"
              class="rounded-full px-2 py-0.5 text-[11px]"
              :class="pinBadgeClass(pinMap[symbol].headline_pin)"
            >
              Pin {{ pinMap[symbol].headline_pin }}
            </span>
            <span
              v-if="uaMap[symbol]?.count > 0"
              class="ml-1 rounded-full bg-yellow-400/15 px-2 py-0.5 text-[10px] font-semibold text-yellow-300 ring-1 ring-yellow-400/25"
              title="UA today"
            >
              UA
            </span>
          </template>
        </LeftPanel>
      </aside>

      <div v-if="showMobileWatchlist" class="fixed inset-0 z-[80] md:hidden">
        <button
          type="button"
          class="absolute inset-0 bg-black/70 backdrop-blur-sm"
          aria-label="Close watchlist"
          @click="showMobileWatchlist = false"
        />

        <aside
          class="relative z-10 h-full w-[88vw] max-w-[360px] overflow-y-auto border-r border-gray-800 bg-gray-900 shadow-2xl"
        >
          <button
            type="button"
            class="absolute right-3 top-3 rounded-lg border border-white/10 bg-white/5 p-2 text-gray-300 hover:bg-white/10 hover:text-white"
            aria-label="Close watchlist"
            @click="showMobileWatchlist = false"
          >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>

          <LeftPanel
            :watchlist="watchlistItems"
            :pinMap="pinMap"
            :uaMap="uaMap"
            @select="handleSelectSymbol"
            @add="reloadWatchlist"
            @remove="handleRemoveFromWatchlist"
            @refresh="reloadWatchlist"
          >
            <template #chips="{ symbol }">
              <span
                v-if="pinMap[symbol]?.headline_pin != null"
                class="rounded-full px-2 py-0.5 text-[11px]"
                :class="pinBadgeClass(pinMap[symbol].headline_pin)"
              >
                Pin {{ pinMap[symbol].headline_pin }}
              </span>
              <span
                v-if="uaMap[symbol]?.count > 0"
                class="ml-1 rounded-full bg-yellow-400/15 px-2 py-0.5 text-[10px] font-semibold text-yellow-300 ring-1 ring-yellow-400/25"
                title="UA today"
              >
                UA
              </span>
            </template>
          </LeftPanel>
        </aside>
      </div>

      <main class="min-w-0 flex-1 overflow-y-auto p-3 md:p-6">
        <div class="mb-3 md:hidden">
          <button
            type="button"
            class="inline-flex items-center gap-2 rounded-xl border border-gray-700 bg-gray-800/80 px-3 py-2 text-sm font-medium text-white shadow-lg shadow-black/20"
            @click="showMobileWatchlist = true"
          >
            <svg class="h-4 w-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7h18M3 12h18M3 17h18" />
            </svg>
            Watchlist
            <span class="rounded-full bg-cyan-500/15 px-2 py-0.5 text-xs text-cyan-300">
              {{ watchlistItems.length }}
            </span>
          </button>
        </div>

        <slot />
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import axios from 'axios'
import LeftPanel from './LeftPanel.vue'

const watchlistItems = ref([])
const pinMap = ref({})
const uaMap = ref({})
const showMobileWatchlist = ref(false)

function pinBadgeClass(score) {
  if (score >= 70) return 'bg-yellow-400/20 text-yellow-300 ring-1 ring-yellow-400/30'
  if (score >= 40) return 'bg-amber-500/15 text-amber-300 ring-1 ring-amber-500/20'
  return 'bg-gray-600/40 text-gray-200 ring-1 ring-gray-500/30'
}

function handleWatchlistUpdated() {
  reloadWatchlist()
}

async function reloadWatchlist() {
  const { data } = await axios.get('/api/watchlist')
  watchlistItems.value = Array.isArray(data) ? data : []
  await loadPinsAndUA()
}

async function loadPinsAndUA() {
  const syms = [...new Set(watchlistItems.value.map((item) => item.symbol))]

  try {
    const { data } = await axios.get('/api/expiry-pressure/batch', { params: { symbols: syms, days: 3 } })
    pinMap.value = data?.items || {}
  } catch {
    pinMap.value = {}
  }

  const out = {}
  await Promise.all(syms.map(async (symbol) => {
    try {
      const { data } = await axios.get('/api/ua', { params: { symbol } })
      out[symbol] = { data_date: data?.data_date || null, count: (data?.items || []).length }
    } catch {
      out[symbol] = { data_date: null, count: 0 }
    }
  }))
  uaMap.value = out
}

async function handleSelectSymbol(symbol) {
  showMobileWatchlist.value = false

  await Promise.allSettled([
    axios.post('/api/intraday/pull', { symbols: [symbol] }),
    axios.post('/api/prime-calculator', { symbol }),
    axios.get('/api/symbol/status', { params: { symbol, timeframe: '14d' } }),
  ])

  window.dispatchEvent(new CustomEvent('select-symbol', { detail: { symbol } }))
}

async function handleRemoveFromWatchlist(id) {
  await axios.delete(`/api/watchlist/${id}`)
  await reloadWatchlist()
}

onMounted(() => {
  reloadWatchlist()
  window.addEventListener('watchlist-updated', handleWatchlistUpdated)
})

onUnmounted(() => {
  window.removeEventListener('watchlist-updated', handleWatchlistUpdated)
})
</script>
