<script setup>
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { useIntradayVolatility } from '../composables/useIntradayVolatility'
const props = defineProps({ symbol: { type: String, required: true } })
const emit = defineEmits(['reading-inspected'])
const session = ref(''), expiry = ref(''), metric = ref('iv'), index = ref(0), tableOpen = ref(false)
watch(() => props.symbol, () => { session.value = ''; expiry.value = '' })
const { data, loading, error, refresh } = useIntradayVolatility(() => props.symbol, () => session.value, () => expiry.value)
const rows = computed(() => data.value?.readings || [])
const selected = computed(() => rows.value[index.value])
watch(rows, (next, old) => {
  const previous = old?.[index.value]?.recorded_at
  const retained = index.value < (old?.length || 0) - 1 ? next.findIndex(row => row.recorded_at === previous) : -1
  index.value = retained >= 0 ? retained : Math.max(0, next.length - 1)
})
const time = value => value ? new Intl.DateTimeFormat('en-US', { timeZone: 'America/New_York', hour: 'numeric', minute: '2-digit' }).format(new Date(value)) : '—'
const fmt = (value, unit = '%', signed = false) => Number.isFinite(value) ? `${signed && value > 0 ? '+' : ''}${value.toFixed(2)}${unit === '%' ? '%' : ' pp'}` : '—'
const change = key => {
  const row = selected.value
  if (row?.[key + '_change'] == null) return 'Comparison appears with a comparable reading'
  if (row.recorded_at === row[key + '_baseline_at']) return 'First reading in this comparison'
  return `${fmt(row[key + '_change'], 'pp', true)} since ${time(row[key + '_baseline_at'])} ET`
}
const message = computed(() => {
  const value = selected.value?.[metric.value + '_change']
  if (!Number.isFinite(value) || Math.abs(value) < .005) return metric.value === 'iv' ? 'Compare how options volatility changes through the session.' : 'Compare put and call volatility at the same expiration.'
  if (metric.value === 'iv') return value > 0 ? 'Near-money implied volatility has risen since the first comparable reading.' : 'Near-money implied volatility has fallen since the first comparable reading.'
  return value > 0 ? 'Put IV has increased relative to call IV since the first comparable reading.' : 'Put IV has decreased relative to call IV since the first comparable reading.'
})
const host = ref(null), width = ref(700)
let observer
onMounted(() => {
  if (typeof ResizeObserver !== 'undefined') {
    observer = new ResizeObserver(entries => { width.value = Math.max(250, entries[0].contentRect.width) })
    if (host.value) observer.observe(host.value)
  }
})
onBeforeUnmount(() => observer?.disconnect())
const range = computed(() => {
  const values = rows.value.map(row => row[metric.value]).filter(Number.isFinite)
  if (!values.length) return { min: 0, max: 1 }
  const lo = Math.min(...values), hi = Math.max(...values), pad = Math.max((hi - lo) * .15, .1)
  return { min: lo - pad, max: hi + pad }
})
const x = i => {
  const first = Date.parse(rows.value[0]?.recorded_at), last = Date.parse(rows.value.at(-1)?.recorded_at)
  return first === last ? width.value / 2 : 58 + (Date.parse(rows.value[i]?.recorded_at) - first) / (last - first) * (width.value - 82)
}
const y = value => 205 - (value - range.value.min) / (range.value.max - range.value.min) * 175
const ticks = computed(() => Array.from({ length: 5 }, (_, i) => range.value.min + (range.value.max - range.value.min) * i / 4))
const chartPaths = computed(() => {
  const solid = [], connectors = []
  let previous = null
  rows.value.forEach((row, i) => {
    if (!Number.isFinite(row[metric.value])) return
    const timestamp = Date.parse(row.recorded_at), point = `${x(i)},${y(row[metric.value])}`
    const connected = previous !== null && i === previous.index + 1 && timestamp - previous.timestamp <= 20 * 60000
    solid.push(`${connected ? 'L' : 'M'}${point}`)
    // Connect observed endpoints for readability without creating intermediate readings.
    if (previous !== null && !connected) connectors.push(`M${previous.point} L${point}`)
    previous = { index: i, timestamp, point }
  })
  return { solid: solid.join(' '), connectors: connectors.join(' ') }
})
function inspect() { emit('reading-inspected', { indicator: 'intraday_volatility', recorded_at: selected.value?.recorded_at }) }
function changeSession(event) { expiry.value = ''; session.value = event.target.value }
function download() {
  if (!data.value) return
  const url = URL.createObjectURL(new Blob([JSON.stringify(data.value, null, 2)], { type: 'application/json' }))
  const anchor = document.createElement('a'); anchor.href = url
  anchor.download = `iv-skew-${props.symbol}-${data.value.session}-${data.value.expiry}.json`; anchor.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
</script>

<template>
  <section class="iv-session gex-panel" aria-label="Intraday IV and skew" :aria-busy="loading">
    <header class="iv-heading">
      <div><p class="gex-eyebrow">{{ symbol }} · INTRADAY OPTIONS PRICING</p><h2>How options pricing is changing</h2><p>Follow implied volatility and put-versus-call pricing through the session.</p></div>
      <div class="iv-actions"><button type="button" :disabled="loading" @click="refresh">{{ loading ? 'Updating…' : 'Refresh IV & skew' }}</button><button type="button" :disabled="!rows.length" @click="download">Export IV & skew JSON</button></div>
    </header>
    <div class="iv-controls">
      <label>Session<input type="date" :value="session || data?.session || ''" aria-label="IV session" @change="changeSession" /></label>
      <button v-if="session" type="button" @click="session = ''; expiry = ''">Latest session</button>
      <label>Expiration<select :value="expiry || data?.expiry || ''" :disabled="loading || !data?.expiries.length" aria-label="IV expiration" @change="expiry = $event.target.value"><option v-for="date in data?.expiries || []" :key="date" :value="date">{{ date }}</option></select></label>
      <span class="iv-context">Same expiration throughout · Default nearest to 7 days</span>
    </div>
    <p class="iv-timing">Delayed intraday updates · Recorded times shown in ET · Comparison starts after 9:45 AM ET</p>
    <p v-if="error" role="alert">{{ error }} <span v-if="data">Showing the last loaded readings.</span></p>
    <p v-if="loading && !data" role="status">Loading {{ symbol }} IV and skew…</p>
    <template v-else-if="data && rows.length">
      <div class="iv-reading-header"><strong>Recorded {{ data.session }} · {{ time(selected?.recorded_at) }} ET</strong><span>{{ rows.length }} readings · Expiration {{ data.expiry }}</span></div>
      <div class="iv-metrics">
        <article><h3>Near-money IV</h3><strong data-testid="iv-level">{{ fmt(selected?.iv) }}</strong><p>{{ change('iv') }}</p><small>Average of 50-delta call and put IV</small></article>
        <article><h3>Put IV · 25 delta</h3><strong>{{ fmt(selected?.put_iv) }}</strong><p>Downside options volatility</p></article>
        <article><h3>Call IV · 25 delta</h3><strong>{{ fmt(selected?.call_iv) }}</strong><p>Upside options volatility</p></article>
        <article><h3>Put-minus-call skew</h3><strong data-testid="iv-skew">{{ fmt(selected?.skew, 'pp', true) }}</strong><p>{{ change('skew') }}</p><small>Positive means put IV exceeds call IV</small></article>
      </div>
    </template>
    <div ref="host" class="iv-chart-host">
      <template v-if="rows.length">
        <div class="iv-chart-heading"><div class="iv-switch" role="group" aria-label="Volatility chart metric"><button type="button" :aria-pressed="metric === 'iv'" @click="metric = 'iv'">IV level</button><button type="button" :aria-pressed="metric === 'skew'" @click="metric = 'skew'">Put vs call skew</button></div><p>{{ message }}</p></div>
        <div v-if="chartPaths.connectors" class="iv-legend" aria-label="Chart line styles"><span><i class="iv-legend-solid" aria-hidden="true"></i>Recorded trend</span><span><i class="iv-legend-connector" aria-hidden="true"></i>Connection between readings</span></div>
        <svg class="iv-chart" :viewBox="`0 0 ${width} 248`" role="img" :aria-label="`${symbol} ${metric === 'iv' ? 'implied volatility in percent' : 'put-minus-call skew in percentage points'}, recorded on ${data?.session}. ${chartPaths.connectors ? 'Dashed lines connect recorded endpoints for continuity; values between them are not observations. ' : ''}Inspect readings using the slider or table.`">
          <g v-for="tick in ticks" :key="tick"><line x1="58" :x2="width-24" :y1="y(tick)" :y2="y(tick)" class="iv-grid" /><text x="49" :y="y(tick)+4" text-anchor="end">{{ tick.toFixed(2) }}</text></g>
          <text x="58" y="18">{{ metric === 'iv' ? 'IV %' : 'Skew · pp' }}</text>
          <path v-if="chartPaths.connectors" :d="chartPaths.connectors" class="iv-connector" />
          <path :d="chartPaths.solid" class="iv-line" />
          <template v-for="(row, i) in rows" :key="row.recorded_at"><circle v-if="Number.isFinite(row[metric])" :cx="x(i)" :cy="y(row[metric])" :r="i === index ? 5 : 2" class="iv-point" /></template>
          <line v-if="Number.isFinite(selected?.[metric])" :x1="x(index)" :x2="x(index)" y1="26" y2="205" class="iv-cursor" />
          <text x="58" y="233">{{ time(rows[0].recorded_at) }}</text><text v-if="rows.length > 1" :x="width-24" y="233" text-anchor="end">{{ time(rows.at(-1).recorded_at) }} ET</text>
          <text v-if="!chartPaths.solid" :x="width / 2" y="115" text-anchor="middle">Choose another expiration to compare this metric</text>
        </svg>
        <label class="iv-scrubber"><span>Inspect reading <strong>{{ time(selected?.recorded_at) }} ET</strong></span><input v-model.number="index" type="range" min="0" :max="Math.max(0, rows.length-1)" :disabled="rows.length < 2" aria-label="Inspect IV reading" :aria-valuetext="`${time(selected?.recorded_at)} ET, IV ${fmt(selected?.iv)}, skew ${fmt(selected?.skew, 'pp')}`" @input="inspect" /></label>
        <p class="iv-footnote">{{ rows.length === 1 ? 'One reading so far. Changes appear as further readings arrive.' : 'Changes compare with the first available reading for this expiration.' }}<template v-if="chartPaths.connectors"> Dots mark recorded values. Dashed lines connect them for continuity and do not add readings.</template></p>
      </template>
    </div>
    <p v-if="data && !rows.length && !loading">{{ data.status === 'capacity_exceeded' ? 'This session has more readings than this view can display.' : `No IV comparison recorded${data.session ? ` for ${symbol} on ${data.session}` : ` for ${symbol}`} yet. Choose another session or expiration.` }}</p>
    <details class="iv-guide"><summary>How to read IV and skew</summary><p><strong>Start with IV.</strong> Higher near-money IV means options imply a larger expected price move. Lower IV means a smaller implied move. IV does not tell you which direction price will go.</p><p><strong>Then compare puts and calls.</strong> A skew of +3 pp means 25-delta put IV is three percentage points above 25-delta call IV. If the skew rises, puts have become relatively more expensive in volatility terms. This does not identify buying or selling.</p><p><strong>Use a consistent comparison.</strong> We keep the expiration fixed and compare 50-delta IV and 25-delta skew. The strikes used can change as price moves. A change from 20% to 22% IV is +2 percentage points. The starting reading may be after the market open.</p><p>Read this beside wall tracking: one shows how options are priced, the other shows price behavior around modeled levels. Neither establishes a trade entry.</p><p>Times show when readings were collected from delayed intraday updates. Near-money IV uses the average of interpolated call and put IV at 50 delta. Skew uses interpolated put IV minus call IV at 25 delta.</p></details>
    <details v-if="rows.length" class="iv-table" @toggle="tableOpen = $event.target.open"><summary>All {{ rows.length }} readings</summary><div v-if="tableOpen" class="iv-table-scroll"><table><caption>{{ symbol }} · {{ data.session }} · Expiration {{ data.expiry }} · Recorded times in ET</caption><thead><tr><th>Recorded</th><th>IV</th><th>Put IV</th><th>Call IV</th><th>Skew</th></tr></thead><tbody><tr v-for="(row, i) in rows" :key="row.recorded_at"><td><button type="button" :aria-pressed="i === index" @click="index = i; inspect()">{{ time(row.recorded_at) }}</button></td><td>{{ fmt(row.iv) }}</td><td>{{ fmt(row.put_iv) }}</td><td>{{ fmt(row.call_iv) }}</td><td>{{ fmt(row.skew, 'pp', true) }}</td></tr></tbody></table></div></details>
  </section>
</template>

<style scoped>
.iv-connector{fill:none;stroke:#9bc5ff;stroke-width:2;stroke-dasharray:6 5;opacity:.6}.iv-legend{display:flex;gap:16px;flex-wrap:wrap;margin-top:10px;color:#b7c4dc;font-size:12px}.iv-legend span{display:inline-flex;align-items:center;gap:7px}.iv-legend i{display:inline-block;width:22px;border-top:2px solid #9bc5ff}.iv-legend .iv-legend-connector{border-top-style:dashed;opacity:.6}
.iv-session{margin-top:20px;padding:22px;border:1px solid var(--gex-border,#373e53);border-radius:14px;background:var(--gex-surface,#1b1e29);color:var(--gex-text,#e4e9f6);min-width:0}
.iv-heading,.iv-reading-header,.iv-chart-heading,.iv-actions,.iv-controls{display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap}.iv-heading h2{font-size:19px;margin:6px 0}.iv-heading p,.iv-timing,.iv-footnote,.iv-context,small{color:#b7c4dc;font-size:12px}.iv-actions{justify-content:flex-start;gap:8px}button,select,input[type=date]{font:inherit;color:inherit;background:#25293a;border:1px solid #48516e;border-radius:7px;padding:9px 12px;min-height:36px}button{cursor:pointer}button[aria-pressed=true]{background:#303c58;border-color:#a8a7ff}button:disabled,select:disabled{opacity:.55;cursor:default}button:focus-visible,select:focus-visible,input:focus-visible,summary:focus-visible{outline:2px solid #b3acff;outline-offset:3px}.iv-controls{justify-content:flex-start;margin-top:22px}.iv-controls label{display:grid;gap:6px;font-size:12px}.iv-timing{margin:12px 0 20px}.iv-reading-header{font-size:12px;margin:14px 0}.iv-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.iv-metrics article{border:1px solid #3d455d;border-top:2px solid #98bff3;border-radius:10px;padding:16px}.iv-metrics h3{font-size:12px;font-weight:500;margin:0 0 10px}.iv-metrics strong{font-size:27px;color:#9bc5ff;font-variant-numeric:tabular-nums}.iv-metrics p{font-size:12px;margin:10px 0;line-height:1.5}.iv-chart-host{min-width:0}.iv-chart-heading{margin-top:22px;align-items:flex-start}.iv-chart-heading p{font-size:13px;margin:9px 0;max-width:600px}.iv-switch{display:flex;gap:6px;flex-wrap:wrap}.iv-chart{display:block;width:100%;margin-top:10px}.iv-chart text{font-size:11px;fill:#bdcce7}.iv-grid{stroke:#363c50;stroke-width:1}.iv-line{fill:none;stroke:#9bc5ff;stroke-width:2.5;stroke-linejoin:round}.iv-point{fill:#a7bfff}.iv-cursor{stroke:#a9a3ff;stroke-dasharray:3 4;opacity:.65}.iv-scrubber{display:block;margin:6px 0}.iv-scrubber span{display:flex;justify-content:space-between;font-size:12px}.iv-scrubber input{width:100%;margin:16px 0 6px;accent-color:#afa8ff}.iv-guide,.iv-table{margin-top:18px;font-size:13px;line-height:1.65}.iv-guide p{max-width:950px}.iv-table-scroll{overflow:auto;max-height:340px;margin-top:12px}table{width:100%;border-collapse:collapse;font-size:12px}th,td{text-align:right;padding:9px;border-bottom:1px solid #3b4257;white-space:nowrap}th:first-child,td:first-child{text-align:left}caption{text-align:left;padding-bottom:10px}summary{cursor:pointer;font-weight:600}
@media(max-width:900px){.iv-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:480px){.iv-session{padding:14px}.iv-metrics{gap:8px}.iv-metrics article{padding:12px}.iv-metrics strong{font-size:23px}.iv-heading h2{font-size:17px}.iv-context{width:100%}}
</style>
