<!-- AppShell.vue -->
<template>
  <div class="min-h-screen bg-gray-900 text-white">
    <div class="flex">
      <!-- LEFT: Watchlist panel -->
      <aside
        class="w-[360px] border-r border-gray-800 bg-gray-900/80 backdrop-blur sticky top-0 h-screen overflow-y-auto"
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
              class="text-[11px] px-2 py-0.5 rounded-full"
              :class="pinBadgeClass(pinMap[symbol].headline_pin)"
            >
              Pin {{ pinMap[symbol].headline_pin }}
            </span>
            <span v-if="uaMap[symbol]?.count > 0" class="ml-1" title="UA today">🔔</span>
          </template>
        </LeftPanel>
      </aside>

      <!-- RIGHT: main content -->
      <main class="flex-1 p-6 overflow-y-auto">
        <slot />
      </main>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'
import LeftPanel from './LeftPanel.vue'

const watchlistItems = ref([])
const pinMap = ref({})
const uaMap  = ref({})

function pinBadgeClass(score) {
  if (score >= 70) return 'bg-yellow-400/20 text-yellow-300 ring-1 ring-yellow-400/30'
  if (score >= 40) return 'bg-amber-500/15 text-amber-300 ring-1 ring-amber-500/20'
  return 'bg-gray-600/40 text-gray-200 ring-1 ring-gray-500/30'
}

async function reloadWatchlist() {
  const { data } = await axios.get('/api/watchlist')
  watchlistItems.value = Array.isArray(data) ? data : []
  await loadPinsAndUA()
}

async function loadPinsAndUA() {
  const syms = [...new Set(watchlistItems.value.map(i=>i.symbol))]
  // Pin batch (already have endpoint)
  try {
    const { data } = await axios.get('/api/expiry-pressure/batch', { params: { symbols: syms, days: 3 } })
    pinMap.value = data?.items || {}
  } catch { pinMap.value = {} }

  // UA mini badges
  const out = {}
  await Promise.all(syms.map(async s => {
    try {
      const { data } = await axios.get('/api/ua', { params: { symbol: s } })
      out[s] = { data_date: data?.data_date || null, count: (data?.items || []).length }
    } catch { out[s] = { data_date: null, count: 0 } }
  }))
  uaMap.value = out
}

async function handleSelectSymbol(sym) {
  // Kick off data producers first
  // await Promise.allSettled([
  //   axios.post('/api/intraday/pull', { symbols: [sym] }),
  //   axios.post('/api/prime-calculator', { symbol: sym }),
  // ])

  window.dispatchEvent(new CustomEvent('select-symbol', { detail: { symbol: sym } }))
}

async function handleRemoveFromWatchlist(id) {
  await axios.delete(`/api/watchlist/${id}`)
  await reloadWatchlist()
}

onMounted(reloadWatchlist)
</script>
