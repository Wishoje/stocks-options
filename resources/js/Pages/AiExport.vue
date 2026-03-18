<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import axios from 'axios'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppShell from '@/Components/AppShell.vue'

const watchlist = ref([])
const watchlistLoading = ref(false)
const watchlistError = ref('')
const symbolFilter = ref('')
const exporting = ref(false)
const exportError = ref('')
const gexTimeframe = ref('30d')
const selectedSymbols = ref([])
const selectedIndicators = ref([])
const exports = ref([])
const activeExportId = ref(null)
let pollTimer = null

const indicatorOptions = [
  {
    key: 'wall_snapshots',
    label: 'Wall snapshots',
    description: 'Latest 1d/7d/14d/30d wall snapshot rows for each symbol.',
  },
  {
    key: 'gex_levels',
    label: 'GEX levels',
    description: 'Full EOD GEX payload including strike data and wall levels.',
  },
  {
    key: 'qscore',
    label: 'Q-score',
    description: 'Option, vol, momentum, and seasonality blend with explanations.',
  },
  {
    key: 'dealer_positioning',
    label: 'Dealer positioning',
    description: 'DEX by expiry and total dealer delta positioning.',
  },
  {
    key: 'expiry_pressure',
    label: 'Expiry pressure',
    description: 'Pin-risk and max-pain clusters for the next few expiries.',
  },
  {
    key: 'iv_skew',
    label: 'IV skew',
    description: '25-delta skew and curvature across expiries.',
  },
  {
    key: 'term_structure',
    label: 'Term structure',
    description: 'Implied volatility by expiry from near to far.',
  },
  {
    key: 'vrp',
    label: 'VRP',
    description: 'IV(1M), RV(20), raw VRP, and z-score.',
  },
  {
    key: 'seasonality',
    label: 'Seasonality',
    description: 'Five-day forward seasonal return profile and note.',
  },
  {
    key: 'unusual_activity',
    label: 'Unusual activity',
    description: 'Top unusual strikes with z-score, vol/OI, and premium metadata.',
  },
]

const defaultIndicatorKeys = indicatorOptions.map((option) => option.key)
selectedIndicators.value = [...defaultIndicatorKeys]

const filteredWatchlist = computed(() => {
  const query = symbolFilter.value.trim().toUpperCase()
  if (!query) return watchlist.value

  return watchlist.value.filter((item) => item.symbol.includes(query))
})

const selectedSymbolCount = computed(() => selectedSymbols.value.length)
const allSymbolsSelected = computed(() => {
  return watchlist.value.length > 0 && selectedSymbols.value.length === watchlist.value.length
})
const allIndicatorsSelected = computed(() => {
  return selectedIndicators.value.length === indicatorOptions.length
})

async function loadWatchlist() {
  watchlistLoading.value = true
  watchlistError.value = ''

  try {
    const { data } = await axios.get('/api/watchlist')
    watchlist.value = Array.isArray(data) ? data : []
    selectedSymbols.value = watchlist.value.map((item) => item.symbol)
  } catch (error) {
    watchlistError.value = error?.response?.data?.message || error.message || 'Failed to load watchlist.'
  } finally {
    watchlistLoading.value = false
  }
}

async function loadExports() {
  try {
    const { data } = await axios.get('/api/watchlist/eod-exports')
    exports.value = Array.isArray(data?.items) ? data.items : []

    if (!hasPendingExports()) {
      stopPolling()
    }
  } catch {}
}

function toggleSymbol(symbol) {
  if (selectedSymbols.value.includes(symbol)) {
    selectedSymbols.value = selectedSymbols.value.filter((item) => item !== symbol)
    return
  }

  selectedSymbols.value = [...selectedSymbols.value, symbol].sort()
}

function toggleIndicator(indicator) {
  if (selectedIndicators.value.includes(indicator)) {
    selectedIndicators.value = selectedIndicators.value.filter((item) => item !== indicator)
    return
  }

  selectedIndicators.value = [...selectedIndicators.value, indicator]
}

function selectAllSymbols() {
  selectedSymbols.value = watchlist.value.map((item) => item.symbol)
}

function clearSymbols() {
  selectedSymbols.value = []
}

function selectAllIndicators() {
  selectedIndicators.value = [...defaultIndicatorKeys]
}

function clearIndicators() {
  selectedIndicators.value = []
}

function formatStamp(value) {
  if (!value) return '--'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleString()
}

function statusTone(status) {
  if (status === 'completed') return 'text-emerald-300'
  if (status === 'failed') return 'text-rose-300'
  if (status === 'processing') return 'text-amber-300'
  return 'text-cyan-300'
}

function hasPendingExports() {
  return exports.value.some((item) => item.status === 'queued' || item.status === 'processing')
}

function stopPolling() {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

async function refreshExport(exportId) {
  const { data } = await axios.get(`/api/watchlist/eod-export/${exportId}`)
  const item = data?.item
  if (!item) return

  const next = exports.value.filter((row) => row.id !== item.id)
  next.unshift(item)
  exports.value = next.sort((a, b) => Number(b.id) - Number(a.id))

  if (item.status === 'completed' || item.status === 'failed') {
    if (activeExportId.value === item.id) {
      activeExportId.value = null
    }
  }
}

function ensurePolling() {
  if (pollTimer) return

  pollTimer = setInterval(async () => {
    const ids = exports.value
      .filter((item) => item.status === 'queued' || item.status === 'processing')
      .map((item) => item.id)

    if (!ids.length) {
      stopPolling()
      return
    }

    await Promise.allSettled(ids.map((id) => refreshExport(id)))
  }, 3000)
}

function downloadExport(item) {
  if (!item?.download_url) return
  window.location.assign(item.download_url)
}

async function exportBundle() {
  exportError.value = ''

  if (!selectedSymbols.value.length) {
    exportError.value = 'Select at least one symbol.'
    return
  }

  if (!selectedIndicators.value.length) {
    exportError.value = 'Select at least one indicator.'
    return
  }

  exporting.value = true

  try {
    const { data, status } = await axios.post('/api/watchlist/eod-export', {
      symbols: selectedSymbols.value,
      indicators: selectedIndicators.value,
      timeframe: gexTimeframe.value,
    })

    const item = data?.item || null
    if (status !== 202 || !item) {
      throw new Error('Export queue request failed.')
    }

    activeExportId.value = item.id
    exports.value = [item, ...exports.value.filter((row) => row.id !== item.id)]
      .sort((a, b) => Number(b.id) - Number(a.id))
    ensurePolling()
  } catch (error) {
    exportError.value = error?.response?.data?.message || error.message || 'Export failed.'
  } finally {
    exporting.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadWatchlist(), loadExports()])
  if (hasPendingExports()) ensurePolling()
})

onUnmounted(() => {
  stopPolling()
})
</script>

<template>
  <AppLayout title="AI Export">
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
        AI Export
      </h2>
    </template>

    <div class="py-0">
      <AppShell>
        <div class="space-y-5">
          <section class="rounded-2xl border border-cyan-900/60 bg-gradient-to-br from-cyan-950/80 via-gray-900 to-slate-950 p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
              <div class="max-w-3xl space-y-2">
                <div class="inline-flex items-center gap-2 rounded-full border border-cyan-500/30 bg-cyan-500/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.24em] text-cyan-200">
                  Queued Export
                </div>
                <h3 class="text-2xl font-semibold text-white">
                  Export your full watchlist EOD indicator bundle without waiting on the request
                </h3>
                <p class="text-sm leading-6 text-cyan-50/80">
                  Large bundles now run in the queue. Each symbol now includes a compact AI-friendly summary block on top of the full raw indicator payloads.
                  Submit the export, keep working, and download the JSON from the ready list below when it finishes.
                </p>
              </div>

              <div class="min-w-[260px] rounded-2xl border border-white/10 bg-black/20 p-4 text-sm text-gray-200">
                <div class="flex items-center justify-between">
                  <span class="text-gray-400">Symbols</span>
                  <span class="font-mono text-cyan-300">{{ selectedSymbolCount }}</span>
                </div>
                <div class="mt-2 flex items-center justify-between">
                  <span class="text-gray-400">Indicators</span>
                  <span class="font-mono text-cyan-300">{{ selectedIndicators.length }}</span>
                </div>
                <div class="mt-2 flex items-center justify-between">
                  <span class="text-gray-400">GEX timeframe</span>
                  <span class="font-mono text-white">{{ gexTimeframe.toUpperCase() }}</span>
                </div>
                <button
                  type="button"
                  class="mt-4 w-full rounded-xl bg-cyan-500 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-400 disabled:cursor-not-allowed disabled:opacity-60"
                  :disabled="exporting || !selectedSymbolCount || !selectedIndicators.length"
                  @click="exportBundle"
                >
                  {{ exporting ? 'Queueing export...' : 'Queue JSON export' }}
                </button>
              </div>
            </div>

            <p v-if="exportError" class="mt-3 text-sm text-rose-300">
              {{ exportError }}
            </p>
            <p v-if="activeExportId" class="mt-3 text-xs text-cyan-100/80">
              Export request #<span class="font-mono">{{ activeExportId }}</span> is running in the background.
            </p>
          </section>

          <div class="grid gap-5 xl:grid-cols-[1.15fr_0.85fr]">
            <section class="rounded-2xl border border-gray-800 bg-gray-900/90 p-5">
              <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                  <h3 class="text-lg font-semibold text-white">Symbols</h3>
                  <p class="text-sm text-gray-400">
                    Default is every symbol in your watchlist. Uncheck any names you do not want in the bundle.
                  </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                  <button
                    type="button"
                    class="rounded-lg border border-gray-700 bg-gray-800 px-3 py-1.5 text-xs font-medium text-gray-200 transition hover:bg-gray-700"
                    @click="selectAllSymbols"
                  >
                    {{ allSymbolsSelected ? 'All selected' : 'Select all' }}
                  </button>
                  <button
                    type="button"
                    class="rounded-lg border border-gray-700 bg-gray-800 px-3 py-1.5 text-xs font-medium text-gray-200 transition hover:bg-gray-700"
                    @click="clearSymbols"
                  >
                    Clear
                  </button>
                </div>
              </div>

              <div class="mt-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <input
                  v-model="symbolFilter"
                  type="text"
                  class="w-full rounded-xl border border-gray-700 bg-gray-950 px-3 py-2 text-sm text-white placeholder:text-gray-500 focus:border-cyan-500 focus:outline-none md:max-w-xs"
                  placeholder="Filter symbols"
                />
                <div class="text-xs text-gray-400">
                  Selected {{ selectedSymbolCount }} of {{ watchlist.length }}
                </div>
              </div>

              <div v-if="watchlistError" class="mt-4 text-sm text-rose-300">
                {{ watchlistError }}
              </div>
              <div v-else-if="watchlistLoading" class="mt-4 text-sm text-gray-400">
                Loading watchlist...
              </div>
              <div v-else-if="!watchlist.length" class="mt-4 rounded-xl border border-dashed border-gray-700 bg-gray-950/70 p-4 text-sm text-gray-300">
                No watchlist symbols yet. Add names from the
                <Link :href="route('options.scanner')" class="text-cyan-300 hover:text-cyan-200">
                  scanner
                </Link>
                or dashboard first.
              </div>
              <div v-else class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                <label
                  v-for="item in filteredWatchlist"
                  :key="item.id"
                  class="flex cursor-pointer items-center justify-between rounded-xl border px-3 py-2 transition"
                  :class="selectedSymbols.includes(item.symbol)
                    ? 'border-cyan-500/40 bg-cyan-500/10'
                    : 'border-gray-800 bg-gray-950/70 hover:border-gray-700'"
                >
                  <span class="font-mono text-sm text-white">{{ item.symbol }}</span>
                  <input
                    :checked="selectedSymbols.includes(item.symbol)"
                    type="checkbox"
                    class="h-4 w-4 rounded border-gray-600 bg-gray-900 text-cyan-400 focus:ring-cyan-500"
                    @change="toggleSymbol(item.symbol)"
                  />
                </label>
              </div>
            </section>

            <section class="space-y-5">
              <div class="rounded-2xl border border-gray-800 bg-gray-900/90 p-5">
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                  <div>
                    <h3 class="text-lg font-semibold text-white">Indicator groups</h3>
                    <p class="text-sm text-gray-400">
                      Everything is on by default. This controls how heavy the export is.
                    </p>
                  </div>

                  <div class="flex flex-wrap items-center gap-2">
                    <button
                      type="button"
                      class="rounded-lg border border-gray-700 bg-gray-800 px-3 py-1.5 text-xs font-medium text-gray-200 transition hover:bg-gray-700"
                      @click="selectAllIndicators"
                    >
                      {{ allIndicatorsSelected ? 'All selected' : 'Select all' }}
                    </button>
                    <button
                      type="button"
                      class="rounded-lg border border-gray-700 bg-gray-800 px-3 py-1.5 text-xs font-medium text-gray-200 transition hover:bg-gray-700"
                      @click="clearIndicators"
                    >
                      Clear
                    </button>
                  </div>
                </div>

                <div class="mt-4 space-y-2">
                  <label
                    v-for="option in indicatorOptions"
                    :key="option.key"
                    class="flex cursor-pointer gap-3 rounded-xl border px-3 py-3 transition"
                    :class="selectedIndicators.includes(option.key)
                      ? 'border-cyan-500/40 bg-cyan-500/10'
                      : 'border-gray-800 bg-gray-950/70 hover:border-gray-700'"
                  >
                    <input
                      :checked="selectedIndicators.includes(option.key)"
                      type="checkbox"
                      class="mt-1 h-4 w-4 rounded border-gray-600 bg-gray-900 text-cyan-400 focus:ring-cyan-500"
                      @change="toggleIndicator(option.key)"
                    />
                    <div>
                      <div class="text-sm font-medium text-white">{{ option.label }}</div>
                      <div class="text-xs leading-5 text-gray-400">{{ option.description }}</div>
                    </div>
                  </label>
                </div>
              </div>

              <div class="rounded-2xl border border-gray-800 bg-gray-900/90 p-5">
                <h3 class="text-lg font-semibold text-white">Export options</h3>
                <p class="mt-1 text-sm text-gray-400">
                  GEX payloads use one timeframe. Everything else pulls the latest available EOD-style dataset for each symbol.
                </p>

                <div class="mt-4">
                  <label class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500">
                    GEX timeframe
                  </label>
                  <select
                    v-model="gexTimeframe"
                    class="mt-2 w-full rounded-xl border border-gray-700 bg-gray-950 px-3 py-2 text-sm text-white focus:border-cyan-500 focus:outline-none"
                  >
                    <option value="1d">1D</option>
                    <option value="7d">7D</option>
                    <option value="14d">14D</option>
                    <option value="30d">30D</option>
                    <option value="90d">90D</option>
                    <option value="monthly">Monthly</option>
                  </select>
                </div>

                <div class="mt-4 rounded-xl border border-amber-500/20 bg-amber-500/10 p-3 text-xs leading-5 text-amber-100/90">
                  JSON is still the export format so nested strike data, clusters, and metadata survive intact for AI use. The new
                  <span class="font-semibold">summary</span> block gives AI a lighter first-pass view per symbol.
                </div>
              </div>

              <div class="rounded-2xl border border-gray-800 bg-gray-900/90 p-5">
                <h3 class="text-lg font-semibold text-white">Current payload shape</h3>
                <div class="mt-3 rounded-xl border border-gray-800 bg-gray-950/80 p-3 font-mono text-[11px] leading-5 text-cyan-100/80">
                  generated_at<br>
                  symbols[]<br>
                  indicators[]<br>
                  options.gex_timeframe<br>
                  items[].symbol<br>
                  items[].summary.data_dates<br>
                  items[].summary.wall<br>
                  items[].summary.qscore<br>
                  items[].summary.gex<br>
                  items[].summary.dealer_positioning<br>
                  items[].summary.expiry_pressure<br>
                  items[].summary.iv_skew<br>
                  items[].summary.term_structure<br>
                  items[].summary.vrp<br>
                  items[].summary.seasonality<br>
                  items[].summary.unusual_activity<br>
                  items[].wall_snapshots<br>
                  items[].gex_levels<br>
                  items[].qscore<br>
                  items[].dealer_positioning<br>
                  items[].expiry_pressure<br>
                  items[].iv_skew<br>
                  items[].term_structure<br>
                  items[].vrp<br>
                  items[].seasonality<br>
                  items[].unusual_activity
                </div>
              </div>

              <div class="rounded-2xl border border-gray-800 bg-gray-900/90 p-5">
                <div class="flex items-center justify-between gap-3">
                  <div>
                    <h3 class="text-lg font-semibold text-white">Recent exports</h3>
                    <p class="mt-1 text-sm text-gray-400">
                      When the status turns completed, the download button appears.
                    </p>
                  </div>
                  <button
                    type="button"
                    class="rounded-lg border border-gray-700 bg-gray-800 px-3 py-1.5 text-xs font-medium text-gray-200 transition hover:bg-gray-700"
                    @click="loadExports"
                  >
                    Refresh
                  </button>
                </div>

                <div v-if="!exports.length" class="mt-4 rounded-xl border border-dashed border-gray-700 bg-gray-950/60 p-4 text-sm text-gray-400">
                  No exports yet.
                </div>

                <div v-else class="mt-4 space-y-2">
                  <div
                    v-for="item in exports"
                    :key="item.id"
                    class="rounded-xl border border-gray-800 bg-gray-950/70 p-3"
                  >
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                      <div class="min-w-0">
                        <div class="flex items-center gap-2 text-sm">
                          <span class="font-mono text-cyan-200">#{{ item.id }}</span>
                          <span class="font-semibold" :class="statusTone(item.status)">
                            {{ item.status }}
                          </span>
                        </div>
                        <div class="mt-1 text-xs text-gray-400">
                          {{ item.symbol_count }} symbols | {{ item.indicator_count }} indicators | queued {{ formatStamp(item.created_at) }}
                        </div>
                        <div v-if="item.completed_at" class="mt-1 text-xs text-gray-500">
                          completed {{ formatStamp(item.completed_at) }}
                        </div>
                        <div v-if="item.error_message" class="mt-2 text-xs text-rose-300">
                          {{ item.error_message }}
                        </div>
                      </div>

                      <div class="flex flex-wrap items-center gap-2">
                        <button
                          v-if="item.status === 'completed' && item.download_url"
                          type="button"
                          class="rounded-lg bg-emerald-500 px-3 py-1.5 text-xs font-semibold text-gray-950 transition hover:bg-emerald-400"
                          @click="downloadExport(item)"
                        >
                          Download
                        </button>
                        <button
                          v-else-if="item.status === 'queued' || item.status === 'processing'"
                          type="button"
                          class="rounded-lg border border-amber-400/30 bg-amber-500/10 px-3 py-1.5 text-xs font-semibold text-amber-200"
                          @click="refreshExport(item.id)"
                        >
                          Check status
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </section>
          </div>
        </div>
      </AppShell>
    </div>
  </AppLayout>
</template>
