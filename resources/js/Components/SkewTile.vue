<template>
  <div class="bg-gray-800 rounded-2xl p-4">
    <!-- Header -->
    <div class="flex items-center justify-between gap-2">
      <h4 class="font-semibold">25Δ Skew & Curvature</h4>

      <div class="flex items-center gap-2 text-[11px]">
        <button
          class="px-2 py-1 rounded bg-gray-700 text-gray-300 hover:bg-gray-600"
          @click="showHelp = !showHelp"
          :aria-expanded="showHelp ? 'true' : 'false'"
          title="Open quick guide for this indicator"
        >
          How to read
        </button>

        <span
          v-if="row?.exp"
          class="px-2 py-0.5 rounded bg-gray-700 text-gray-300"
          :title="'Expiry used for this snapshot • Days to expiry (DTE)'"
        >
          {{ row.exp }} • {{ dte(row.exp) }} DTE
        </span>
        <span
          v-if="row?.data_date"
          class="px-2 py-0.5 rounded bg-gray-700 text-gray-400"
          title="Market date of the options snapshot"
        >
          {{ row.data_date }}
        </span>

        <button
          v-for="b in buckets"
          :key="b.key"
          class="px-2 py-1 rounded transition"
          :class="tab===b.key ? 'bg-gray-600 text-white' : 'bg-gray-700 text-gray-300 hover:bg-gray-600'"
          :title="bucketTitle(b)"
          @click="selectBucket(b.key)"
        >
          {{ b.label }}
        </button>
      </div>
    </div>

    <!-- Help panel -->
    <div
      v-if="showHelp"
      class="mt-3 text-[12px] leading-5 bg-gray-700/40 rounded-xl p-3 text-gray-200 space-y-2"
    >
      <div class="font-semibold text-gray-100">What is 25Δ skew?</div>
      <div>
        <span class="font-medium">Skew (pp)</span> = Put IV(25Δ) − Call IV(25Δ), shown in
        <em>percentage points</em>. Positive values mean 25Δ puts are richer than
        25Δ calls (common in indices); negative values mean call-skew.
      </div>

      <div class="font-semibold text-gray-100">Reading the meter</div>
      <ul class="list-disc pl-5 space-y-1">
        <li>
          The meter is zero-centered and clamped to <span class="font-medium">±5pp</span>.
          Marker at the right = put-heavy skew; left = call-tilted.
        </li>
        <li>
          <span class="font-medium">Rule of thumb:</span>
          <span class="text-green-300">≥ +5pp</span> very steep put-skew,
          <span class="text-green-300">+2–5pp</span> moderate put-skew,
          <span class="text-gray-300">−1–+1pp</span> near flat,
          <span class="text-red-300">≤ −1pp</span> call-skew tilt.
        </li>
      </ul>

      <div class="font-semibold text-gray-100">Recent skew bars</div>
      <div>
        Each bar = the chosen bucket’s nearest expiry on that day.
        Height is scaled to the most extreme absolute reading in the window.
        Green > 0 (put-skew), red &lt; 0 (call-skew). The small “Δ” shows change from
        first to last day.
      </div>

      <div class="font-semibold text-gray-100">Quality badge</div>
      <div>
        Uses server diagnostics when available:
        <code>n_points</code> (usable IV+Δ quotes) and <code>k_span</code>
        (log-moneyness span around ATM). <span class="font-medium">OK</span> if
        <code>n_points ≥ 20</code> and <code>k_span ≥ 0.05</code>. Otherwise you’ll see
        <em>Thin</em> (few quotes), <em>Narrow span</em>, or <em>Thin & Narrow</em>.
        When diagnostics are missing, the badge falls back to history density/range.
      </div>

      <div class="font-semibold text-gray-100">Using it in practice</div>
      <ul class="list-disc pl-5 space-y-1">
        <li>
          <span class="font-medium">Steep put-skew (≥ +5pp):</span>
          prefer put debits/diagonals; avoid tight call credits (calls price cheap).
        </li>
        <li>
          <span class="font-medium">Moderate put-skew (+2–5pp):</span>
          lean to put debits or balanced calendars.
        </li>
        <li>
          <span class="font-medium">Near flat (−1–+1pp):</span>
          selection by thesis; straddles/calendars are more symmetric.
        </li>
        <li>
          <span class="font-medium">Call-skew (≤ −1pp):</span>
          call debits may be cheaper than usual.
        </li>
        <li>
          <span class="font-medium">Curvature:</span>
          positive (smile) → wings pricey (flies/iron flies);
          negative (smirk) → wings relatively cheap (ratios/wing adds).
        </li>
      </ul>

      <div class="text-[11px] text-gray-400">
        Tip: buckets choose the <em>nearest expiry by calendar days</em> each day (ties prefer non-past).
        DTE differences can affect absolute skew levels across buckets.
      </div>
    </div>

    <!-- Meter -->
    <div class="mt-3" :title="meterTitle">
      <div class="h-2 w-full rounded bg-gradient-to-r from-red-700/30 via-gray-600/30 to-green-700/30"></div>
      <div v-if="row && num(row.skew_pc)!=null" class="relative -mt-2" aria-hidden="true">
        <div
          class="absolute -top-1 w-0 h-0 border-l-4 border-r-4 border-b-8 border-transparent border-b-gray-200"
          :style="{ left: meterLeftPct + '%' }"
        />
      </div>
      <div class="flex justify-between text-[10px] text-gray-500 mt-1">
        <span class="mt-1" title="Left cap of meter; values are clamped here">-5pp</span>
        <span class="mt-1" title="Zero skew = 25Δ puts and calls have equal IV">0</span>
        <span class="mt-1" title="Right cap of meter; values are clamped here">+5pp</span>
      </div>
    </div>

    <!-- Mini bars (recent history) -->
    <div v-if="barSeries.length" class="mt-3">
      <div class="flex items-center justify-between mb-1">
        <div class="text-xs text-gray-400">Recent skew</div>
        <div
          v-if="sparkDelta"
          class="text-xs"
          :class="sparkDelta.startsWith('+') ? 'text-green-400' : 'text-red-400'"
          :title="'Change from first to last reading in this window'"
        >
          Δ {{ sparkDelta }}
        </div>
      </div>
      <div class="grid grid-cols-12 gap-1">
        <div
          v-for="(p, i) in barSeries"
          :key="i"
          class="h-10 bg-gray-700 rounded relative overflow-hidden"
          :title="`${p.date} • ${(p.v*100).toFixed(1)}pp`"
        >
          <div
            class="absolute bottom-1 left-1 right-1 rounded"
            :class="p.v>=0 ? 'bg-green-400/70' : 'bg-red-400/70'"
            :style="{ height: p.hPct + '%' }"
          />
        </div>
      </div>
      <div class="flex justify-between text-[10px] text-gray-500 mt-1">
        <span>{{ barLabels.start }}</span><span>{{ barLabels.end }}</span>
      </div>
    </div>

    <!-- Loading / Error -->
    <div v-if="loading" class="mt-4">
      <div class="animate-pulse">
        <div class="h-4 bg-gray-700 rounded w-1/3 mb-2"></div>
        <div class="grid grid-cols-3 gap-4">
          <div class="h-8 bg-gray-700 rounded"></div>
          <div class="h-8 bg-gray-700 rounded"></div>
          <div class="h-8 bg-gray-700 rounded"></div>
        </div>
        <div class="h-4 bg-gray-700 rounded mt-3 w-1/2"></div>
      </div>
    </div>

    <div v-else-if="err" class="text-red-400 mt-3 text-sm">
      {{ err }}
      <a :href="debugHref" class="underline ml-2" target="_blank" rel="noopener noreferrer">debug</a>
    </div>

    <!-- Body -->
    <div v-else-if="row" class="mt-3 space-y-2">
      <!-- Quality -->
      <div v-if="qualityText" class="text-[11px] text-gray-400">
        Quality:
        <span :class="qualityClass">{{ qualityText }}</span>
        <span
          v-if="row.k_span"
          class="ml-2"
          :title="'Log-moneyness span around ATM used in fit (± shown as percent of spot). Larger is better.'"
        >
          (span ±{{ (num(row.k_span)*100).toFixed(1) }}%)
        </span>
      </div>

      <!-- 3 metrics -->
      <div class="grid grid-cols-3 gap-4 text-center mt-2">
        <div title="Interpolated 25Δ put implied volatility">
          <div class="text-gray-400 text-xs">Put IV (25Δ)</div>
          <div class="text-lg">{{ fmtPct(row.iv_put_25d) }}</div>
        </div>

        <div title="Interpolated 25Δ call implied volatility">
          <div class="text-gray-400 text-xs">Call IV (25Δ)</div>
          <div class="text-lg">{{ fmtPct(row.iv_call_25d) }}</div>
        </div>

        <div title="Skew = Put25Δ IV − Call25Δ IV (percentage points)">
          <div class="text-gray-400 text-xs">Skew (pp)</div>
          <div class="text-lg flex items-center justify-center gap-2">
            <span :class="['px-2 py-0.5 rounded', skewClass(row.skew_pc)]">
              {{ fmtPp(row.skew_pc) }}
            </span>
            <span v-if="row.skew_pc_dod!=null" :class="num(row.skew_pc_dod)>=0 ? 'text-green-400' : 'text-red-400'">
              {{ arrow(row.skew_pc_dod) }} {{ fmtPp(row.skew_pc_dod) }}
            </span>
          </div>
        </div>
      </div>

      <!-- Curvature + regime/advice -->
      <div class="text-xs text-gray-300 mt-1">
        Curvature:
        <span
          class="cursor-help"
          title="Quadratic term of IV vs log-moneyness near ATM; higher → stronger smile (pricey wings)."
        >
          {{ toFixedOrDash(row.curvature, 6) }}
        </span>
        <span v-if="row.curvature_dod!=null" :class="num(row.curvature_dod)>=0 ? 'text-green-400' : 'text-red-400'">
          ({{ arrow(row.curvature_dod) }} {{ toFixedOrDash(row.curvature_dod, 6) }})
        </span>
      </div>

      <div class="text-xs text-gray-300 mt-1">
        <span class="font-semibold">{{ regime.label }}</span>
        <span class="ml-2 text-gray-400">{{ regime.tip }}</span>
      </div>

      <p class="text-[12px] text-gray-400 mt-2 italic">
        {{ advice }}
      </p>
    </div>

    <div v-else class="mt-3 text-gray-400 text-sm">
      No skew data available for the selected bucket.
      <a :href="debugHref" class="underline ml-2" target="_blank" rel="noopener noreferrer">debug</a>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted, computed } from 'vue'
import axios from 'axios'

/* props */
const props = defineProps({ symbol: { type: String, default: 'SPY' } })

/* buckets */
const buckets = [
  { key: '0d', label: '0DTE', days: 0 },
  { key: '1w', label: '1W',   days: 7 },
  { key: '1m', label: '1M',   days: 21 },
]

/* state */
const tab = ref('1w')
const row = ref(null)
const err = ref(null)
const loading = ref(false)
const skewSeries = ref([]) // [{data_date, exp_date, dte, skew_pc, ...}]
const showHelp = ref(false)

/* utils */
function num(x){ const n = Number(x); return Number.isFinite(n) ? n : null }
function toFixedOrDash(x, dp=6){ const n=num(x); return n==null ? '—' : n.toFixed(dp) }
function fmtPct(x){ const n=num(x); return n==null ? '—' : (n*100).toFixed(1)+'%' }
function fmtPp(x){ const n=num(x); return n==null ? '—' : (n*100).toFixed(1)+'pp' }
function arrow(x){ const n=num(x); return n==null ? '' : (n>=0 ? '▲' : '▼') }
function dte(exp){ const t=new Date(exp); if (isNaN(t)) return '—'; const today=new Date(); return Math.max(0, Math.round((t-today)/86400000)) }

/* UI helpers */
function bucketTitle(b){ return `Target roughly ${b.days} calendar days to expiry; server picks nearest expiry.` }
const meterTitle = computed(()=>{
  const v = num(row.value?.skew_pc)
  if (v==null) return 'Skew meter (clamped to ±5 percentage points)'
  return `Skew today: ${(v*100).toFixed(1)}pp (clamped to ±5pp)`
})

/* styling */
function skewClass(s){
  const n = num(s)
  if (n==null) return 'bg-gray-600/40 text-gray-200'
  if (n > 0.05) return 'bg-green-500/15 text-green-300'
  if (n > 0.02) return 'bg-green-500/10 text-green-200'
  if (n < -0.01) return 'bg-red-500/15 text-red-300'
  return 'bg-gray-500/15 text-gray-200'
}

/* advice */
const advice = computed(() => {
  if (!row.value) return '—'
  const s = num(row.value.skew_pc)
  if (s == null) return '—'
  if (s > 0.05) return 'Steep put-skew → prefer put debits/diagonals; avoid tight call credits.'
  if (s > 0.02) return 'Moderate put-skew → lean to put debits or balanced calendars.'
  if (s < -0.01) return 'Call-skew tilt → call debits may be cheaper than usual.'
  return 'Skew near flat → select by thesis; calendars/straddles price more symmetrically.'
})

/* regime */
const regime = computed(() => {
  const s = num(row.value?.skew_pc)
  const c = num(row.value?.curvature)
  if (s == null) return { label: '—', tip: '' }
  const spp = s*100
  if (spp >= 5)  return { label: 'Steep put-skew', tip: 'Put debit/diagonal favored; call credits rich.' }
  if (spp >= 2)  return { label: 'Put-skew',       tip: 'Put debits fair; balanced calendars OK.' }
  if (spp <= -1) return { label: 'Call-skew tilt', tip: 'Call debits price better than usual.' }
  if (Math.abs(spp) < 1) {
    if (c != null && c > 0.02)  return { label: 'Smiley / convex',  tip: 'Wings pricey → consider flies/iron flies.' }
    if (c != null && c < -0.02) return { label: 'Smirky / concave', tip: 'Wings cheap → ratio spreads/wing adds.' }
  }
  return { label: 'Near flat', tip: 'Choose by thesis; straddles/calendars more symmetric.' }
})

/* quality */
const qualityText = computed(() => {
  const span = num(row.value?.k_span)
  const pts  = num(row.value?.n_points)
  if (span != null || pts != null) {
    const okP = pts != null ? pts >= 20 : true
    const okS = span != null ? span >= 0.05 : true
    if (okP && okS) return 'OK'
    if (!okP && okS) return `Thin (${pts ?? '—'} pts)`
    if (okP && !okS) return 'Narrow span'
    return 'Thin & Narrow'
  }
  // history fallback
  const vals = skewSeries.value.map(d => num(d.skew_pc)).filter(Number.isFinite)
  if (!vals.length) return ''
  const thin   = vals.length < 5
  const rangeP = (Math.max(...vals) - Math.min(...vals)) * 100
  const narrow = rangeP < 0.3
  if (!thin && !narrow) return 'OK'
  if (thin && !narrow)  return `Thin (${vals.length} pts)`
  if (!thin && narrow)  return 'Narrow range'
  return 'Thin & Narrow'
})
const qualityClass = computed(() => qualityText.value === 'OK' ? 'text-green-300' : 'text-yellow-300')

/* meter position (±5pp) */
const meterLeftPct = computed(() => {
  const v = num(row.value?.skew_pc)
  if (v == null) return 50
  const clamped = Math.max(-0.05, Math.min(0.05, v))
  return ((clamped + 0.05) / 0.10) * 100
})

/* mini-bars */
const barSeries = computed(() => {
  const vals = skewSeries.value.map(d => ({ date: d.data_date, v: num(d.skew_pc) })).filter(d => Number.isFinite(d.v))
  if (!vals.length) return []
  const absMax = Math.max(...vals.map(d => Math.abs(d.v))) || 1e-6
  return vals.map(d => ({ ...d, hPct: Math.min(100, (Math.abs(d.v)/absMax)*100) }))
})
const barLabels = computed(() => {
  if (!skewSeries.value.length) return { start: '', end: '' }
  return { start: skewSeries.value[0].data_date, end: skewSeries.value[skewSeries.value.length-1].data_date }
})
const sparkDelta = computed(() => {
  const a = skewSeries.value.map(d => num(d.skew_pc)).filter(Number.isFinite)
  if (a.length < 2) return null
  const d = (a[a.length-1]-a[0])*100
  return (d>=0?'+':'') + d.toFixed(1) + 'pp'
})

/* API */
function bucket(){ return buckets.find(b => b.key===tab.value) || buckets[1] }
const debugHref = computed(() => {
  const sym = encodeURIComponent(props.symbol)
  return row.value?.exp ? `/api/iv/skew/debug?symbol=${sym}&exp=${row.value.exp}` : `/api/iv/skew/debug?symbol=${sym}`
})

async function fetchSkew(){
  loading.value = true; err.value = null; row.value = null
  try {
    const b = bucket()
    const res = await axios.get('/api/iv/skew/by-bucket', { params: { symbol: props.symbol, days: b.days } })
    const r = res?.data
    row.value = (r && (r.exp_date || r.exp)) ? {
      exp: r.exp_date ?? r.exp,
      data_date: r.data_date ?? null,
      iv_put_25d: r.iv_put_25d ?? null,
      iv_call_25d: r.iv_call_25d ?? null,
      skew_pc: r.skew_pc ?? null,
      curvature: r.curvature ?? null,
      skew_pc_dod: r.skew_pc_dod ?? null,
      curvature_dod: r.curvature_dod ?? null,
      n_points: r.n_points ?? null,
      k_span: r.k_span ?? null,
    } : null
    if (!row.value) err.value = 'No skew row returned for this bucket.'
    await loadSkewHistory()
  } catch (e) {
    err.value = e?.response?.data?.message || e?.response?.data || e.message || 'Failed to load skew'
  } finally {
    loading.value = false
  }
}

async function loadSkewHistory(){
  try {
    const b = bucket()
    const { data } = await axios.get('/api/iv/skew/history/bucket', {
      params: { symbol: props.symbol, days: b.days, limit: 30 }
    })
    skewSeries.value = Array.isArray(data) ? data : []
  } catch {
    skewSeries.value = []
  }
}

async function selectBucket(key){ tab.value = key; await fetchSkew() }
onMounted(fetchSkew)
watch(() => props.symbol, fetchSkew)
</script>
