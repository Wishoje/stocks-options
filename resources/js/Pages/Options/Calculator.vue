<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import Chart from 'chart.js/auto'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppShell from '@/Components/AppShell.vue'
import axios from 'axios'

const symbol = ref('SPY')
const stockPrice = ref(0)
const optionType = ref('call')
const selectedOption = ref(null)
const contracts = ref(1)
const loading = ref(true)
const error = ref('')
const expirations = ref([])
const selectedExpiry = ref(null)
const chainData = ref([])

let chart = null
const chartRef = ref(null)

// 100% SAFE: Always returns a number
const safeNumber = (val) => {
  if (val === null || val === undefined) return 0
  const num = typeof val === 'string' ? parseFloat(val) : val
  return isNaN(num) ? 0 : num
}

const safePremium = (opt) => {
  if (!opt) return 0
  return safeNumber(opt.mid)
}

// SAFE COMPUTED
const breakeven = computed(() => {
  if (!selectedOption.value) return '0.00'
  const premium = safePremium(selectedOption.value)
  const be = optionType.value === 'call'
    ? selectedOption.value.strike + premium
    : selectedOption.value.strike - premium
  return Number(be).toFixed(2)  // ← FORCE NUMBER → STRING
})

const maxLoss = computed(() => {
  const premium = safePremium(selectedOption.value)
  const loss = premium * 100 * contracts.value
  return `-${loss.toFixed(0)}`
})

const cost = computed(() => {
  const premium = safePremium(selectedOption.value)
  const c = premium * 100 * contracts.value
  return c.toFixed(0)
})

const profitData = computed(() => {
  if (!selectedOption.value) return []
  const premium = safePremium(selectedOption.value)
  return priceRange.value.map(price => {
    const intrinsic = optionType.value === 'call'
      ? Math.max(price - selectedOption.value.strike, 0)
      : Math.max(selectedOption.value.strike - price, 0)
    return Number((intrinsic - premium) * 100 * contracts.value)
  })
})

const priceRange = computed(() => {
  const center = stockPrice.value || 100
  const width = center * 0.4
  const prices = []
  for (let i = 0; i <= 50; i++) {
    prices.push(center - width + i * (width * 2) / 50)
  }
  return prices
})

const moveNeeded = computed(() => {
  if (!stockPrice.value || stockPrice.value === 0) return 'N/A'
  const be = Number(breakeven.value)
  const pct = ((be / stockPrice.value) - 1) * 100
  return (pct > 0 ? '+' : '') + Number(pct).toFixed(1) + '%'
})

const strikesAroundPrice = computed(() => {
  const range = 0.2
  const min = stockPrice.value * (1 - range)
  const max = stockPrice.value * (1 + range)
  return chainData.value.filter(o => o.strike >= min && o.strike <= max)
})

const groupedStrikes = computed(() => {
  const map = {}
  strikesAroundPrice.value.forEach(o => {
    if (!map[o.strike]) map[o.strike] = { strike: o.strike, call: null, put: null }
    map[o.strike][o.type] = o
  })
  return Object.values(map).sort((a, b) => a.strike - b.strike)
})

const loadChain = async () => {
  loading.value = true
  try {
    const { data } = await axios.get('/api/option-chain', {
      params: { symbol: symbol.value, expiry: selectedExpiry.value }
    })

    stockPrice.value = safeNumber(data.underlying.price)
    chainData.value = data.chain || []
    expirations.value = data.expirations || []

    if (!selectedExpiry.value && expirations.value.length) {
      selectedExpiry.value = expirations.value[0]
    }

    const atm = Math.round(stockPrice.value / 5) * 5
    const opt = chainData.value.find(o => o.strike === atm && o.type === optionType.value)
                 || chainData.value[0]

    if (opt) selectOption(opt)
  } catch (e) {
    error.value = 'Failed to load chain'
  } finally {
    loading.value = false
  }
}

const selectOption = (opt) => {
  if (!opt) return
  selectedOption.value = {
    ...opt,
    premium: safePremium(opt)
  }
  optionType.value = opt.type
  renderChart()
}

const renderChart = () => {
  if (!chartRef.value || !selectedOption.value) return
  const ctx = chartRef.value.getContext('2d')
  if (chart) chart.destroy()

  chart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: priceRange.value.map(p => p.toFixed(1)),
      datasets: [{
        label: 'P&L',
        data: profitData.value,
        borderColor: optionType.value === 'call' ? '#10b981' : '#ef4444',
        backgroundColor: optionType.value === 'call' ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)',
        fill: true,
        tension: 0.4,
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        x: { title: { display: true, text: 'Stock Price' } },
        y: { title: { display: true, text: 'P&L ($)' } }
      }
    }
  })
}

watch(selectedExpiry, loadChain)
watch(() => symbol.value, () => {
  selectedExpiry.value = null
  loadChain()
})

// AUTO-LOAD LAST WATCHLIST SYMBOL
onMounted(async () => {
  window.addEventListener('select-symbol', e => {
    symbol.value = e.detail.symbol || 'SPY'
  })

  try {
    const { data } = await axios.get('/api/watchlist')
    const last = data?.[0]?.symbol || 'SPY'
    symbol.value = last
    await axios.post('/api/prime-calculator', { symbol: last })
  } catch (e) {
    console.warn('Watchlist load failed, using SPY')
  }

  await loadChain()
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
          <div v-if="loading" class="text-center py-20">
            <div class="inline-block animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-cyan-500"></div>
            <p class="mt-4 text-gray-400">Fetching {{ symbol }} live chain...</p>
          </div>

          <div v-else-if="error" class="text-center py-20 text-red-400">
            {{ error }}
          </div>

          <div v-else class="space-y-6">
            <!-- Expiry Chips -->
            <div class="flex flex-wrap gap-2">
              <button
                v-for="exp in expirations"
                @click="selectedExpiry = exp"
                :class="selectedExpiry === exp ? 'bg-cyan-600' : 'bg-gray-700'"
                class="px-3 py-1.5 rounded-lg text-xs font-medium"
              >
                {{ exp }}
              </button>
            </div>

            <div class="flex justify-center mt-4">
                <button @click="loadChain" class="px-6 py-2 bg-cyan-600 rounded-lg hover:bg-cyan-700 transition">
                    Refresh Live Data
                </button>
            </div>

            <!-- Chain Table -->
            <div class="bg-white/10 backdrop-blur-xl rounded-2xl border border-gray-700/50 p-6 overflow-x-auto">
              <h3 class="text-xl font-bold mb-4">Live Chain ±20%</h3>
              <table class="w-full text-sm">
                <thead>
                  <tr class="text-gray-400 border-b border-gray-700">
                    <th class="text-left py-2">Strike</th>
                    <th class="text-left py-2">Call</th>
                    <th class="text-left py-2">Put</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in groupedStrikes" :key="row.strike"
                      class="hover:bg-gray-800/50 cursor-pointer border-b border-gray-800"
                      :class="{ 'bg-gray-800/30': selectedOption?.strike === row.strike }">
                    <td class="py-3 font-mono">{{ row.strike }}</td>
                    <td @click="selectOption(row.call)" class="py-3"
                        :class="optionType === 'call' && selectedOption?.strike === row.strike ? 'text-emerald-400 font-bold' : 'text-gray-300'">
                      ${{ row.call?.mid.toFixed(2) || '—' }}
                    </td>
                    <td @click="selectOption(row.put)" class="py-3"
                        :class="optionType === 'put' && selectedOption?.strike === row.strike ? 'text-red-400 font-bold' : 'text-gray-300'">
                      ${{ row.put?.mid.toFixed(2) || '—' }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Controls + Summary -->
            <div class="grid lg:grid-cols-3 gap-6">
              <div class="space-y-6">
                <div class="bg-white/10 backdrop-blur-xl rounded-2xl border border-gray-700/50 p-6">
                  <h3 class="text-xl font-bold mb-4">{{ symbol }} @ ${{ stockPrice.toFixed(2) }}</h3>
                  <div class="space-y-4">
                    <div class="flex gap-3">
                      <button @click="optionType = 'call'"
                        :class="optionType === 'call' ? 'bg-emerald-600' : 'bg-gray-700'"
                        class="flex-1 py-3 rounded-lg font-medium">Long Call</button>
                      <button @click="optionType = 'put'"
                        :class="optionType === 'put' ? 'bg-red-600' : 'bg-gray-700'"
                        class="flex-1 py-3 rounded-lg font-medium">Long Put</button>
                    </div>

                    <div v-if="selectedOption" class="bg-gray-800/50 rounded-lg p-4 space-y-2">
                      <div class="text-sm text-gray-400">Selected</div>
                      <div class="font-mono text-lg text-cyan-300">
                        {{ selectedOption.expiry }} {{ selectedOption.strike }} {{ optionType.toUpperCase() }}
                      </div>
                      <div class="text-sm">
                        <span class="text-gray-400">Mid:</span>
                        <span class="font-bold text-emerald-400 ml-2">${{ selectedOption.premium.toFixed(2) }}</span>
                      </div>
                    </div>

                    <div>
                      <label class="text-sm text-gray-300">Contracts</label>
                      <input v-model.number="contracts" type="number" min="1"
                        class="w-full mt-2 px-4 py-3 bg-gray-800/70 border border-gray-600 rounded-lg text-white">
                    </div>
                  </div>
                </div>

                <div class="bg-gradient-to-br from-cyan-600/20 to-blue-700/20 backdrop-blur-xl rounded-2xl border border-cyan-500/30 p-6">
                  <h4 class="text-lg font-bold text-cyan-300 mb-4">Trade Summary</h4>
                  <div class="space-y-3 text-sm">
                    <div class="flex justify-between">
                      <span class="text-gray-400">Breakeven</span>
                      <span class="font-bold text-white">${{ breakeven }}</span>
                    </div>
                    <div class="flex justify-between">
                      <span class="text-gray-400">Max Loss</span>
                      <span class="font-bold text-red-400">${{ maxLoss.toLocaleString() }}</span>
                    </div>
                    <div class="flex justify-between">
                      <span class="text-gray-400">Cost</span>
                      <span class="font-bold text-white">${{ cost.toLocaleString() }}</span>
                    </div>
                    <div class="flex justify-between">
                      <span class="text-gray-400">Move Needed</span>
                      <span class="font-bold text-cyan-400">{{ moveNeeded }}</span>
                    </div>
                  </div>
                </div>
              </div>

              <div class="lg:col-span-2">
                <div class="bg-white/10 backdrop-blur-xl rounded-2xl border border-gray-700/50 p-6">
                  <h3 class="text-xl font-bold mb-4">Live P&L</h3>
                  <canvas ref="chartRef" class="w-full h-96"></canvas>
                </div>

                <div class="mt-6 grid grid-cols-3 gap-4 text-center">
                  <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
                    <div class="text-2xl font-bold text-red-400">${{ Math.abs(maxLoss).toLocaleString() }}</div>
                    <div class="text-xs text-gray-400">Max Risk</div>
                  </div>
                  <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
                    <div class="text-2xl font-bold text-yellow-400">1 : Infinity</div>
                    <div class="text-xs text-gray-400">R:R</div>
                  </div>
                  <div class="bg-gray-800/50 backdrop-blur rounded-xl p-4 border border-gray-700">
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