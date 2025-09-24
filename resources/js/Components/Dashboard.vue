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

        <!-- NEW: Expiration Dates List -->
        <div v-if="levels && levels.expiration_dates && levels.expiration_dates.length" class="text-sm text-gray-300">
            <span class="font-semibold">Expirations:</span>
            <ul class="inline-flex flex-wrap gap-2 ml-2">
            <li v-for="d in levels.expiration_dates" :key="d"
                class="px-2 py-1 bg-gray-700 rounded">
                {{ d }}
            </li>
            </ul>
        </div>

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
import axios from 'axios'

import MetricCard from './MetricCard.vue'
import StrikeDeltaChart    from './StrikeDeltaChart.vue'
import VolumeDeltaChart    from './VolumeDeltaChart.vue'
import NetGexChart         from './NetGexChart.vue'
import OiDistributionChart from './OiDistributionChart.vue'
import VolDistributionChart from './VolDistributionChart.vue'

// ---- axios defaults for Sanctum / Fortify
axios.defaults.withCredentials = true
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest'
// if you changed Sanctum defaults, also set:
// axios.defaults.xsrfCookieName = 'XSRF-TOKEN'
// axios.defaults.xsrfHeaderName = 'X-XSRF-TOKEN'

const userSymbol     = ref('SPY')
const timeframe      = ref('14d')
const levels         = ref(null)
const loading        = ref(false)
const error          = ref(null)

const newSymbol      = ref('')
const newTimeframe   = ref('14d')
const watchlistItems = ref([])

const fetchingData   = ref(false)
const fetchSuccess   = ref('')
const fetchError     = ref('')

onMounted(async () => {
  try {
    // 1) Get CSRF cookie (required for any state-changing request with Sanctum)
    await axios.get('/sanctum/csrf-cookie')

    try {
      const { data } = await axios.get('/api/me')
      console.log('API sees user:', data)
    } catch (e) {
      console.log('API auth failed', e?.response?.status, e?.response?.data)
    }


    // 2) Load watchlist (requires authenticated session)
    const { data } = await axios.get('/api/watchlist')
    watchlistItems.value = data
  } catch (e) {
    console.error(e)
  }
})

async function loadWatchlist() {
  try {
    const { data } = await axios.get('/api/watchlist')
    watchlistItems.value = data
  } catch (e) {
    console.error(e)
  }
}

async function addWatchlist() {
  try {
    await axios.get('/sanctum/csrf-cookie')
    const payload = { symbol: newSymbol.value.trim().toUpperCase(), timeframe: newTimeframe.value }
    if (!payload.symbol) return

    // prevent duplicates client-side
    if (watchlistItems.value.some(i => i.symbol === payload.symbol && i.timeframe === payload.timeframe)) return

    const { data } = await axios.post('/api/watchlist', payload)
    watchlistItems.value.push(data)
    newSymbol.value = ''
  } catch (e) {
    if (e?.response?.status === 401) window.location.href = '/login'
    console.error(e)
  }
}

async function removeWatchlist(id) {
  try {
    await axios.get('/sanctum/csrf-cookie')
    await axios.delete(`/api/watchlist/${id}`)
    watchlistItems.value = watchlistItems.value.filter(i => i.id !== id)
  } catch (e) {
    console.error(e)
  }
}

async function fetchWatchlistData() {
  fetchingData.value = true
  fetchSuccess.value = ''
  fetchError.value   = ''
  try {
    await axios.get('/sanctum/csrf-cookie')
    const { data } = await axios.post('/api/watchlist/fetch')
    fetchSuccess.value = data?.message || 'Started'
  } catch (e) {
    fetchError.value = e?.response?.data?.message || e.message
  } finally {
    fetchingData.value = false
  }
}

async function fetchGexLevels(sym, tf) {
  loading.value = true
  error.value   = null
  levels.value  = null
  try {
    const { data } = await axios.get('/api/gex-levels', {
      params: { symbol: sym, timeframe: tf }
    })
    levels.value = data
  } catch (e) {
    error.value = e?.response?.data?.error || e.message
  } finally {
    loading.value = false
  }
}
</script>

