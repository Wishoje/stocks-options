<template>
  <!-- Dark background, white text, min-h-screen -->
  <div class="p-4 bg-gray-900 text-white min-h-screen">

    <!-- ======================== WATCHLIST SECTION ======================= -->
    <div class="bg-gray-800 shadow-md rounded p-4 mb-6">
      <h2 class="text-xl font-bold mb-4">Watchlist</h2>

      <!-- Add symbol to watchlist -->
      <div class="mb-4 flex items-center gap-2">
        <label class="font-semibold">Symbol:</label>
        <input
          v-model="newSymbol"
          type="text"
          class="border border-gray-700 bg-gray-700 text-white px-2 py-1 rounded"
          placeholder="e.g. SPY"
        />

        <label class="font-semibold">Timeframe:</label>
        <select
          v-model="newTimeframe"
          class="border border-gray-700 bg-gray-700 text-white px-2 py-1 rounded"
        >
          <option value="0d">Today (0DTE)</option>
          <option value="1d">Next Day</option>
          <option value="7d">7 Days</option>
          <option value="14d">14 Days</option>
          <option value="monthly">Monthly</option>
        </select>

        <button
          @click="addWatchlist"
          class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-500"
        >
          + Add
        </button>
      </div>

      <!-- Display watchlist items -->
      <div v-if="watchlistItems.length" class="mb-4">
        <h3 class="font-semibold mb-2">Your Watchlist:</h3>
        <ul>
          <li
            v-for="item in watchlistItems"
            :key="item.id"
            class="flex items-center gap-2 mb-1"
          >
            <span>{{ item.symbol }} - {{ item.timeframe }}</span>
            <button
              @click="removeWatchlist(item.id)"
              class="px-2 py-1 bg-red-600 text-white rounded hover:bg-red-500"
            >
              Remove
            </button>
          </li>
        </ul>
      </div>

      <!-- Fetch Data Button -->
      <div>
        <button
          @click="fetchWatchlistData"
          class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-500"
        >
          Fetch Data for Watchlist
        </button>
        <div class="text-gray-400 mt-2" v-if="fetchingData">Fetching data in progress...</div>
        <div class="text-green-400 font-semibold" v-if="fetchSuccess">{{ fetchSuccess }}</div>
        <div class="text-red-400 font-semibold" v-if="fetchError">{{ fetchError }}</div>
      </div>
    </div>

    <!-- ======================== GEX LEVELS SECTION ======================= -->
    <div class="bg-gray-800 shadow-md rounded p-4">
      <h2 class="text-xl font-bold mb-4">GEX Levels & Calculations</h2>

      <!-- Symbol Input & Timeframe for Analysis -->
      <div class="mb-4 flex items-center gap-2">
        <label class="font-semibold">Symbol:</label>
        <input
          v-model="userSymbol"
          type="text"
          class="border border-gray-700 bg-gray-700 text-white px-2 py-1 rounded"
          placeholder="e.g. SPY"
        />

        <label class="font-semibold">Timeframe:</label>
        <select
          v-model="timeframe"
          class="border border-gray-700 bg-gray-700 text-white px-2 py-1 rounded"
        >
          <option value="0d">Today (0DTE)</option>
          <option value="1d">Next Day</option>
          <option value="7d">7 Days</option>
          <option value="14d">14 Days</option>
          <option value="monthly">Monthly</option>
        </select>

        <button
          @click="fetchGexLevels(userSymbol, timeframe)"
          class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-500"
        >
          Load Data
        </button>
      </div>

      <div v-if="loading" class="text-gray-300 mt-4">Loading...</div>
      <div v-if="error" class="text-red-400 font-bold mt-4">{{ error }}</div>

      <!-- Show GEX + Additional Calculations -->
      <div v-if="levels && !loading && !error" class="mt-4">
        <p><strong>Symbol:</strong> {{ levels.symbol }}</p>
        <p><strong>Timeframe:</strong> {{ levels.timeframe }}</p>
        <p><strong>Expiration Dates:</strong> {{ levels.expiration_dates.join(', ') }}</p>

        <!-- HVL & GEX-Related -->
        <p><strong>HVL:</strong> {{ levels.HVL }}</p>
        <p><strong>Call Resistance:</strong> {{ levels.call_resistance }}</p>
        <p><strong>Put Support:</strong> {{ levels.put_support }}</p>

        <!-- Additional calculations -->
        <h3 class="font-semibold mt-4">OI & Volume Metrics</h3>
        <ul class="list-disc ml-5">
          <li>Call OI: {{ levels.call_open_interest_total }}</li>
          <li>Put OI: {{ levels.put_open_interest_total }}</li>
          <li>Call OI %: {{ levels.call_interest_percentage }}%</li>
          <li>Put OI %: {{ levels.put_interest_percentage }}%</li>
          <li>Call Volume: {{ levels.call_volume_total }}</li>
          <li>Put Volume: {{ levels.put_volume_total }}</li>
          <li>PCR Volume: {{ levels.pcr_volume }}</li>
        </ul>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'

// GEX logic states
const userSymbol = ref('SPY')
const timeframe = ref('7d')

const levels = ref(null)
const loading = ref(false)
const error = ref(null)

// WATCHLIST states
const newSymbol = ref('')
const newTimeframe = ref('14d')
const watchlistItems = ref([])
const fetchingData = ref(false)
const fetchSuccess = ref('')
const fetchError = ref('')

/* 
  -------------
  WATCHLIST Logic
  -------------
*/

/** 
 * Retrieve existing watchlist from server on component mount
 */
onMounted(async () => {
  // 1) Ask Laravel for the XSRF-TOKEN cookie
  await fetch('/sanctum/csrf-cookie', {
    method: 'GET',
    credentials: 'include'  // important
  }).catch(err => console.error('CSRF cookie fetch error', err))

  await loadWatchlist()
})

/** 
 * Loads the watchlist items for the logged-in user 
 */
async function loadWatchlist() {
  try {
    const response = await fetch('/api/watchlist', {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
      credentials: 'include',
    })
    if (!response.ok) {
      throw new Error(`Failed to load watchlist. HTTP ${response.status}`)
    }
    const data = await response.json()
    watchlistItems.value = data
  } catch (err) {
    console.error(err)
  }
}

/**
 * Add new symbol/timeframe to watchlist
 */
async function addWatchlist() {
  try {
    const payload = { symbol: newSymbol.value, timeframe: newTimeframe.value }
    const response = await fetch('/api/watchlist', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify(payload)
    })
    if (!response.ok) {
      throw new Error(`Failed to add to watchlist. HTTP ${response.status}`)
    }
    const addedItem = await response.json()
    watchlistItems.value.push(addedItem)
    newSymbol.value = ''
    newTimeframe.value = '14d'
  } catch (err) {
    console.error(err)
  }
}

/**
 * Remove item from watchlist
 */
async function removeWatchlist(itemId) {
  try {
    const response = await fetch(`/api/watchlist/${itemId}`, {
      method: 'DELETE',
      headers: { 'Accept': 'application/json' },
      credentials: 'include',
    })
    if (!response.ok) {
      throw new Error(`Failed to remove from watchlist. HTTP ${response.status}`)
    }
    watchlistItems.value = watchlistItems.value.filter(item => item.id !== itemId)
  } catch (err) {
    console.error(err)
  }
}

/**
 * Fetch data for watchlist items 
 * (Calls your backend /api/watchlist/fetch -> job -> Finnhub)
 */
async function fetchWatchlistData() {
  try {
    fetchingData.value = true
    fetchSuccess.value = ''
    fetchError.value = ''

    const response = await fetch('/api/watchlist/fetch', {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      credentials: 'include',
    })
    if (!response.ok) {
      throw new Error(`Failed to fetch watchlist data. HTTP ${response.status}`)
    }
    const data = await response.json()
    fetchSuccess.value = data.message || 'Data fetch started'
  } catch (err) {
    fetchError.value = err.message || 'Error fetching data'
  } finally {
    fetchingData.value = false
  }
}

/* 
  -------------
  GEX Levels Logic
  -------------
*/
async function fetchGexLevels(symbol, tf) {
  loading.value = true
  error.value = null
  levels.value = null

  try {
    const response = await fetch(`/api/gex-levels?symbol=${symbol}&timeframe=${tf}`)
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`)
    }
    const data = await response.json()
    levels.value = data
  } catch (err) {
    error.value = err.message || "Failed to load GEX levels."
  } finally {
    loading.value = false
  }
}
</script>
