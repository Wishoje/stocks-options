<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import axios from 'axios'
import Chart from 'chart.js/auto'

axios.defaults.withCredentials = true
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest'

// ---- Watchlist sidebar ----
const watchlist = ref([])
const symbol = ref('SPY')
const priming = ref(false)

// ---- Chain area ----
const expiries = ref([])
const grouped = ref({})
const selectedExp = ref(null)
const chain = computed(() => (selectedExp.value && grouped.value[selectedExp.value]) ? grouped.value[selectedExp.value] : [])

// ---- Position editor ----
const under = ref({ symbol: 'SPY', price: null })
const legs = ref([])
const defaultIv = ref(0.20)
const sideLabel = { long: 'Buy', short: 'Write' } // UI wording
const r = ref(0.00); const q = ref(0.00)

// ---- Results ----
const nowGreeks = ref(null)
const payoff = ref([])
const scenarios = ref([])
const busy = ref(false)
const err = ref('')

const spotText = ref('-0.1,-0.05,0,0.05,0.1')
const ivText   = ref('-0.05,-0.02,0,0.02,0.05')
const daysText = ref('0,5,10')

const parseList = (txt) => txt.split(',').map(s => Number(s.trim())).filter(v => Number.isFinite(v))

// ---- Chart ----
const canvasRef = ref(null)
let chart
function draw(){
  if(!canvasRef.value || !payoff.value.length) return
  const ctx = canvasRef.value.getContext('2d')
  const labels = payoff.value.map(p => p.S)
  const pnls   = payoff.value.map(p => p.pnl)
  if(chart) chart.destroy()
  chart = new Chart(ctx, {
    type: 'line',
    data: { labels, datasets: [{ label: 'PNL vs Spot (today)', data: pnls, pointRadius: 0 }] },
    options: { responsive: true, animation: false, scales: { x:{ ticks:{ maxTicksLimit: 8 } }, y:{ ticks:{ callback: v => (v/1000).toFixed(0)+'k' } } } }
  })
}

// ---- Data loaders ----
async function loadWatchlist(){
  try {
    const { data } = await axios.get('/api/watchlist')
    watchlist.value = Array.isArray(data) ? data : []
    if (watchlist.value.length && !symbol.value) {
      symbol.value = watchlist.value[0].symbol
    }
  } catch { watchlist.value = [] }
}

async function primeSymbol(sym){
  try {
    priming.value = true
    await axios.post('/api/prime-calculator', { symbol: sym })
  } catch {} finally {
    priming.value = false
  }
}

async function loadChain(sym){
  const { data } = await axios.get('/api/option-chain', { params: { symbol: sym } })
  if (data.priming) { priming.value = true; return }
  priming.value = false
  under.value = data.underlying || { symbol: sym, price: null }
  expiries.value = data.expirations || []
  grouped.value = data.grouped || {}
  if (!selectedExp.value || !grouped.value[selectedExp.value]) {
    selectedExp.value = expiries.value[0] || null
  }
}

function isoFromMd(md){ // 'MM-DD' -> closest future date (year rolls on Jan)
  const now = new Date()
  const year = now.getMonth()+1 > Number(md.slice(0,2)) ? now.getFullYear()+1 : now.getFullYear()
  return new Date(`${year}-${md}`).toISOString().slice(0,10)
}

function addFromRow(row){
  // row has strike/type/mid/bid/ask/expiry
  const price = Number(row.mid ?? row.bid ?? row.ask ?? 0) || 0
  legs.value.push({
    side: 'long',
    type: row.type,
    qty: 1,
    strike: Number(row.strike),
    expiry: isoFromMd(row.expiry),
    price,
  })
}

function removeLeg(i){ legs.value.splice(i,1) }

async function analyze(){
  err.value = ''; busy.value = true
  try{
    const payload = {
      underlying: { symbol: under.value.symbol, price: Number(under.value.price || 0) },
      default_iv: Number(defaultIv.value || 0.2),
      r: Number(r.value || 0), q: Number(q.value || 0),
      legs: legs.value.map(l => ({
        side: l.side, type: l.type, qty: Number(l.qty||1),
        strike: Number(l.strike), expiry: l.expiry,
        price: (l.price!==undefined && l.price!=='') ? Number(l.price) : undefined,
        iv:    (l.iv!==undefined && l.iv!=='') ? Number(l.iv) : undefined,
      })),
      scenarios: {
        spot_pct: parseList(spotText.value),
        iv_pts:   parseList(ivText.value),
        days:     parseList(daysText.value),
      }
    }
    const { data } = await axios.post('/api/position/analyze', payload)
    nowGreeks.value = data.now
    payoff.value = data.payoff
    scenarios.value = data.scenarios
    draw()
  } catch(e){
    err.value = e?.response?.data?.message || JSON.stringify(e?.response?.data?.errors || e.message)
  } finally { busy.value = false }
}

function fmtUsd(v){ return new Intl.NumberFormat('en-US',{style:'currency',currency:'USD',maximumFractionDigits:0}).format(Number(v||0)) }

// ---- Lifecycle ----
onMounted(async () => {
  await loadWatchlist()
  await primeSymbol(symbol.value)
  await loadChain(symbol.value)
  // If price missing, try to take latest from chain mid of ATM
  if (!under.value.price) {
    const rows = chain.value
    const atm = rows.length ? rows.reduce((a,b) =>
      Math.abs(b.strike - (b.under_price ?? 0)) < Math.abs((a?.strike ?? 0) - (b.under_price ?? 0)) ? b : a, null) : null
  }
})

watch(symbol, async (s) => {
  under.value.symbol = s
  legs.value = []
  await primeSymbol(s)
  await loadChain(s)
})
watch(selectedExp, () => { /* table auto updates via computed chain */ })
</script>

<template>
  <div class="min-h-[calc(100vh-120px)] bg-gray-950 text-white grid grid-cols-12 gap-4 p-4">
    <!-- Watchlist -->
    <aside class="col-span-12 md:col-span-2 bg-gray-900 border border-gray-800 rounded-xl p-3">
      <div class="text-xs uppercase text-gray-400 mb-2">Watchlist</div>
      <div class="space-y-1 max-h-[70vh] overflow-auto">
        <button v-for="w in watchlist" :key="w.id"
                @click="symbol = w.symbol"
                class="w-full text-left px-2 py-1 rounded hover:bg-gray-800"
                :class="symbol===w.symbol ? 'bg-cyan-700/30 text-cyan-300' : 'text-gray-200'">
          {{ w.symbol }}
        </button>
      </div>
    </aside>

    <!-- Option Chains -->
    <section class="col-span-12 md:col-span-5 bg-gray-900 border border-gray-800 rounded-xl p-3">
      <div class="flex items-center gap-3 mb-2">
        <div class="text-sm font-semibold">{{ under.symbol }}</div>
        <div class="text-xs text-gray-400">Spot: {{ under.price ?? '—' }}</div>
        <div v-if="priming" class="ml-auto text-[11px] px-2 py-0.5 rounded bg-amber-600/30 border border-amber-600/40">Warming data…</div>
        <button class="ml-auto text-xs px-2 py-1 bg-gray-800 rounded border border-gray-700"
                @click="() => loadChain(symbol)">Refresh</button>
      </div>

      <div v-if="!expiries.length && !priming" class="text-sm text-gray-400">No chain yet for {{ under.symbol }}.</div>

      <!-- Expiry chips -->
      <div class="flex flex-wrap gap-1.5 mb-2">
        <button v-for="e in expiries" :key="e" class="px-2 py-0.5 text-xs rounded border"
                :class="selectedExp===e ? 'bg-cyan-700/40 border-cyan-600 text-cyan-100' : 'bg-gray-800 border-gray-700 text-gray-300'"
                @click="selectedExp = e">
          {{ e }}
        </button>
      </div>

      <!-- Chain table -->
      <div class="max-h-[60vh] overflow-auto text-xs">
        <table v-if="chain.length" class="w-full">
          <thead class="text-gray-400 sticky top-0 bg-gray-900">
          <tr><th class="text-left">Type</th><th class="text-right">Strike</th><th class="text-right">Bid</th><th class="text-right">Mid</th><th class="text-right">Ask</th><th></th></tr>
          </thead>
          <tbody>
          <tr v-for="r in chain" :key="r.type + '-' + r.strike" class="border-t border-gray-800">
            <td class="py-1">{{ r.type }}</td>
            <td class="text-right">{{ Number(r.strike).toFixed(2) }}</td>
            <td class="text-right">{{ r.bid ?? '—' }}</td>
            <td class="text-right">{{ r.mid ?? '—' }}</td>
            <td class="text-right">{{ r.ask ?? '—' }}</td>
            <td class="text-right">
              <button class="px-2 py-0.5 bg-cyan-600 rounded" @click="addFromRow(r)">Add</button>
            </td>
          </tr>
          </tbody>
        </table>
        <div v-else class="text-gray-400">No rows for {{ selectedExp || '—' }}.</div>
      </div>
    </section>

    <!-- Position builder + results -->
    <section class="col-span-12 md:col-span-5 space-y-3">
      <div class="bg-gray-900 border border-gray-800 rounded-xl p-3">
        <div class="flex items-center justify-between mb-2">
          <div class="text-sm font-semibold">Your Position</div>
          <div class="text-xs text-gray-400">{{ under.symbol }}</div>
        </div>

        <div class="space-y-2">
          <div v-for="(l,i) in legs" :key="i" class="grid grid-cols-12 gap-2 items-center">
            <select v-model="l.side" class="col-span-2 bg-gray-800 rounded px-2 py-1">
              <option value="long">{{ sideLabel.long }}</option>
              <option value="short">{{ sideLabel.short }}</option>
            </select>
            <select v-model="l.type" class="col-span-2 bg-gray-800 rounded px-2 py-1">
              <option value="call">Call</option><option value="put">Put</option>
            </select>
            <input v-model.number="l.qty" type="number" min="1" class="col-span-2 bg-gray-800 rounded px-2 py-1" placeholder="Qty">
            <input v-model.number="l.strike" type="number" step="0.01" class="col-span-2 bg-gray-800 rounded px-2 py-1" placeholder="Strike">
            <input v-model="l.expiry" type="date" class="col-span-3 bg-gray-800 rounded px-2 py-1">
            <button @click="removeLeg(i)" class="col-span-1 text-red-300 hover:text-red-200">✕</button>

            <div class="col-span-12 grid grid-cols-12 gap-2">
              <input v-model.number="l.price" type="number" step="0.01" class="col-span-4 bg-gray-800 rounded px-2 py-1" placeholder="Price paid">
              <input v-model.number="l.iv"    type="number" step="0.01" class="col-span-4 bg-gray-800 rounded px-2 py-1" placeholder="IV (optional)">
              <div class="col-span-4 text-xs text-gray-400 pt-2">Leave IV blank to back it out from price.</div>
            </div>
          </div>

          <div v-if="!legs.length" class="text-xs text-gray-400">Pick a row from the chain to add a leg.</div>
        </div>

        <div class="mt-3 grid grid-cols-3 gap-2">
          <input v-model.number="under.price" type="number" step="0.01" class="bg-gray-800 rounded px-2 py-1" placeholder="Underlying price">
          <input v-model.number="defaultIv" type="number" step="0.01" class="bg-gray-800 rounded px-2 py-1" placeholder="Default IV (0.20)">
          <div class="grid grid-cols-2 gap-2">
            <input v-model.number="r" type="number" step="0.001" class="bg-gray-800 rounded px-2 py-1" placeholder="r">
            <input v-model.number="q" type="number" step="0.001" class="bg-gray-800 rounded px-2 py-1" placeholder="q">
          </div>
        </div>

        <div class="mt-3">
          <button @click="analyze" :disabled="busy || !legs.length || !under.price"
                  class="px-3 py-1.5 bg-cyan-600 rounded hover:bg-cyan-700 disabled:opacity-50">
            {{ busy ? 'Calculating…' : 'Calculate' }}
          </button>
          <span v-if="!under.price" class="ml-2 text-xs text-amber-300">Enter underlying price.</span>
        </div>

        <div v-if="err" class="mt-2 text-red-400 text-sm">{{ err }}</div>
      </div>

      <div class="bg-gray-900 border border-gray-800 rounded-xl p-3">
        <canvas ref="canvasRef" class="w-full h-64 bg-gray-800 rounded"></canvas>

        <div class="mt-3">
          <div class="text-sm font-semibold mb-1">Scenarios</div>
          <div class="grid grid-cols-3 gap-2 text-xs">
            <input v-model="spotText" class="bg-gray-800 rounded px-2 py-1" placeholder="-0.1,-0.05,0,0.05,0.1">
            <input v-model="ivText"   class="bg-gray-800 rounded px-2 py-1" placeholder="-0.05,-0.02,0,0.02,0.05">
            <input v-model="daysText" class="bg-gray-800 rounded px-2 py-1" placeholder="0,5,10">
          </div>
          <button @click="analyze" :disabled="busy" class="mt-2 px-3 py-1.5 bg-gray-800 rounded">Recompute</button>

          <div v-if="scenarios.length" class="mt-3 max-h-48 overflow-auto">
            <table class="w-full text-xs">
              <thead class="text-gray-400">
              <tr><th class="text-left">d</th><th>Spot%</th><th>IV</th><th class="text-right">PNL</th></tr>
              </thead>
              <tbody>
              <tr v-for="(r,i) in scenarios.slice(0,60)" :key="i" class="border-t border-gray-800">
                <td>{{ r.d_days }}</td>
                <td>{{ (r.d_spot*100).toFixed(1) }}%</td>
                <td>{{ (r.d_iv*100).toFixed(1) }} pts</td>
                <td class="text-right" :class="r.pnl>=0?'text-green-300':'text-red-300'">{{ fmtUsd(r.pnl) }}</td>
              </tr>
              </tbody>
            </table>
            <div v-if="scenarios.length>60" class="text-gray-400 text-[11px] mt-1">(+{{ scenarios.length-60 }} more…)</div>
          </div>
        </div>

        <div v-if="nowGreeks" class="mt-3 grid grid-cols-3 gap-2 text-sm">
          <div class="bg-gray-800 rounded p-2">Δ {{ nowGreeks.delta.toFixed(2) }}</div>
          <div class="bg-gray-800 rounded p-2">Γ {{ nowGreeks.gamma.toFixed(4) }}</div>
          <div class="bg-gray-800 rounded p-2">Θ {{ nowGreeks.theta.toFixed(2) }}/d</div>
          <div class="bg-gray-800 rounded p-2">V {{ nowGreeks.vega.toFixed(2) }}/pt</div>
          <div class="bg-gray-800 rounded p-2">ρ {{ nowGreeks.rho.toFixed(2) }}</div>
          <div class="bg-gray-800 rounded p-2">Px {{ (nowGreeks.price/100).toFixed(2) }}</div>
        </div>
      </div>
    </section>
  </div>
</template>
