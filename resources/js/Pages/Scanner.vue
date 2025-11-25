<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import AppShell from '@/Components/AppShell.vue'
import { ref, onMounted, computed, watch } from 'vue'
import axios from 'axios'

const hotSymbols = ref([])
const loading = ref(false)
const error = ref('')

// NEW: controls
const limit = ref(200)  // how many symbols to fetch
const days  = ref(10)   // lookback window

// NEW: watchlist state
const watchlist = ref([])             // [{id, symbol, ...}, ...]
const watchlistBusy = ref({})         // { [symbol]: boolean }

const watchlistSymbols = computed(
  () => new Set(watchlist.value.map(w => w.symbol))
)

function isInWatchlist(sym) {
  return watchlistSymbols.value.has(sym)
}

async function loadHotOptions() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await axios.get('/api/hot-options', {
      params: {
        limit: limit.value,
        days:  days.value,
      },
    })
    hotSymbols.value = Array.isArray(data?.symbols) ? data.symbols : []
  } catch (e) {
    error.value = e?.response?.data?.error || e.message
  } finally {
    loading.value = false
  }
}

// NEW: reload when controls change
watch([limit, days], () => {
  // don’t hammer API on every tiny change if you ever use text inputs
  loadHotOptions()
})

// NEW: load current watchlist
async function loadWatchlist() {
  try {
    const { data } = await axios.get('/api/watchlist')
    watchlist.value = Array.isArray(data) ? data : []
  } catch (e) {
    // silently ignore for now
    console.error('watchlist load failed', e)
  }
}

function selectSymbol(sym) {
  window.dispatchEvent(
    new CustomEvent('select-symbol', { detail: { symbol: sym } })
  )
}

// NEW: toggle / add-remove watchlist from scanner
async function toggleWatchlist(sym) {
  const existing = watchlist.value.find(w => w.symbol === sym)
  watchlistBusy.value = { ...watchlistBusy.value, [sym]: true }

  try {
    if (existing) {
      // remove
      await axios.delete(`/api/watchlist/${existing.id}`)
      watchlist.value = watchlist.value.filter(w => w.symbol !== sym)
    } else {
      // add
      const { data } = await axios.post('/api/watchlist', { symbol: sym })
      if (data && data.id) {
        watchlist.value.push(data)
      } else {
        await loadWatchlist()
      }
    }

    // 🔔 tell the rest of the app the watchlist changed
    window.dispatchEvent(
      new CustomEvent('watchlist-updated', {
        detail: {
          symbols: watchlist.value.map(w => w.symbol),
        },
      })
    )
  } catch (e) {
    console.error('toggleWatchlist error', e)
  } finally {
    watchlistBusy.value = { ...watchlistBusy.value, [sym]: false }
  }
}

// NEW: "load more" helper
function loadMore() {
  // bump in chunks, cap at something sane
  limit.value = Math.min(limit.value + 100, 600)
  // watcher on limit will call loadHotOptions()
}

onMounted(async () => {
  await Promise.all([
    loadHotOptions(),
    loadWatchlist(),
  ])
})
</script>

<template>
  <AppLayout title="Scanner">
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
        Scanner – Top Option Symbols
      </h2>
    </template>

    <div class="py-0">
      <AppShell>
        <div class="space-y-4">
          <div class="bg-gray-800/60 border border-gray-700 rounded-xl p-4">
            <h3 class="text-lg font-semibold mb-2">Most Active Option Underlyings</h3>
            <p class="text-sm text-gray-400">
              Ranked by open interest &amp; volume over the selected lookback window.
            </p>
          </div>

          <!-- NEW: controls row -->
          <div class="bg-gray-900/70 border border-gray-800 rounded-xl px-4 py-3 flex flex-wrap items-center gap-3">
            <div class="flex items-center gap-2">
              <span class="text-xs uppercase tracking-wide text-gray-400">Universe</span>
              <div class="inline-flex rounded-lg overflow-hidden border border-gray-700">
                <button
                  v-for="opt in [100, 200, 400]"
                  :key="opt"
                  type="button"
                  @click="limit = opt"
                  class="px-3 py-1.5 text-xs font-medium transition"
                  :class="limit === opt
                    ? 'bg-cyan-600 text-white'
                    : 'bg-gray-800 text-gray-300 hover:bg-gray-700'"
                >
                  Top {{ opt }}
                </button>
              </div>
            </div>

            <div class="flex items-center gap-2">
              <span class="text-xs uppercase tracking-wide text-gray-400">Lookback</span>
              <div class="inline-flex rounded-lg overflow-hidden border border-gray-700">
                <button
                  v-for="opt in [5, 10, 20]"
                  :key="opt"
                  type="button"
                  @click="days = opt"
                  class="px-3 py-1.5 text-xs font-medium transition"
                  :class="days === opt
                    ? 'bg-indigo-600 text-white'
                    : 'bg-gray-800 text-gray-300 hover:bg-gray-700'"
                >
                  {{ opt }}d
                </button>
              </div>
            </div>

            <div class="ml-auto flex items-center gap-3 text-xs text-gray-400">
              <span>
                Loaded
                <span class="font-mono text-cyan-300">{{ hotSymbols.length }}</span>
                / {{ limit }}
              </span>

              <button
                type="button"
                @click="loadMore"
                class="px-3 py-1.5 rounded-lg text-[11px] font-medium
                       bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-200"
              >
                Load more
              </button>
            </div>
          </div>

          <div v-if="error" class="text-red-400 text-sm">
            {{ error }}
          </div>
          <div v-else-if="loading" class="text-gray-300 text-sm">
            Loading…
          </div>
          <div v-else class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-2">
            <button
              v-for="sym in hotSymbols"
              :key="sym"
              @click="selectSymbol(sym)"
              class="px-3 py-2 bg-gray-800/70 border border-gray-700 rounded-lg
                     hover:bg-gray-700/80 transition text-sm font-mono text-cyan-400
                     flex items-center justify-between gap-2"
            >
              <span class="truncate">{{ sym }}</span>

              <!-- Watchlist indicator / toggle -->
              <span
                @click.stop="toggleWatchlist(sym)"
                class="inline-flex items-center justify-center w-6 h-6 rounded-full text-[11px]"
                :class="isInWatchlist(sym)
                  ? 'bg-emerald-600 text-white'
                  : 'bg-gray-700 text-gray-300 hover:bg-gray-600'"
              >
                <!-- checkmark if already on watchlist, + otherwise -->
                <svg v-if="isInWatchlist(sym)" class="w-3 h-3" viewBox="0 0 20 20" fill="none">
                  <path
                    d="M5 10.5L8.5 14L15 6"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  />
                </svg>
                <svg v-else class="w-3 h-3" viewBox="0 0 20 20" fill="none">
                  <path
                    d="M10 4v12M4 10h12"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  />
                </svg>
              </span>
            </button>
          </div>
        </div>
      </AppShell>
    </div>
  </AppLayout>
</template>
