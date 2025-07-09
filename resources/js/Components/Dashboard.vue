<template>
  <div class="p-6 bg-gray-900 text-white min-h-screen space-y-6">

    <!-- WATCHLIST CARD -->
    <div class="bg-gray-800 rounded-2xl shadow-lg p-6">
      <h2 class="text-2xl font-bold mb-4">Watchlist</h2>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
        <div>
          <label class="block text-sm font-semibold mb-1">Symbol</label>
          <input v-model="newSymbol" type="text"
            class="w-full px-3 py-2 bg-gray-700 rounded focus:outline-none"
            placeholder="SPY"/>
        </div>
        <div>
          <label class="block text-sm font-semibold mb-1">Timeframe</label>
          <select v-model="newTimeframe"
            class="w-full px-3 py-2 bg-gray-700 rounded focus:outline-none">
            <option value="0d">0DTE</option>
            <option value="1d">1DTE</option>
            <option value="7d">7D</option>
            <option value="14d">14D</option>
            <option value="monthly">Monthly</option>
          </select>
        </div>
        <button @click="addWatchlist"
          class="px-4 py-2 bg-green-600 rounded hover:bg-green-500">
          + Add
        </button>
        <button @click="fetchWatchlistData"
          class="px-4 py-2 bg-blue-600 rounded hover:bg-blue-500">
          Fetch All
        </button>
      </div>
      <ul class="mt-4 space-y-2">
        <li v-for="item in watchlistItems" :key="item.id"
            class="flex justify-between bg-gray-700 px-4 py-2 rounded">
          <span>{{ item.symbol }} — {{ item.timeframe }}</span>
          <button @click="removeWatchlist(item.id)"
            class="px-2 py-1 bg-red-600 rounded hover:bg-red-500">×</button>
        </li>
      </ul>
    </div>

    <!-- GEX DASHBOARD CARD -->
    <div class="bg-gray-800 rounded-2xl shadow-lg p-6 space-y-6">
      <h2 class="text-2xl font-bold">GEX Levels & Charts</h2>

      <!-- Controls -->
      <div class="flex flex-col sm:flex-row sm:space-x-4 space-y-4 sm:space-y-0">
        <div class="flex-1">
          <label class="block text-sm font-semibold mb-1">Symbol</label>
          <input v-model="userSymbol" type="text"
            class="w-full px-3 py-2 bg-gray-700 rounded focus:outline-none"/>
        </div>
        <div class="flex-1">
          <label class="block text-sm font-semibold mb-1">Timeframe</label>
          <select v-model="timeframe"
            class="w-full px-3 py-2 bg-gray-700 rounded focus:outline-none">
            <option value="0d">0DTE</option>
            <option value="1d">1DTE</option>
            <option value="7d">7D</option>
            <option value="14d">14D</option>
            <option value="monthly">Monthly</option>
          </select>
        </div>
        <button @click="fetchGexLevels(userSymbol, timeframe)"
          class="self-end px-6 py-2 bg-blue-600 rounded hover:bg-blue-500">
          Load
        </button>
      </div>

      <div v-if="loading" class="text-center text-gray-400">Loading…</div>
      <div v-if="error"   class="text-center text-red-500">{{ error }}</div>

      <div v-if="levels && !loading && !error" class="space-y-6">

        <!-- 1) Key Metrics Row -->
        <div class="grid grid-cols-1 sm:grid-cols-7 gap-4">
          <MetricCard title="HVL" :value="levels.hvl" />
          <MetricCard title="Call OI %" :value="levels.call_interest_percentage + '%'" />
          <MetricCard title="Put OI %"  :value="levels.put_interest_percentage  + '%'" />
          <MetricCard title="Total OI"
            :value="levels.call_open_interest_total + levels.put_open_interest_total" />
          <MetricCard title="Total Vol"
            :value="levels.call_volume_total + levels.put_volume_total" />
          <MetricCard title="Total ΔOI"   :value="levels.total_oi_delta" />
          <MetricCard title="Total ΔVol"  :value="levels.total_volume_delta" />

          <!-- PCR occupies full width on small screens -->
          <div class="sm:col-span-7 bg-gray-700 rounded p-4 text-center">
            <h3 class="font-semibold">PCR (Vol)</h3>
            <p class="text-xl">{{ levels.pcr_volume }}</p>
          </div>
        </div>

        <!-- 2) Distribution Pie Charts -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div class="bg-gray-700 rounded p-4">
            <h4 class="font-semibold mb-2">OI Distribution</h4>
            <p class="text-sm text-gray-400 mb-4">
              Shows the split between call open interest and put open interest.
            </p>
            <OiDistributionChart
              :call-oi="levels.call_open_interest_total"
              :put-oi="levels.put_open_interest_total"
            />
          </div>
          <div class="bg-gray-700 rounded p-4">
            <h4 class="font-semibold mb-2">Volume Distribution</h4>
            <p class="text-sm text-gray-400 mb-4">
              Shows the split between call volume and put volume.
            </p>
            <VolDistributionChart
              :call-vol="levels.call_volume_total"
              :put-vol="levels.put_volume_total"
            />
          </div>
        </div>

        <!-- 3) Strike‐Based Charts -->
        <div class="grid grid-cols-1 md:grid-cols-1 gap-6">
          <div class="bg-gray-700 rounded p-4">
            <h4 class="font-semibold mb-2">ΔOI by Strike</h4>
            <p class="text-sm text-gray-400 mb-4">
              Bar chart of how daily open interest changed at each strike.
            </p>
            <StrikeDeltaChart :strikeData="levels.strike_data" />
          </div>
          <div class="bg-gray-700 rounded p-4">
            <h4 class="font-semibold mb-2">ΔVol by Strike</h4>
            <p class="text-sm text-gray-400 mb-4">
              Bar chart of how daily volume changed at each strike.
            </p>
            <VolumeDeltaChart :strikeData="levels.strike_data" />
          </div>
          <div class="bg-gray-700 rounded p-4">
            <h4 class="font-semibold mb-2">Net GEX by Strike</h4>
            <p class="text-sm text-gray-400 mb-4">
              Bar chart of net gamma exposure per strike (calls − puts).
            </p>
            <NetGexChart :strikeData="levels.strike_data" />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import MetricCard from './MetricCard.vue'
import StrikeDeltaChart    from './StrikeDeltaChart.vue'
import VolumeDeltaChart    from './VolumeDeltaChart.vue'
import NetGexChart         from './NetGexChart.vue'
import OiDistributionChart from './OiDistributionChart.vue'
import VolDistributionChart from './VolDistributionChart.vue'

const userSymbol      = ref('SPY')
const timeframe       = ref('14d')
const levels          = ref(null)
const loading         = ref(false)
const error           = ref(null)

const newSymbol       = ref('')
const newTimeframe    = ref('14d')
const watchlistItems  = ref([])

onMounted(async () => {
  await fetch('/sanctum/csrf-cookie', { credentials: 'include' })
  const res = await fetch('/api/watchlist', { credentials: 'include' })
  watchlistItems.value = await res.json()
})

async function loadWatchlist() {
  const res = await fetch('/api/watchlist',{ credentials:'include' })
  watchlistItems.value = await res.json()
}

async function addWatchlist() {
  const res = await fetch('/api/watchlist', {
    method: 'POST',
    headers:{'Content-Type':'application/json'},
    credentials:'include',
    body: JSON.stringify({symbol:newSymbol.value, timeframe:newTimeframe.value})
  })
  if (res.ok) {
    watchlistItems.value.push(await res.json())
    newSymbol.value = ''
  }
}

async function removeWatchlist(id) {
  const res = await fetch(`/api/watchlist/${id}`,{
    method:'DELETE', credentials:'include'
  })
  if (res.ok) {
    watchlistItems.value = watchlistItems.value.filter(i=>i.id!==id)
  }
}

async function fetchWatchlistData() {
  fetchingData.value = true
  const res = await fetch('/api/watchlist/fetch',{ method:'POST', credentials:'include' })
  fetchSuccess.value = (await res.json()).message || 'Started'
  fetchingData.value = false
}

async function fetchGexLevels(sym, tf) {
  loading.value = true
  error.value   = null
  levels.value  = null

  try {
    const res = await fetch(`/api/gex-levels?symbol=${sym}&timeframe=${tf}`)
    if (!res.ok) throw new Error(`HTTP ${res.status}`)
    levels.value = await res.json()
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}
</script>
