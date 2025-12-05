<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import AppShell from '@/Components/AppShell.vue'
import { ref, onMounted, computed, watch } from 'vue'
import axios from 'axios'

// --- Master mode: "volume" scanner vs "gex" (wall) scanner ---
const scannerMode = ref('gex') // 'volume' | 'gex'

// Volume universe / hot options
const hotSymbols = ref([])
const hotItems = ref([])
const universeMeta = ref({
  tradeDate: null,
  source: null,
  backendSource: null,
  count: 0,
  totalVol: null,
  avgPcr: null,
})
const loading = ref(false)
const error = ref('')

// Controls
const limit = ref(200) // how many symbols to fetch
const days  = ref(10)  // lookback window

// Watchlist state
const watchlist = ref([])       // [{id, symbol, ...}, ...]
const watchlistBusy = ref({})   // { [symbol]: boolean }

const watchlistSymbols = computed(
  () => new Set(watchlist.value.map(w => w.symbol))
)

// Wall scanner state
const wallHits    = ref([])      // [{ symbol, spot, timeframe, hits, walls: {...} }]
const wallNearPct = ref(1.0)     // ±% from wall
const wallNearPts = ref(null)    // optional ±$ from wall

// For volume: how many watchlist names are in the volume universe
const watchlistHitCount = computed(() => {
  const set = new Set(hotSymbols.value)
  let hits = 0
  for (const sym of watchlistSymbols.value) {
    if (set.has(sym)) hits++
  }
  return hits
})

// Order we want timeframes in the UI
const timeframeOrder = ['1d', '7d', '14d', '30d']

// Group wall hits by timeframe
const wallHitsByTimeframe = computed(() => {
  const out = {}
  for (const hit of wallHits.value) {
    const tf = hit.timeframe || 'N/A'
    if (!out[tf]) out[tf] = []
    out[tf].push(hit)
  }
  return out
})

const orderedTimeframes = computed(() => {
  const groups = wallHitsByTimeframe.value

  const primary = timeframeOrder.filter(tf => groups[tf]?.length)
  const extra = Object.keys(groups).filter(
    tf => !timeframeOrder.includes(tf) && groups[tf]?.length
  )

  return [...primary, ...extra]
})

// --- Helpers ---
function isInWatchlist(sym) {
  return watchlistSymbols.value.has(sym)
}

function formatNumber(v) {
  if (v == null) return '—'
  const n = Number(v)
  if (!Number.isFinite(n)) return '—'
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M'
  if (n >= 1_000) return (n / 1_000).toFixed(1) + 'K'
  return n.toLocaleString()
}

function pcrClass(pcr) {
  const v = Number(pcr)
  if (!Number.isFinite(v)) return 'text-gray-400'
  if (v > 1.2) return 'text-red-400'      // put-heavy
  if (v < 0.7) return 'text-emerald-400'  // call-heavy
  return 'text-yellow-300'                // mixed
}

function pcrTag(pcr) {
  const v = Number(pcr)
  if (!Number.isFinite(v)) return ''
  if (v > 1.2) return 'Put-heavy'
  if (v < 0.7) return 'Call-heavy'
  return 'Balanced'
}

// --- API loaders ---

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
      totalVol: data.meta?.total_vol ?? null,
      avgPcr: data.meta?.avg_pcr ?? null,
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

async function loadWatchlist() {
  try {
    const { data } = await axios.get('/api/watchlist')
    watchlist.value = Array.isArray(data) ? data : []
  } catch (e) {
    console.error('watchlist load failed', e)
  }
}

// Master wall scanner – behaves differently per mode
async function loadWallHits() {
  wallHits.value = []

  // Choose symbol universe for GEX scan
  let symbols = []

  if (!watchlist.value.length) {
    // no watchlist → nothing to scan
    return
  }

  if (scannerMode.value === 'volume') {
    // Volume mode: only scan names that are both in watchlist AND hot universe
    const hotSet = new Set(hotSymbols.value)
    symbols = watchlist.value
      .map(w => w.symbol)
      .filter(sym => hotSet.has(sym))
  } else {
    // GEX mode: scan ALL watchlist names
    symbols = watchlist.value.map(w => w.symbol)
  }

  symbols = symbols
    .map(s => s && s.toUpperCase())
    .filter(Boolean)

  if (!symbols.length) return

  try {
    const { data } = await axios.post('/api/scanner/walls', {
      symbols,
      near_pct: wallNearPct.value,
      near_pts: wallNearPts.value,
      timeframes: ['1d', '7d', '14d', '30d'],
    })

    wallHits.value = Array.isArray(data?.items) ? data.items : []
  } catch (e) {
    console.error('wall scanner error', e)
  }
}

// --- UI interactions ---

function selectSymbol(sym) {
  window.dispatchEvent(
    new CustomEvent('select-symbol', { detail: { symbol: sym } })
  )
}

async function toggleWatchlist(sym) {
  const existing = watchlist.value.find(w => w.symbol === sym)
  watchlistBusy.value = { ...watchlistBusy.value, [sym]: true }

  try {
    if (existing) {
      await axios.delete(`/api/watchlist/${existing.id}`)
      watchlist.value = watchlist.value.filter(w => w.symbol !== sym)
    } else {
      const { data } = await axios.post('/api/watchlist', { symbol: sym })
      if (data && data.id) {
        watchlist.value.push(data)
      } else {
        await loadWatchlist()
      }
    }

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

// "Load more" helper for volume universe
function loadMore() {
  limit.value = Math.min(limit.value + 100, 600)
}

// Derived meta for headings
const usingFallback = computed(
  () => universeMeta.value.source === 'fallback_db'
)

const modeTitle = computed(() =>
  scannerMode.value === 'volume'
    ? 'Most Active Option Underlyings'
    : 'GEX Wall Scanner (Watchlist)'
)

const modeSubtitle = computed(() =>
  scannerMode.value === 'volume'
    ? 'Ranked by open interest & volume over the selected lookback window.'
    : 'Shows your watchlist names sitting near large GEX walls across multiple timeframes.'
)

// --- Watches & lifecycle ---

// When controls change, only reload volume universe if we are in volume mode
watch([limit, days], () => {
  if (scannerMode.value === 'volume') {
    loadHotOptions()
  }
})

// When switching modes, ensure the right data is fresh
watch(scannerMode, (mode) => {
  if (mode === 'volume') {
    loadHotOptions()
  } else {
    // GEX mode – recompute wall hits based on full watchlist
    loadWallHits()
  }
})

onMounted(async () => {
  await loadWatchlist()
  await loadHotOptions()
  await loadWallHits()
})
</script>

<template>
  <AppLayout title="Scanner">
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
        Scanner – Options Universe
      </h2>
    </template>

    <div class="py-0">
      <AppShell>
        <div class="space-y-4">
          <!-- Top card with mode toggle + description -->
          <div class="bg-gray-800/60 border border-gray-700 rounded-xl p-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
              <div>
                <h3 class="text-lg font-semibold mb-1">
                  {{ modeTitle }}
                </h3>
                <p class="text-sm text-gray-400">
                  {{ modeSubtitle }}
                </p>
              </div>

              <!-- Master mode toggle -->
              <div class="inline-flex rounded-lg overflow-hidden border border-gray-700">
                <button
                  type="button"
                  class="px-3 py-1.5 text-xs font-medium transition"
                  :class="scannerMode === 'volume'
                    ? 'bg-cyan-600 text-white'
                    : 'bg-gray-900 text-gray-300 hover:bg-gray-700'"
                  @click="scannerMode = 'volume'"
                >
                  Volume scanner
                </button>
                <button
                  type="button"
                  class="px-3 py-1.5 text-xs font-medium transition"
                  :class="scannerMode === 'gex'
                    ? 'bg-indigo-600 text-white'
                    : 'bg-gray-900 text-gray-300 hover:bg-gray-700'"
                  @click="scannerMode = 'gex'"
                >
                  GEX wall scanner
                </button>
              </div>
            </div>
          </div>

          <!-- Controls row – only for volume mode -->
          <div
            v-if="scannerMode === 'volume'"
            class="bg-gray-900/70 border border-gray-800 rounded-xl px-4 py-3 flex flex-wrap items-center gap-3"
          >
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
                · Watchlist in universe:
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

          <!-- Meta + Wall hits row
               Volume: show meta only
               GEX:    show meta + wall panel
          -->
          <!-- Volume mode meta -->
          <div
            v-if="scannerMode === 'volume'"
            class="text-[11px] text-gray-400 px-1 space-y-1"
          >
            <div class="flex items-center gap-3 flex-wrap">
              <span v-if="universeMeta.tradeDate">
                Universe date:
                <span class="font-mono text-cyan-300">{{ universeMeta.tradeDate }}</span>
              </span>

              <span v-if="universeMeta.totalVol">
                Total vol:
                <span class="font-mono text-gray-200">
                  {{ formatNumber(universeMeta.totalVol) }}
                </span>
              </span>

              <span v-if="universeMeta.avgPcr">
                Avg PCR:
                <span class="font-mono" :class="pcrClass(universeMeta.avgPcr)">
                  {{ Number(universeMeta.avgPcr).toFixed(2) }}
                </span>
              </span>

              <span v-if="usingFallback" class="ml-1 text-amber-400">
                (fallback ranking)
              </span>
            </div>

            <div>
              Window: last {{ days }}d · Mode: Volume
            </div>
          </div>

          <!-- GEX mode meta + wall panel (stacked) -->
          <div
            v-else
            class="text-[11px] text-gray-400 px-1 space-y-2"
          >
            <!-- Universe meta / window (top) -->
            <div class="space-y-1">
              <div class="flex items-center gap-3 flex-wrap">
                <span v-if="universeMeta.tradeDate">
                  Universe date:
                  <span class="font-mono text-cyan-300">{{ universeMeta.tradeDate }}</span>
                </span>

                <span v-if="usingFallback" class="ml-1 text-amber-400">
                  (fallback ranking used for base universe)
                </span>
              </div>

              <div>
                Universe: Watchlist ({{ watchlist.length }} names) · Mode: GEX
              </div>
            </div>

            <!-- Wall hits panel (full width, below) -->
            <div class="bg-gray-900/70 border border-gray-800 rounded-xl px-4 py-3 space-y-2">
              <div class="flex items-center gap-2 justify-between">
                <div class="flex items-center gap-2">
                  <span class="text-xs uppercase tracking-wide text-gray-400">
                    Wall hits (watchlist)
                  </span>

                  <span class="text-[10px] text-gray-500">
                    scanning: all watchlist names
                  </span>
                </div>

                <div class="flex items-center gap-1 text-[11px] text-gray-300">
                  <span>±%</span>
                  <input
                    v-model.number="wallNearPct"
                    type="number"
                    step="0.1"
                    class="w-16 px-2 py-1 bg-gray-800 border border-gray-700 rounded
                          focus:outline-none focus:border-cyan-500"
                  />
                  <span class="ml-2">±$</span>
                  <input
                    v-model.number="wallNearPts"
                    type="number"
                    step="0.1"
                    class="w-16 px-2 py-1 bg-gray-800 border border-gray-700 rounded
                          focus:outline-none focus:border-cyan-500"
                    placeholder="(opt)"
                  />
                  <button
                    type="button"
                    @click="loadWallHits"
                    class="ml-2 px-3 py-1.5 rounded-lg text-[11px] font-medium
                          bg-cyan-600 hover:bg-cyan-500 text-white"
                  >
                    Scan
                  </button>
                </div>
              </div>

              <!-- Empty state -->
              <div v-if="!wallHits.length" class="text-[11px] text-gray-500">
                No watchlist names currently sitting on the biggest walls at these thresholds.
              </div>

              <!-- Hits grouped by timeframe -->
              <div v-else class="space-y-2 max-h-72 pr-1">
                <div
                  v-for="tf in orderedTimeframes"
                  :key="tf"
                  class="border border-gray-800 rounded-lg p-2 bg-gray-900/60"
                >
                  <div class="flex items-center justify-between mb-1">
                    <div class="text-[10px] uppercase tracking-wide text-gray-400">
                      {{ tf }} walls
                    </div>
                    <div class="text-[10px] text-gray-500">
                      {{ wallHitsByTimeframe[tf].length }} names
                    </div>
                  </div>

                  <div class="flex flex-wrap gap-1">
                    <button
                      v-for="hit in wallHitsByTimeframe[tf]"
                      :key="hit.symbol + '-' + tf"
                      @click="selectSymbol(hit.symbol)"
                      class="px-2 py-1 rounded-lg text-[11px] font-mono
                            bg-gray-800 border border-gray-700 hover:bg-gray-700
                            flex items-center gap-2"
                    >
                      <span class="text-cyan-300">{{ hit.symbol }}</span>

                      <span
                        v-if="hit.hits.includes('eod_put')"
                        class="px-1 rounded bg-emerald-800/70 text-emerald-100"
                      >
                        EOD put {{ hit.walls.eod_put?.strike }}
                      </span>

                      <span
                        v-if="hit.hits.includes('intraday_call')"
                        class="px-1 rounded bg-red-800/70 text-red-100"
                      >
                        Live call {{ hit.walls.intraday_call?.strike }}
                      </span>

                      <span
                        v-if="hit.walls.eod_put"
                        class="text-[10px] text-gray-400"
                      >
                        Δ{{ hit.walls.eod_put.distance_pc.toFixed(2) }}%
                      </span>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>


          <!-- Main body: Volume grid -->
          <div v-if="scannerMode === 'volume'">
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
                    class="text-[10px] px-1.5 py-0.5 rounded-full
                           bg-gray-900 border border-gray-700 text-gray-300"
                  >
                    #{{ item.rank }}
                  </span>
                </div>

                <div class="flex items-center justify-between text-[11px] text-gray-400">
                  <span v-if="item.total_volume">
                    Vol:
                    <span class="font-mono text-gray-200">
                      {{ formatNumber(item.total_volume) }}
                    </span>
                  </span>

                  <span v-if="item.put_call">
                    PCR:
                    <span class="font-mono" :class="pcrClass(item.put_call)">
                      {{ Number(item.put_call).toFixed(2) }}
                    </span>
                  </span>

                  <span v-if="item.last_price">
                    Last:
                    <span class="font-mono text-gray-200">
                      {{ Number(item.last_price).toFixed(2) }}
                    </span>
                  </span>
                </div>

                <div v-if="item.put_call" class="mt-0.5">
                  <span
                    class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px]
                           bg-gray-900/80 border border-gray-700"
                    :class="pcrClass(item.put_call)"
                  >
                    {{ pcrTag(item.put_call) }}
                  </span>
                </div>

                <div class="flex justify-end">
                  <button
                    type="button"
                    @click.stop="toggleWatchlist(item.symbol)"
                    class="inline-flex items-center justify-center w-6 h-6 rounded-full text-[11px]"
                    :class="isInWatchlist(item.symbol)
                      ? 'bg-emerald-600 text-white'
                      : 'bg-gray-700 text-gray-300 hover:bg-gray-600'"
                  >
                    <span v-if="!watchlistBusy[item.symbol]">
                      {{ isInWatchlist(item.symbol) ? '✓' : '+' }}
                    </span>
                    <span v-else class="animate-pulse">…</span>
                  </button>
                </div>
              </button>
            </div>
          </div>

          <!-- GEX mode currently uses wall panel above as main output -->
        </div>
      </AppShell>
    </div>
  </AppLayout>
</template>
