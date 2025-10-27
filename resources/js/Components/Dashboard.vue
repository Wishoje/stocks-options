<template>
  <div class="p-6 bg-gray-900 text-white min-h-screen space-y-6">
    <div class="bg-gray-800 rounded-2xl shadow-lg overflow-hidden">
      <!-- Header -->
      <div class="px-6 pt-6 pb-4 border-b border-gray-700/50">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div class="space-y-1">
            <h2 class="text-2xl font-bold tracking-tight">GEX Levels & Analytics</h2>
            <p class="text-xs text-gray-400">{{ userSymbol }} — {{ gexTf.value.toUpperCase() }}</p>
          </div>
          <!-- Controls -->
          <div class="px-6 pt-6 pb-4 border-b border-gray-700/50">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
              <div class="space-y-1">
                <h2 class="text-2xl font-bold tracking-tight">GEX Levels & Analytics</h2>
                <p class="text-xs text-gray-400">{{ userSymbol }}</p>
              </div>

              <!-- Group-local timeframe control (applies to GEX Levels + Strikes only) -->
              <div class="flex items-end gap-2">
                <div>
                  <label class="block text-xs font-semibold mb-1 text-gray-300">GEX/Strikes Timeframe</label>
                  <select v-model="gexTf" class="px-3 py-2 bg-gray-700 rounded focus:outline-none">
                    <option value="0d">0DTE</option><option value="1d">1DTE</option><option value="7d">7D</option>
                    <option value="14d">14D</option><option value="21d">21D</option><option value="30d">30D</option>
                    <option value="45d">45D</option><option value="60d">60D</option><option value="90d">90D</option>
                  </select>
                </div>
              </div>
            </div>

            <!-- Expiration chips -->
            <div v-if="levels?.expiration_dates?.length" class="mt-4 flex flex-wrap gap-2">
              <span v-for="d in levels.expiration_dates" :key="d"
                    class="px-2 py-1 rounded-full bg-gray-700 text-[11px] text-gray-200">{{ d }}</span>
            </div>

            <!-- Data freshness -->
            <div class="mt-2 text-[11px] text-gray-500" v-if="levels?.date_prev || lastUpdated">
              <span v-if="levels?.date_prev">Data: {{ levels.date_prev }} (EOD)</span>
              <span v-if="levels?.date_prev && lastUpdated"> • </span>
              <span v-if="lastUpdated">Updated {{ fromNow(lastUpdated) }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Tabs -->
      <div class="px-3 sm:px-4 pt-3 sticky top-0 bg-gray-800/85 backdrop-blur border-b border-gray-700/40 z-10">
        <nav class="flex gap-1 overflow-x-auto no-scrollbar" aria-label="Tabs">
          <button v-for="t in tabs" :key="t.key" @click="activate(t.key)"
            class="px-4 py-2 rounded-lg text-sm transition whitespace-nowrap"
            :class="activeTab === t.key ? 'bg-blue-600 text-white shadow'
                                        : 'text-gray-300 hover:text-white hover:bg-gray-700/60'">
            {{ t.label }}
          </button>
        </nav>
      </div>

      <!-- Body -->
      <div class="p-6 space-y-6">
        <!-- Top-level loading / error -->
        <ui-error-block v-if="topError" :message="'Failed to load levels'" :detail="topError"
                       :onRetry="() => fetchGexLevels(userSymbol, gexTf.value)" />
        <ui-spinner v-else-if="loading" />

        <template v-else>
          <!-- OVERVIEW -->
          <section v-show="activeTab==='overview'" class="space-y-6">
            <div class="bg-gray-700 rounded p-4">
              <h4 class="font-semibold mb-2">Q-Score</h4>
              <QScorePanel :symbol="userSymbol" />
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-7 gap-4">
              <MetricCard title="HVL" :value="levels?.hvl" />
              <MetricCard title="Call OI %" :value="fmtPct(levels?.call_interest_percentage)" />
              <MetricCard title="Put OI %"  :value="fmtPct(levels?.put_interest_percentage)" />
              <MetricCard title="Total OI"  :value="num(levels?.call_open_interest_total) + num(levels?.put_open_interest_total)" />
              <MetricCard title="Total Vol" :value="num(levels?.call_volume_total) + num(levels?.put_volume_total)" />
              <MetricCard title="Total ΔOI" :value="levels?.total_oi_delta" />
              <MetricCard title="Total ΔVol" :value="levels?.total_volume_delta" />
              <div class="sm:col-span-7 bg-gray-700 rounded p-4 text-center">
                <h3 class="font-semibold">PCR (Vol)</h3>
                <p class="text-xl">{{ levels?.pcr_volume }}</p>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div class="bg-gray-700 rounded p-4">
                <h4 class="font-semibold mb-2">OI Distribution</h4>
                <p class="text-sm text-gray-400 mb-4">Call vs Put open interest.</p>
                <OiDistributionChart
                  :call-oi="num(levels?.call_open_interest_total)"
                  :put-oi="num(levels?.put_open_interest_total)" />
              </div>
              <div class="bg-gray-700 rounded p-4">
                <h4 class="font-semibold mb-2">Volume Distribution</h4>
                <p class="text-sm text-gray-400 mb-4">Call vs Put volume.</p>
                <VolDistributionChart
                  :call-vol="num(levels?.call_volume_total)"
                  :put-vol="num(levels?.put_volume_total)" />
              </div>
              <!-- QScore -->
            </div>
          </section>

          <!-- POSITIONING -->
          <Suspense>
            <section v-show="activeTab==='positioning'" class="space-y-6">
              <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-gray-700/60 rounded-xl p-4">
                  <h4 class="font-semibold mb-2">Dealer Positioning</h4>
                  <component :is="busy.positioning ? uiSkeletonCard : DexTile" :symbol="userSymbol" />

                </div>
                <div class="bg-gray-700/60 rounded-xl p-4">
                  <h4 class="font-semibold mb-2">Expiry Pressure ({{ pinDays }}D)</h4>
                  <component :is="busy.positioning ? uiSkeletonCard : ExpiryPressureTile" :symbol="userSymbol" :days="pinDays" />
                </div>
              </div>
              <div class="bg-gray-700/60 rounded-xl p-4">
                <h4 class="font-semibold mb-2">IV Skew</h4>
                <component :is="busy.positioning ? uiSkeletonCard : SkewTile" :symbol="userSymbol" />
              </div>
            </section>
          </Suspense>

          <!-- VOLATILITY -->
          <section v-show="activeTab==='volatility'" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div class="bg-gray-700 rounded p-4">
                <div class="flex items-center justify-between mb-2">
                  <h4 class="font-semibold">Term Structure</h4>
                  <span v-if="term?.date" class="text-xs text-gray-400">as of {{ term.date }}</span>
                </div>

                <ui-error-block v-if="errors.volatility" :message="'Failed to load volatility data'"
                              :detail="errors.volatility" :onRetry="ensureVolatility" />
                <ui-skeleton-card v-else-if="!loaded.volatility" />
                <TermTile v-else :items="term.items || []" :date="term.date" />
              </div>

              <div class="bg-gray-700 rounded p-4">
                <div class="flex items-center justify-between mb-2">
                  <h4 class="font-semibold">Variance Risk Premium</h4>
                  <span v-if="vrp?.date" class="text-xs text-gray-400">as of {{ vrp.date }}</span>
                </div>

                <ui-error-block v-if="errors.volatility" :message="'Failed to load volatility data'"
                              :detail="errors.volatility" :onRetry="ensureVolatility" />
                <ui-skeleton-card v-else-if="!loaded.volatility" />
                <VRPTile v-else :date="vrp.date" :iv1m="vrp.iv1m" :rv20="vrp.rv20" :vrp="vrp.vrp" :z="vrp.z" />
              </div>
            </div>

            <div class="bg-gray-700 rounded p-4">
              <h4 class="font-semibold mb-2">Seasonality (5D)</h4>
              <ui-error-block v-if="errors.volatility" :message="'Failed to load seasonality'"
                            :detail="errors.volatility" :onRetry="ensureVolatility" />
              <ui-skeleton-card v-else-if="!loaded.volatility" />
              <template v-else>
                <Seasonality5Tile
                  v-if="season"
                  :date="season.date"
                  :d1="season.d1" :d2="season.d2" :d3="season.d3" :d4="season.d4" :d5="season.d5"
                  :cum5="season.cum5" :z="season.z" :note="seasonNote" />
                <div v-else class="text-sm text-gray-400">No seasonality data.</div>
              </template>
              <div v-if="volErr" class="text-red-400 text-sm mt-2">Vol metrics error: {{ volErr }}</div>
            </div>
          </section>

          <!-- UA -->
          <section v-show="activeTab==='ua'" class="space-y-4">
            <div class="flex items-center gap-2 text-xs">
              <label>Expiry</label>
              <select v-model="uaExp" class="px-2 py-1 bg-gray-700 rounded text-sm">
                <option value="ALL">All</option>
                <option v-for="d in (levels?.expiration_dates || [])" :key="d" :value="d">{{ d }}</option>
              </select>

              <label>Top</label>
              <input type="number" v-model.number="uaTop" class="w-16 bg-gray-700 rounded px-2 py-1">
              <label>Sort</label>
              <select v-model="uaSort" class="bg-gray-700 rounded px-2 py-1">
                <option value="z_score">Z-Score</option>
                <option value="premium">Premium ($)</option>
                <option value="vol_oi">Vol/OI</option>
              </select>

              <button @click="ensureUA" class="ml-auto px-3 py-1.5 bg-gray-700 hover:bg-gray-600 rounded">Apply</button>
              <button @click="showAdvanced = !showAdvanced" class="px-3 py-1.5 bg-gray-700 hover:bg-gray-600 rounded">
                {{ showAdvanced ? 'Hide' : 'Advanced' }}
              </button>
            </div>

            <div v-if="showAdvanced" class="flex flex-wrap items-center gap-2 text-xs mb-1">
              <label>min Z</label>
              <input type="number" step="0.1" v-model.number="uaMinZ" class="w-16 bg-gray-700 rounded px-2 py-1">
              <label>min Vol/OI</label>
              <input type="number" step="0.1" v-model.number="uaMinVolOI" class="w-16 bg-gray-700 rounded px-2 py-1">
              <label>min Vol</label>
              <input type="number" v-model.number="uaMinVol" class="w-20 bg-gray-700 rounded px-2 py-1">
              <label>min $</label>
              <input type="number" v-model.number="uaMinPrem" class="w-24 bg-gray-700 rounded px-2 py-1" placeholder="premium">
              <label>near ±%</label>
              <input type="number" v-model.number="uaNearPct" class="w-16 bg-gray-700 rounded px-2 py-1" placeholder="10">
              <label>Side</label>
              <select v-model="uaSide" class="bg-gray-700 rounded px-2 py-1">
                <option value="">Both</option><option value="call">Call-led</option><option value="put">Put-led</option>
              </select>
              <div class="ml-auto flex gap-2">
                <button @click="presetConservative" class="px-2 py-1 bg-gray-700 rounded">Conservative</button>
                <button @click="presetAggressive" class="px-2 py-1 bg-gray-700 rounded">Aggressive</button>
              </div>
            </div>

            <ui-error-block v-if="errors.ua" :message="'Failed to load UA'" :detail="errors.ua" :onRetry="ensureUA" />
            <ui-spinner v-else-if="uaLoading" />
            <template v-else>
              <div v-if="!uaDate" class="text-sm text-gray-400 mb-2">No UA data yet for today.</div>
              <UnusualActivityTable :rows="uaRows || []" :dataDate="uaDate" :symbol="userSymbol" />
              <div class="flex justify-center mt-3">
                <button @click="showMore" class="text-xs px-3 py-1.5 bg-gray-700 hover:bg-gray-600 rounded">Show more</button>
              </div>
            </template>
          </section>

          <!-- STRIKES -->
          <section v-show="activeTab==='strikes'" class="space-y-6">
            <div class="bg-gray-700 rounded p-4">
              <h4 class="font-semibold mb-2">ΔOI by Strike</h4>
              <p class="text-sm text-gray-400 mb-4">Daily OI change at each strike.</p>
              <StrikeDeltaChart :strikeData="levels?.strike_data || []" />
            </div>
            <div class="bg-gray-700 rounded p-4">
              <h4 class="font-semibold mb-2">ΔVol by Strike</h4>
              <p class="text-sm text-gray-400 mb-4">Daily volume change at each strike.</p>
              <VolumeDeltaChart :strikeData="levels?.strike_data || []" />
            </div>
            <div class="bg-gray-700 rounded p-4">
              <h4 class="font-semibold mb-2">Net GEX by Strike</h4>
              <p class="text-sm text-gray-400 mb-4">Net gamma exposure per strike (calls − puts).</p>
              <NetGexChart :strikeData="levels?.strike_data || []" />
            </div>
          </section>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue'
import axios from 'axios'

import MetricCard from './MetricCard.vue'
import StrikeDeltaChart    from './StrikeDeltaChart.vue'
import VolumeDeltaChart    from './VolumeDeltaChart.vue'
import NetGexChart         from './NetGexChart.vue'
import OiDistributionChart from './OiDistributionChart.vue'
import VolDistributionChart from './VolDistributionChart.vue'
import Seasonality5Tile from './Seasonality5Tile.vue'
import TermTile from './TermTile.vue'
import VRPTile  from './VRPTile.vue'
const SkewTile = defineAsyncComponent(() => import('./SkewTile.vue'))
const DexTile = defineAsyncComponent(() => import('./DexTile.vue'))
import QScorePanel from './QScorePanel.vue'
const ExpiryPressureTile = defineAsyncComponent(() => import('./ExpiryPressureTile.vue'))
import UnusualActivityTable from './UnusualActivityTable.vue'
import uiSpinner from './Spinner.vue'
import uiSkeletonCard from './SkeletonCard.vue'
import uiErrorBlock from './ErrorBlock.vue'
import { defineAsyncComponent } from 'vue'

axios.defaults.withCredentials = true
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest'

// Tabs
const tabs = [
  { key: 'overview',   label: 'Overview' },
  { key: 'positioning',label: 'Positioning' },
  { key: 'volatility', label: 'Volatility' },
  { key: 'ua',         label: 'Unusual Activity' },
  { key: 'strikes',    label: 'Strikes' },
]
const activeTab = ref('overview')
function activate(key) {
  activeTab.value = key
  if (key === 'volatility' && !loaded.value.volatility) ensureVolatility()
  if (key === 'ua'         && !loaded.value.ua)         ensureUA()
}

// state
const levels         = ref(null)
const loading        = ref(false)
const topError       = ref('')
const lastUpdated    = ref(null)

const pinDays = 3

// section busy/error flags
const busy = ref({ positioning: false })
const errors = ref({ volatility: '', ua: '' })

// volatility data
const term   = ref({ date:null, items:[] })
const vrp    = ref({ date:null, iv1m:null, rv20:null, vrp:null, z:null })
const season = ref(null)
const seasonNote = ref('')
const loaded = ref({ volatility: false, ua: false })

// UA data
const uaRows = ref([])
const uaDate = ref(null)
const uaExp  = ref('ALL')
const uaLoading = ref(false)
const uaTop      = ref(5)
const uaLimit    = ref(50)
const uaMinZ     = ref(2.5)
const uaMinVolOI = ref(2.0)
const uaMinVol   = ref(500)
const uaNearPct  = ref(10)
const uaSide     = ref('')
const uaSort     = ref('z_score')
const uaMinPrem  = ref(0)
const showAdvanced = ref(false)
const watchlistItems = ref([])
const pinMap = ref({})
const uaMap  = ref({})

const symbol          = ref('SPY')           // rename for clarity (ui)
const gexTf = ref('14d')         // ← only for /gex-levels + strikes
const userSymbol      = symbol               // keep template usage
const cache = new Map()
const cacheTerm  = new Map() // key: term|SYM
const cacheVRP   = new Map() // key: vrp|SYM
const cacheSeas  = new Map() // key: seas|SYM
const cacheUA    = new Map() // key: ua|SYM|EXP|top|minZ|minVOI|minVol|minPrem|near|side|sort|limit
const TTL_MS = 300_000
const volErr = ref(null)
const inflight = new Map()

// Abort controllers per request-type
const controllers = {
  gex:      null,
  term:     null,
  vrp:      null,
  season:   null,
  ua:       null,
}

// utils
function withInflight(key, fn){
  const hit = inflight.get(key);
  if (hit) return hit;
  const p = fn().finally(() => inflight.delete(key));
  inflight.set(key, p);
  return p;
}
function now(){ return Date.now() }
function setCache(map, key, data){ map.set(key, { t: now(), data }) }
function getCache(map, key, ttl){ const h = map.get(key); return (h && now()-h.t < ttl) ? h.data : null }
function cacheKeyGex(sym, tf){ return `gex|${sym}|${tf}` }
function num(v){ return Number(v || 0) }
function fmtPct(v){ return (v === null || v === undefined) ? '—' : `${v}%` }
function fromNow(ts) {
  if (!ts) return ''
  const then = new Date(ts).getTime()
  const diff = Math.round((then - Date.now())/1000)
  const abs  = Math.abs(diff)
  const steps = [['year',31536000],['month',2592000],['day',86400],['hour',3600],['minute',60],['second',1]]
  for (const [unit, s] of steps) {
    const amt = Math.trunc(diff / s)
    if (abs >= s || unit === 'second') {
      return new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' }).format(amt, unit)
    }
  }
  return ''
}
function cancel(type){
  try { controllers[type]?.abort() } catch {}
  controllers[type] = new AbortController()
  return controllers[type]
}

function ensureController(type){
  if (!controllers[type] || controllers[type].signal.aborted) {
    controllers[type] = new AbortController()
  }
  return controllers[type]
}

async function loadWatchlist() {
  try {
    const { data } = await axios.get('/api/watchlist')
    watchlistItems.value = Array.isArray(data) ? data : []
    await Promise.all([loadPinBatch(), loadUABadge(watchlistItems.value.map(i => i.symbol))])
  } catch {}
}

async function loadPinBatch() {
  const syms = [...new Set(watchlistItems.value.map(i => i.symbol))]
  if (!syms.length) { pinMap.value = {}; return }
  try {
    const { data } = await axios.get('/api/expiry-pressure/batch', { params: { symbols: syms, days: pinDays } })
    pinMap.value = data?.items || {}
  } catch { pinMap.value = {} }
}

async function loadUABadge(symbols) {
  const uniq = [...new Set(symbols)]
  const out = {}
  await Promise.all(uniq.map(async (s) => {
    try {
      const { data } = await axios.get('/api/ua', { params: { symbol: s } })
      out[s] = { data_date: data?.data_date || null, count: (data?.items || []).length }
    } catch { out[s] = { data_date: null, count: 0 } }
  }))
  uaMap.value = out
}

// data loaders with cancellation + error capture
async function fetchGexLevels(sym, tf = gexTf.value) {
  const key = cacheKeyGex(sym, tf)
  const hit = cache.get(key)
  if (hit && Date.now() - hit.t < TTL_MS) {
    levels.value = hit.data
    lastUpdated.value = new Date().toISOString()
    return
  }
  loading.value = true; topError.value=''; levels.value=null
  try {
    await withInflight(`gex:${key}`, async () => {
      const ctl = ensureController('gex')
      const { data } = await axios.get('/api/gex-levels', {
        params: { symbol: sym, timeframe: tf }, signal: ctl.signal
      })
      levels.value = data || {}
      cache.set(key, { t: Date.now(), data: levels.value })
      uaExp.value = 'ALL'
      lastUpdated.value = new Date().toISOString()
      return data
    })
  } catch (e) {
    if (e.name !== 'CanceledError' && e.code !== 'ERR_CANCELED') {
      topError.value = e?.response?.data?.error || e.message
    }
  } finally {
    if (!controllers.gex.signal.aborted) loading.value = false
  }
}

async function loadTermAndVRP(sym) {
  errors.value.volatility = ''
  const termCtl = ensureController('term')
  const vrpCtl  = ensureController('vrp')

  const tKey = `term|${sym}`; const vKey = `vrp|${sym}`
  const tHit = getCache(cacheTerm, tKey, 60_000)
  const vHit = getCache(cacheVRP, vKey, 60_000)
  if (tHit && vHit) { term.value = tHit; vrp.value = vHit; return }
  try {
    const t = await withInflight(`term:${sym}`, () =>
      axios.get('/api/iv/term', { params: { symbol: sym }, signal: termCtl.signal })
    )
    term.value = { date: t.data?.date ?? null, items: Array.isArray(t.data?.items) ? t.data.items : [] }
    setCache(cacheTerm, tKey, term.value)
    const v = await withInflight(`vrp:${sym}`, () =>
      axios.get('/api/vrp', { params: { symbol: sym }, signal: vrpCtl.signal })
    )
    vrp.value = { date: v.data?.date ?? null, iv1m: v.data?.iv1m ?? null, rv20: v.data?.rv20 ?? null, vrp: v.data?.vrp ?? null, z: v.data?.z ?? null }
    setCache(cacheVRP, vKey, vrp.value)
  } catch (e) {
    if (e.name !== 'CanceledError' && e.code !== 'ERR_CANCELED') {
      errors.value.volatility = e?.response?.data || e.message
      volErr.value = errors.value.volatility
    }
  }
}

async function loadSeasonality(sym) {
  const ctl = ensureController('season')
  const sKey = `seas|${sym}`
  const sHit = getCache(cacheSeas, sKey, 300_000) // 5 min; slow-changing
  if (sHit) { season.value = sHit.variant; seasonNote.value = sHit.note; return }
  try {
    const { data } = await withInflight(`season:${sym}`, () =>
      axios.get('/api/seasonality/5d', { params: { symbol: sym }, signal: ctl.signal })
    )
    season.value     = data?.variant || null
    seasonNote.value = data?.note || ''
    setCache(cacheSeas, sKey, data || {})
  } catch (e) {
    if (e.name !== 'CanceledError' && e.code !== 'ERR_CANCELED') {
      errors.value.volatility = errors.value.volatility || e.message
    }
  }
}

async function loadUA(sym, exp = null) {
  const ctl = ensureController('ua')
  uaLoading.value = true
  errors.value.ua = ''

  const k = [
    'ua', sym, exp ?? 'ALL', uaTop.value, uaMinZ.value, uaMinVolOI.value,
    uaMinVol.value, uaMinPrem.value, uaNearPct.value || 0, uaSide.value || '',
    uaSort.value, uaLimit.value
  ].join('|')

  const hit = getCache(cacheUA, k, 60_000)
  if (hit) {
    uaDate.value = hit.data_date || null
    uaRows.value = hit.items || []
    uaLoading.value = false
    return
  }

  try {
    const { data } = await withInflight(`ua:${k}`, () =>
      axios.get('/api/ua', {
        params: {
          symbol: sym,
          exp,
          per_expiry: uaTop.value,
          limit: uaLimit.value,
          min_z: uaMinZ.value,
          min_vol_oi: uaMinVolOI.value,
          min_vol: uaMinVol.value,
          min_premium: uaMinPrem.value,
          near_spot_pct: uaNearPct.value || 0,
          only_side: uaSide.value || null,
          with_premium: true,
          sort: uaSort.value
        },
        signal: ctl.signal
      })
    )
    uaDate.value = data?.data_date || null
    uaRows.value = data?.items || []
    setCache(cacheUA, k, data || {})
  } catch (e) {
    if (e.name !== 'CanceledError' && e.code !== 'ERR_CANCELED') {
      errors.value.ua = e?.response?.data || e.message
      uaDate.value = null
      uaRows.value = []
    }
  } finally {
    if (!ctl.signal.aborted) uaLoading.value = false
  }
}


// lazy triggers
async function ensureVolatility() {
  errors.value.volatility = ''
  loaded.value.volatility = false
  await Promise.all([loadTermAndVRP(userSymbol.value), loadSeasonality(userSymbol.value)])
  if (!errors.value.volatility) loaded.value.volatility = true
}
async function ensureUA() {
  errors.value.ua = ''
  loaded.value.ua = false
  await loadUA(userSymbol.value, uaExp.value === 'ALL' ? null : uaExp.value)
  loaded.value.ua = true
}

// presets / paging
function presetConservative(){ uaMinZ.value=3.0; uaMinVolOI.value=0.75; uaMinVol.value=1000; uaNearPct.value=5; uaSide.value='' }
function presetAggressive(){   uaMinZ.value=2.0; uaMinVolOI.value=0.25; uaMinVol.value=0;   uaNearPct.value=0; uaSide.value='' }
function showMore(){ uaTop.value=Math.min(uaTop.value+3,20); uaLimit.value=Math.min(uaLimit.value+30,200); ensureUA() }

// lifecycle
function onSymbolPicked(e){
  const sym = String(e.detail?.symbol || '').trim().toUpperCase()
  if (!sym) return
  userSymbol.value = sym
  loaded.value = { volatility: false, ua: false }
  fetchGexLevels(sym, gexTf.value)
}

onMounted(() => {
  loadWatchlist()
  window.addEventListener('select-symbol', onSymbolPicked)
  fetchGexLevels(userSymbol.value, gexTf.value)
})
onUnmounted(() => { window.removeEventListener('select-symbol', onSymbolPicked); Object.keys(controllers).forEach(cancel) })

let symbolTimer
watch(userSymbol, (s) => {
  clearTimeout(symbolTimer)
  symbolTimer = setTimeout(() => {
    fetchGexLevels(s, gexTf.value)
    // only reload UA if the UA tab was already loaded once
    if (loaded.value.ua && activeTab.value === 'ua') ensureUA()
    // no reload of volatility or positioning here
  }, 250)
})
watch(uaExp, () => { if (activeTab.value==='ua') ensureUA() })
watch([userSymbol], () => {
  busy.value.positioning = true
  // give child tiles a render frame; turn off a bit later
  requestAnimationFrame(() => setTimeout(() => { busy.value.positioning = false }, 250))
})
watch(gexTf, tf => {
  fetchGexLevels(userSymbol.value, tf)
})
</script>

<style scoped>
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>
