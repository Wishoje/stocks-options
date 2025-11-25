<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import AppShell from '@/Components/AppShell.vue'
import { ref, onMounted, computed, watch } from 'vue'
import axios from 'axios'

const hotSymbols = ref([])
const hotItems = ref([])
const universeMeta = ref({
  tradeDate: null,
  source: null,
  backendSource: null,
  count: 0,
})
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

const watchlistHitCount = computed(() => {
  const set = new Set(hotSymbols.value)
  let hits = 0
  for (const sym of watchlistSymbols.value) {
    if (set.has(sym)) hits++
  }
  return hits
})

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

    universeMeta.value = {
      tradeDate: data.trade_date ?? null,
      source: data.source ?? null,
      backendSource: data.meta?.source ?? null,
      count: data.meta?.count ?? hotSymbols.value.length,
    }

    hotItems.value = Array.isArray(data?.items) ? data.items : []
    hotSymbols.value = hotItems.value.length
        ? hotItems.value.map(i => i.symbol)
        : (Array.isArray(data?.symbols) ? data.symbols : [])
  } catch (e) {
    error.value = e?.response?.data?.error || e.message
  } finally {
    loading.value = false
  }
}

const usingFallback = computed(
  () => universeMeta.value.source === 'fallback_db'
)

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

                <span class="hidden sm:inline">
                    · Watchlist hits:
                    <span class="font-mono text-emerald-300">{{ watchlistHitCount }}</span>
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

        <div class="flex items-center justify-between text-[11px] text-gray-400 px-1">
            <div class="flex items-center gap-2">
                <span v-if="universeMeta.tradeDate">
                Universe date:
                <span class="font-mono text-cyan-300">{{ universeMeta.tradeDate }}</span>
                </span>
                <span v-if="usingFallback" class="ml-1 text-amber-400">
                (fallback ranking)
                </span>
            </div>
            <div>
                Window: last {{ days }}d
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
            v-for="item in hotItems.length ? hotItems : hotSymbols.map(s => ({ symbol: s }))"
            :key="item.symbol"
            @click="selectSymbol(item.symbol)"
            class="px-3 py-2 bg-gray-800/70 border border-gray-700 rounded-lg
                    hover:bg-gray-700/80 transition text-sm font-mono text-cyan-400
                    flex flex-col gap-1"
            >
            <div class="flex items-center justify-between gap-2">
                <span class="truncate">{{ item.symbol }}</span>
                <span
                v-if="item.rank"
                class="text-[10px] px-1.5 py-0.5 rounded-full bg-gray-900 border border-gray-700 text-gray-300"
                >
                #{{ item.rank }}
                </span>
            </div>

            <div class="flex items-center justify-between text-[11px] text-gray-400">
                <span v-if="item.total_volume">Vol: {{ item.total_volume.toLocaleString() }}</span>
                <span v-if="item.put_call">PCR: {{ item.put_call }}</span>
                <span v-if="item.last_price">Last: {{ item.last_price }}</span>
            </div>

            <!-- existing watchlist toggle -->
            <div class="flex justify-end">
                <span
                @click.stop="toggleWatchlist(item.symbol)"
                class="inline-flex items-center justify-center w-6 h-6 rounded-full text-[11px]"
                :class="isInWatchlist(item.symbol)
                    ? 'bg-emerald-600 text-white'
                    : 'bg-gray-700 text-gray-300 hover:bg-gray-600'"
                >
                +
                </span>
            </div>
            </button>
          </div>
        </div>
      </AppShell>
    </div>
  </AppLayout>
</template>
