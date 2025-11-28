<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import Chart from 'chart.js/auto'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppShell from '@/Components/AppShell.vue'
import axios from 'axios'

const symbol         = ref('SPY')
const stockPrice     = ref(0)
const optionType     = ref('call') // 'call' | 'put'
const selectedOption = ref(null)
const contracts      = ref(1)
const loading        = ref(true)
const error          = ref('')
const expirations    = ref([])
const selectedExpiry = ref(null)
const chainData      = ref([])
const entryPrice     = ref(null) // per-share price YOU paid (or want)

// scenario + view modes
const decayMode      = ref('flat')    // 'flat' | 'breakeven' | 'target'
const targetPrice    = ref(null)
const decayViewMode  = ref('compact') // 'compact' | 'full'

// strike band for chain list
const strikeBandMode = ref('near') // 'near' | 'wide' | 'all'

let chart = null
let decayChart = null
const chartRef = ref(null)
const decayChartRef = ref(null)

// ---------- helpers ----------
const safeNumber = (val) => {
  if (val === null || val === undefined) return 0
  const num = typeof val === 'string' ? parseFloat(val) : Number(val)
  return isNaN(num) ? 0 : num
}

const safePremium = (opt) => {
  if (!opt) return 0
  return safeNumber(opt.mid)
}

// Effective premium PER SHARE used as *entry price*
const effectivePremium = computed(() => {
  const entered = entryPrice.value
  if (entered !== null && entered !== '' && entered !== undefined) {
    const n = safeNumber(entered)
    if (n > 0) return n
  }
  // fallback: you “entered” at current mid
  return safePremium(selectedOption.value)
})

// ---------- main metrics ----------
const totalCost = computed(() => {
  const premium = effectivePremium.value
  const c = Math.max(1, safeNumber(contracts.value || 1))
  return premium * 100 * c
})

const breakeven = computed(() => {
  if (!selectedOption.value) return '0.00'
  const premium = effectivePremium.value
  const be =
    optionType.value === 'call'
      ? selectedOption.value.strike + premium
      : selectedOption.value.strike - premium
  return Number(be).toFixed(2)
})

const maxLoss = computed(() => -totalCost.value)

const cost = computed(() => totalCost.value)

const formatPrice = (val) => {
  const n = safeNumber(val)
  if (!Number.isFinite(n)) return '—'
  return n.toFixed(2)
}

const priceRange = computed(() => {
  const center = stockPrice.value || 100
  const width = center * 0.4
  const prices = []
  for (let i = 0; i <= 50; i++) {
    prices.push(center - width + (i * (width * 2)) / 50)
  }
  return prices
})

const profitData = computed(() => {
  if (!selectedOption.value) return []
  const premium = effectivePremium.value
  const c = Math.max(1, safeNumber(contracts.value || 1))

  return priceRange.value.map((price) => {
    const intrinsic =
      optionType.value === 'call'
        ? Math.max(price - selectedOption.value.strike, 0)
        : Math.max(selectedOption.value.strike - price, 0)

    return Number((intrinsic - premium) * 100 * c)
  })
})

const moveNeeded = computed(() => {
  if (!stockPrice.value || stockPrice.value === 0) return 'N/A'
  const be = Number(breakeven.value)
  const pct = ((be / stockPrice.value) - 1) * 100
  return (pct > 0 ? '+' : '') + Number(pct).toFixed(1) + '%'
})

// payoff table at expiration
const payoffTableRows = computed(() =>
  priceRange.value.map((p, idx) => {
    const pnl = profitData.value[idx] ?? 0
    const roi = totalCost.value > 0 ? (pnl / totalCost.value) * 100 : 0
    return {
      price: Number(p.toFixed(2)),
      pnl,
      roi,
    }
  })
)

// ---------- DTE + scenario ----------
const daysToExpiration = computed(() => {
  if (!selectedOption.value?.expiry) return 0

  const exp = new Date(selectedOption.value.expiry) // YYYY-MM-DD
  const today = new Date()

  const msPerDay = 1000 * 60 * 60 * 24
  const diff = Math.ceil((exp - today) / msPerDay)

  return Math.max(diff, 0)
})

const decayUnderlying = computed(() => {
  if (!selectedOption.value) return null

  if (decayMode.value === 'flat') {
    return safeNumber(stockPrice.value || 0)
  }

  if (decayMode.value === 'breakeven') {
    const be = safeNumber(breakeven.value)
    return be > 0 ? be : safeNumber(stockPrice.value || 0)
  }

  // 'target'
  const t = safeNumber(targetPrice.value)
  if (t > 0) return t

  // fallback to spot if target is not set
  return safeNumber(stockPrice.value || 0)
})

const timeDecayTitle = computed(() => {
  const S = decayUnderlying.value
  if (!selectedOption.value || !S) return 'Time Decay'

  if (decayMode.value === 'flat') {
    return `Flat @ Spot ($${S.toFixed(2)})`
  }
  if (decayMode.value === 'breakeven') {
    return `Flat @ Breakeven ($${S.toFixed(2)})`
  }
  return `Flat @ Target ($${S.toFixed(2)})`
})

/**
 * Time-decay table:
 * Approximate theoretical value if:
 *  - price = decayUnderlying (spot / breakeven / target)
 *  - time value decays from today's level down to 0 at expiration
 *
 * Uses:
 *   - current mid for today's option value
 *   - entryPrice / effectivePremium for P&L
 */
const timeDecayRows = computed(() => {
  if (!selectedOption.value) return []

  const dte = daysToExpiration.value
  if (dte <= 0) return []

  const Sspot = safeNumber(stockPrice.value || 0)
  const S = decayUnderlying.value
  if (!S || S <= 0 || !Sspot) return []

  const K = safeNumber(selectedOption.value.strike)
  const entry = effectivePremium.value
  const c = Math.max(1, safeNumber(contracts.value || 1))

  // Intrinsic at scenario price (where you imagine price sits)
  const intrinsicScenario =
    optionType.value === 'call'
      ? Math.max(S - K, 0)
      : Math.max(K - S, 0)

  // Intrinsic at current spot (where current mid lives)
  const intrinsicSpot =
    optionType.value === 'call'
      ? Math.max(Sspot - K, 0)
      : Math.max(K - Sspot, 0)

  const currentMid = safePremium(selectedOption.value)

  // Time value "today" (can be negative if market mid < intrinsic)
  const timeValueNow = currentMid - intrinsicSpot

  const rows = []
  for (let d = 0; d <= dte; d++) {
    const frac = dte === 0 ? 0 : d / dte

    // Linear decay of time value from now → 0
    const theoPerShare = intrinsicScenario + timeValueNow * frac

    const pnl = (theoPerShare - entry) * 100 * c
    const roi = totalCost.value > 0 ? (pnl / totalCost.value) * 100 : 0

    rows.push({
      dte: d,
      price: theoPerShare,
      pnl,
      roi,
    })
  }

  return rows
})

// compact vs full view for table
const visibleTimeDecayRows = computed(() => {
  const rows = timeDecayRows.value
  if (decayViewMode.value === 'full' || rows.length <= 16) {
    return rows
  }

  // keep: today (0), a small middle window, and expiry
  const first = rows[0]
  const last = rows[rows.length - 1]

  const windowSize = 6
  const midIndex = Math.floor(rows.length / 2)
  const start = Math.max(1, midIndex - Math.floor(windowSize / 2))
  const end = Math.min(rows.length - 2, start + windowSize - 1)

  const middle = rows.slice(start, end + 1)

  return [first, ...middle, last]
})

const hiddenTimeDecayCount = computed(() => {
  const total = timeDecayRows.value.length
  const visible = visibleTimeDecayRows.value.length
  return Math.max(total - visible, 0)
})

// ---------- "is everything ready?" ----------
const hasData = computed(() => {
  return (
    !!selectedOption.value &&
    chainData.value.length > 0 &&
    expirations.value.length > 0 &&
    safeNumber(stockPrice.value) > 0
  )
})

// ---------- chain display ----------
const strikesAroundPrice = computed(() => {
  const center = stockPrice.value || 0
  if (!center || chainData.value.length === 0) return chainData.value

  if (strikeBandMode.value === 'all') {
    return chainData.value
  }

  const pct = strikeBandMode.value === 'near' ? 0.15 : 0.4
  const lo = center * (1 - pct)
  const hi = center * (1 + pct)

  return chainData.value.filter((o) => o.strike >= lo && o.strike <= hi)
})

const groupedStrikes = computed(() => {
  const map = {}
  strikesAroundPrice.value.forEach((o) => {
    if (!map[o.strike]) map[o.strike] = { strike: o.strike, call: null, put: null }
    map[o.strike][o.type] = o
  })
  return Object.values(map).sort((a, b) => a.strike - b.strike)
})

const handleExpiryClick = async (value) => {
  if (selectedExpiry.value === value) return // no-op if same
  selectedExpiry.value = value
  await loadChain()
}

// ---------- API ----------
const loadChain = async () => {
  loading.value = true
  try {
    const { data } = await axios.get('/api/option-chain', {
      params: { symbol: symbol.value, expiry: selectedExpiry.value },
    })

    stockPrice.value   = safeNumber(data.underlying.price)
    chainData.value    = data.chain || []
    expirations.value  = data.expirations || []
    error.value        = ''

    // If nothing selected yet, pick first expiry
    if (!selectedExpiry.value && expirations.value.length) {
      selectedExpiry.value = expirations.value[0].value
    }

    // Pick ATM call/put as default
    const atm = Math.round(stockPrice.value / 5) * 5
    const opt =
      chainData.value.find(
        (o) => Number(o.strike) === atm && o.type === optionType.value
      ) || chainData.value[0]

    if (opt) selectOption(opt)
  } catch (e) {
    console.error(e)
    error.value = 'Failed to load chain'
  } finally {
    loading.value = false
  }
}

const selectOption = (opt) => {
  if (!opt) return
  selectedOption.value = {
    ...opt,
    premium: safePremium(opt),
  }
  optionType.value = opt.type
  renderChart()
  renderDecayChart()
}

// ---------- charts ----------
const renderChart = () => {
  if (!chartRef.value || !selectedOption.value) return
  const ctx = chartRef.value.getContext('2d')
  if (chart) chart.destroy()

  chart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: priceRange.value.map((p) => p.toFixed(1)),
      datasets: [
        {
          label: 'P&L vs Price (Expiration)',
          data: profitData.value,
          borderColor: optionType.value === 'call' ? '#10b981' : '#ef4444',
          backgroundColor:
            optionType.value === 'call'
              ? 'rgba(16, 185, 129, 0.15)'
              : 'rgba(239, 68, 68, 0.15)',
          fill: true,
          tension: 0.4,
        },
      ],
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        x: { title: { display: true, text: 'Stock Price at Expiration' } },
        y: { title: { display: true, text: 'P&L ($)' } },
      },
    },
  })
}

const renderDecayChart = () => {
  const rows = timeDecayRows.value
  if (!decayChartRef.value || rows.length === 0) {
    if (decayChart) {
      decayChart.destroy()
      decayChart = null
    }
    return
  }

  const ctx = decayChartRef.value.getContext('2d')
  if (decayChart) decayChart.destroy()

  decayChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: rows.map((r) => r.dte),
      datasets: [
        {
          label: 'P&L vs Time',
          data: rows.map((r) => r.pnl),
          borderColor: optionType.value === 'call' ? '#22c55e' : '#f97316',
          backgroundColor:
            optionType.value === 'call'
              ? 'rgba(34, 197, 94, 0.15)'
              : 'rgba(249, 115, 22, 0.15)',
          fill: true,
          tension: 0.3,
        },
      ],
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        x: { title: { display: true, text: 'Days to Expiration' } },
        y: { title: { display: true, text: 'P&L ($)' } },
      },
    },
  })
}

// ---------- watchers ----------
watch(
  [
    selectedOption,
    optionType,
    contracts,
    () => stockPrice.value,
    entryPrice,
    decayMode,
    targetPrice,
  ],
  () => {
    if (!selectedOption.value) return
    renderChart()
    renderDecayChart()
  }
)

// NO watcher on selectedExpiry – we control it via handleExpiryClick + loadChain

// ---------- symbol selection handler ----------
const handleSelectSymbol = async (e) => {
  const sym = e.detail.symbol || 'SPY'

  // optional optimization: if same symbol and we already have data, no-op
  if (sym === symbol.value && chainData.value.length) return

  symbol.value         = sym
  selectedExpiry.value = null
  selectedOption.value = null
  entryPrice.value     = null
  targetPrice.value    = null
  error.value          = ''
  loading.value        = true

  await loadChain()
}

// ---------- mounted / unmounted ----------
onMounted(async () => {
  window.addEventListener('select-symbol', handleSelectSymbol)

  try {
    const { data } = await axios.get('/api/watchlist')
    const last = data?.[0]?.symbol || 'SPY'
    symbol.value = last

    const primeCalculator = await axios.post('/api/prime-calculator', {
      symbol: last,
    })
    console.log('Primed calculator for', last, primeCalculator.data)
  } catch (e) {
    console.warn('Watchlist load failed, using SPY', e)
  }

  await loadChain()
})

onBeforeUnmount(() => {
  window.removeEventListener('select-symbol', handleSelectSymbol)
})
</script>

<template>
  <AppLayout title="Live Options Calculator">
    <template #header>
      <h2 class="text-2xl font-bold">Live Options Calculator (15-min delay)</h2>
    </template>

    <div class="py-6">
      <AppShell>
        <div class="max-w-7xl mx-auto px-6 space-y-6">
          <!-- Error first -->
          <div v-if="error" class="text-center py-20 text-red-400">
            {{ error }}
          </div>

          <!-- Global "booting" / loading / waiting for full data -->
          <div
            v-else-if="loading || !hasData"
            class="text-center py-20"
          >
            <div
              class="inline-block animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-cyan-500"
            ></div>
            <p class="mt-4 text-gray-400">
              Preparing {{ symbol }} calculator…
            </p>
          </div>

          <!-- Main UI only when fully ready -->
          <div v-else class="space-y-6">
            <!-- Expiry chips -->
            <div class="flex flex-wrap gap-2 items-center">
              <span class="text-xs text-gray-400 mr-1">Expiry:</span>
              <button
                v-for="exp in expirations"
                :key="exp.value"
                @click="handleExpiryClick(exp.value)"
                :class="selectedExpiry === exp.value ? 'bg-cyan-600' : 'bg-gray-700'"
                class="px-3 py-1.5 rounded-lg text-xs font-medium"
              >
                {{ exp.label }}
              </button>
            </div>

            <div class="flex justify-center mt-4">
              <button
                @click="loadChain"
                class="px-6 py-2 bg-cyan-600 rounded-lg hover:bg-cyan-700 transition"
              >
                Refresh Live Data
              </button>
            </div>

            <!-- Chain Table -->
            <div
              class="bg-white/10 backdrop-blur-xl rounded-2xl border border-gray-700/50 p-6 overflow-x-auto"
            >
              <div class="flex items-center justify-between mb-4">
                <h3 class="text-xl font-bold">Live Chain</h3>
                <div class="flex items-center gap-2">
                  <span class="text-xs text-gray-400">Strikes:</span>
                  <button
                    class="px-2.5 py-1 rounded-full text-[11px] font-medium"
                    :class="strikeBandMode === 'near' ? 'bg-cyan-600 text-white' : 'bg-gray-700 text-gray-200'"
                    @click="strikeBandMode = 'near'"
                  >
                    Near (±15%)
                  </button>
                  <button
                    class="px-2.5 py-1 rounded-full text-[11px] font-medium"
                    :class="strikeBandMode === 'wide' ? 'bg-cyan-600 text-white' : 'bg-gray-700 text-gray-200'"
                    @click="strikeBandMode = 'wide'"
                  >
                    Wide (±40%)
                  </button>
                  <button
                    class="px-2.5 py-1 rounded-full text-[11px] font-medium"
                    :class="strikeBandMode === 'all' ? 'bg-cyan-600 text-white' : 'bg-gray-700 text-gray-200'"
                    @click="strikeBandMode = 'all'"
                  >
                    All
                  </button>
                </div>
              </div>

              <table class="w-full text-sm">
                <thead>
                  <tr class="text-gray-400 border-b border-gray-700">
                    <th class="text-left py-2">Strike</th>
                    <th class="text-left py-2">Call</th>
                    <th class="text-left py-2">Put</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="row in groupedStrikes"
                    :key="row.strike"
                    class="hover:bg-gray-800/50 cursor-pointer border-b border-gray-800"
                    :class="{ 'bg-gray-800/30': selectedOption?.strike === row.strike }"
                  >
                    <td class="py-3 font-mono">{{ row.strike }}</td>

                    <td
                      @click="selectOption(row.call)"
                      class="py-3"
                      :class="
                        optionType === 'call' && selectedOption?.strike === row.strike
                          ? 'text-emerald-400 font-bold'
                          : 'text-gray-300'
                      "
                    >
                      <span v-if="row.call">
                        ${{ formatPrice(row.call.mid) }}
                      </span>
                      <span v-else>—</span>
                    </td>

                    <td
                      @click="selectOption(row.put)"
                      class="py-3"
                      :class="
                        optionType === 'put' && selectedOption?.strike === row.strike
                          ? 'text-red-400 font-bold'
                          : 'text-gray-300'
                      "
                    >
                      <span v-if="row.put">
                        ${{ formatPrice(row.put.mid) }}
                      </span>
                      <span v-else>—</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Controls + Summary -->
            <div class="grid lg:grid-cols-3 gap-6">
              <div class="space-y-6">
                <div
                  class="bg-white/10 backdrop-blur-xl rounded-2xl border border-gray-700/50 p-6"
                >
                  <h3 class="text-xl font-bold mb-4">
                    {{ symbol }} @ ${{ stockPrice.toFixed(2) }}
                  </h3>
                  <div class="space-y-4">
                    <div class="flex gap-3">
                      <button
                        @click="optionType = 'call'"
                        :class="optionType === 'call' ? 'bg-emerald-600' : 'bg-gray-700'"
                        class="flex-1 py-3 rounded-lg font-medium"
                      >
                        Long Call
                      </button>
                      <button
                        @click="optionType = 'put'"
                        :class="optionType === 'put' ? 'bg-red-600' : 'bg-gray-700'"
                        class="flex-1 py-3 rounded-lg font-medium"
                      >
                        Long Put
                      </button>
                    </div>

                    <div
                      v-if="selectedOption"
                      class="bg-gray-800/50 rounded-lg p-4 space-y-2"
                    >
                      <div class="text-sm text-gray-400">Selected</div>
                      <div class="font-mono text-lg text-cyan-300">
                        {{ selectedOption.expiry }} {{ selectedOption.strike }}
                        {{ optionType.toUpperCase() }}
                      </div>
                      <div class="text-sm">
                        <span class="text-gray-400">Mid:</span>
                        <span class="font-bold text-emerald-400 ml-2">
                          ${{ selectedOption.premium.toFixed(2) }}
                        </span>
                      </div>
                    </div>

                    <div>
                      <label class="text-sm text-gray-300">Contracts</label>
                      <input
                        v-model.number="contracts"
                        type="number"
                        min="1"
                        class="w-full mt-2 px-4 py-3 bg-gray-800/70 border border-gray-600 rounded-lg text-white"
                      />
                    </div>

                    <div class="mt-4">
                      <label class="text-sm text-gray-300">
                        Entry price per share (optional)
                      </label>
                      <input
                        v-model.number="entryPrice"
                        type="number"
                        min="0"
                        step="0.01"
                        class="w-full mt-2 px-4 py-3 bg-gray-800/70 border border-gray-600 rounded-lg text-white"
                        placeholder="Leave blank to use live mid"
                      />
                    </div>
                  </div>
                </div>

                <div
                  class="bg-gradient-to-br from-cyan-600/20 to-blue-700/20 backdrop-blur-xl rounded-2xl border border-cyan-500/30 p-6"
                >
                  <h4 class="text-lg font-bold text-cyan-300 mb-4">Trade Summary</h4>
                  <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                      <span class="text-gray-400">Breakeven</span>
                      <span class="font-bold text-white">${{ breakeven }}</span>
                    </div>
                    <div class="flex justify-between">
                      <span class="text-gray-400">Max Loss</span>
                      <span class="font-bold text-red-400">
                        ${{ Math.abs(maxLoss).toLocaleString() }}
                      </span>
                    </div>
                    <div class="flex justify-between">
                      <span class="text-gray-400">Cost</span>
                      <span class="font-bold text-white">
                        ${{ cost.toLocaleString() }}
                      </span>
                    </div>
                    <div class="flex justify-between">
                      <span class="text-gray-400">Move Needed</span>
                      <span class="font-bold text-cyan-400">{{ moveNeeded }}</span>
                    </div>
                  </div>
                </div>
              </div>

              <div class="lg:col-span-2 space-y-6">
                <!-- charts -->
                <div class="bg-white/10 backdrop-blur-xl rounded-2xl border border-gray-700/50 p-6">
                  <div class="grid lg:grid-cols-2 gap-6">
                    <div>
                      <h3 class="text-xl font-bold mb-4">P&L vs Price (at Expiration)</h3>
                      <canvas ref="chartRef" class="w-full h-80"></canvas>
                    </div>

                    <div>
                      <h3 class="text-xl font-bold mb-1">P&L vs Time</h3>
                      <p class="text-xs text-gray-400 mb-3">
                        Scenario: {{ timeDecayTitle }} • DTE: {{ daysToExpiration }}
                      </p>
                      <canvas ref="decayChartRef" class="w-full h-80"></canvas>
                    </div>
                  </div>
                </div>

                <!-- Time Decay Table (before payoff) -->
                <div
                  class="bg-white/10 backdrop-blur-xl rounded-2xl border border-gray-700/50 p-6"
                >
                  <div class="flex items-center justify-between mb-3">
                    <div>
                      <h3 class="text-lg font-bold">
                        {{ timeDecayTitle }}
                      </h3>
                      <span class="text-xs text-gray-400">
                        DTE: {{ daysToExpiration }} day<span v-if="daysToExpiration !== 1">s</span>
                      </span>
                    </div>

                    <div class="flex items-center gap-2">
                      <span class="text-xs text-gray-400 mr-1">View:</span>
                      <button
                        class="px-3 py-1 rounded-full text-xs font-medium"
                        :class="decayViewMode === 'compact' ? 'bg-cyan-600 text-white' : 'bg-gray-700 text-gray-200'"
                        @click="decayViewMode = 'compact'"
                      >
                        Compact
                      </button>
                      <button
                        class="px-3 py-1 rounded-full text-xs font-medium"
                        :class="decayViewMode === 'full' ? 'bg-cyan-600 text-white' : 'bg-gray-700 text-gray-200'"
                        @click="decayViewMode = 'full'"
                      >
                        Full
                      </button>
                    </div>
                  </div>

                  <!-- Scenario controls -->
                  <div class="flex flex-wrap items-center gap-3 mb-4">
                    <span class="text-xs text-gray-400 mr-1">Scenario:</span>

                    <button
                      class="px-3 py-1 rounded-full text-xs font-medium"
                      :class="decayMode === 'flat' ? 'bg-cyan-600 text-white' : 'bg-gray-700 text-gray-200'"
                      @click="decayMode = 'flat'"
                    >
                      Flat @ Spot
                    </button>

                    <button
                      class="px-3 py-1 rounded-full text-xs font-medium"
                      :class="decayMode === 'breakeven' ? 'bg-cyan-600 text-white' : 'bg-gray-700 text-gray-200'"
                      @click="decayMode = 'breakeven'"
                    >
                      Flat @ Breakeven
                    </button>

                    <button
                      class="px-3 py-1 rounded-full text-xs font-medium"
                      :class="decayMode === 'target' ? 'bg-cyan-600 text-white' : 'bg-gray-700 text-gray-200'"
                      @click="decayMode = 'target'"
                    >
                      Flat @ Target
                    </button>

                    <input
                      v-if="decayMode === 'target'"
                      v-model.number="targetPrice"
                      type="number"
                      min="0"
                      step="0.1"
                      class="ml-2 px-3 py-1.5 bg-gray-800/70 border border-gray-600 rounded-lg text-xs text-white w-28"
                      placeholder="Target"
                    />
                  </div>

                  <p class="text-xs text-gray-400 mb-3">
                    Approximate option value and P&amp;L per day if the stock stays at the selected
                    scenario price and time value decays linearly into expiration.
                  </p>

                  <div class="max-h-80 overflow-y-auto">
                    <table class="w-full text-sm">
                      <thead>
                        <tr class="text-gray-400 border-b border-gray-700">
                          <th class="text-left py-2">Days to Exp</th>
                          <th class="text-left py-2">Option Price ($)</th>
                          <th class="text-left py-2">P&amp;L ($)</th>
                          <th class="text-left py-2">ROI (%)</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr
                          v-for="row in visibleTimeDecayRows"
                          :key="row.dte"
                          :class="[
                            'border-b border-gray-800',
                            row.pnl > 0 ? 'bg-emerald-900/20' : '',
                            row.pnl < 0 ? 'bg-red-900/10' : '',
                          ]"
                        >
                          <td class="py-2 font-mono">{{ row.dte }}</td>
                          <td class="py-2 font-mono">
                            ${{ row.price.toFixed(2) }}
                          </td>
                          <td
                            class="py-2 font-mono"
                            :class="row.pnl >= 0 ? 'text-emerald-400' : 'text-red-400'"
                          >
                            ${{ row.pnl.toFixed(0) }}
                          </td>
                          <td class="py-2 font-mono text-gray-300">
                            {{ row.roi.toFixed(1) }}%
                          </td>
                        </tr>

                        <tr
                          v-if="decayViewMode === 'compact' && hiddenTimeDecayCount > 0"
                        >
                          <td colspan="4" class="py-2 text-center text-xs text-gray-500">
                            … {{ hiddenTimeDecayCount }} more day<span v-if="hiddenTimeDecayCount !== 1">s</span> hidden.
                            Switch to <span class="font-semibold">Full</span> view to see all.
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>

                <!-- Payoff table (after time decay) -->
                <div
                  class="bg-white/10 backdrop-blur-xl rounded-2xl border border-gray-700/50 p-6"
                >
                  <h3 class="text-lg font-bold mb-4">Payoff Table (at Expiration)</h3>
                  <div class="max-h-80 overflow-y-auto">
                    <table class="w-full text-sm">
                      <thead>
                        <tr class="text-gray-400 border-b border-gray-700">
                          <th class="text-left py-2">Stock Price</th>
                          <th class="text-left py-2">P&amp;L ($)</th>
                          <th class="text-left py-2">ROI (%)</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr
                          v-for="row in payoffTableRows"
                          :key="row.price"
                          :class="[
                            'border-b border-gray-800',
                            row.pnl > 0 ? 'bg-emerald-900/20' : '',
                            row.pnl < 0 ? 'bg-red-900/10' : '',
                          ]"
                        >
                          <td class="py-2 font-mono">${{ row.price.toFixed(2) }}</td>
                          <td
                            class="py-2 font-mono"
                            :class="row.pnl >= 0 ? 'text-emerald-400' : 'text-red-400'"
                          >
                            ${{ row.pnl.toFixed(0) }}
                          </td>
                          <td class="py-2 font-mono text-gray-300">
                            {{ row.roi.toFixed(1) }}%
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>

                <!-- quick stats -->
                <div class="grid grid-cols-3 gap-4 text-center">
                  <div
                    class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700"
                  >
                    <div class="text-2xl font-bold text-red-400">
                      ${{ Math.abs(maxLoss).toLocaleString() }}
                    </div>
                    <div class="text-xs text-gray-400">Max Risk</div>
                  </div>
                  <div
                    class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700"
                  >
                    <div class="text-2xl font-bold text-yellow-400">1 : Infinity</div>
                    <div class="text-xs text-gray-400">R:R (long option)</div>
                  </div>
                  <div
                    class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700"
                  >
                    <div class="text-2xl font-bold text-cyan-400">{{ moveNeeded }}</div>
                    <div class="text-xs text-gray-400">Move Needed</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </AppShell>
    </div>
  </AppLayout>
</template>
