<script setup>
import { computed, ref, watch, nextTick, onScopeDispose } from 'vue'
import { useDexByStrike } from '@/composables/useDexByStrike'
const props = defineProps({ levels: Object, scopeLabel: String, enabled: { type: Boolean, default: true } })
const emit = defineEmits(['reading-inspected'])
const { data, loading, error, retry } = useDexByStrike(() => props.levels, () => props.enabled)
const selectedStrike = ref(null), start = ref(0), tableOpen = ref(false), tablePage = ref(0)
const chart = ref(null), width = ref(840)
let observer
watch(chart, element => {
  observer?.disconnect()
  if (!element || typeof ResizeObserver === 'undefined') return
  const resize = () => { width.value = Math.max(260, element.getBoundingClientRect().width) }
  resize(); observer = new ResizeObserver(resize); observer.observe(element)
}, { flush: 'post' })
onScopeDispose(() => observer?.disconnect())
const rows = computed(() => data.value?.strikes || [])
const pageSize = computed(() => width.value < 500 ? 15 : 31)
const maxStart = computed(() => Math.max(0, rows.value.length - pageSize.value))
const visible = computed(() => rows.value.slice(start.value, start.value + pageSize.value))
const selected = computed(() => rows.value.find(row => row.strike === selectedStrike.value))
const lastTablePage = computed(() => Math.max(0, Math.ceil(rows.value.length / 50) - 1))
const tableRows = computed(() => rows.value.slice(tablePage.value * 50, (tablePage.value + 1) * 50))
const nearClose = computed(() => {
  const price = data.value?.reference_price?.value
  return Number.isFinite(price) && rows.value.length ? rows.value.reduce((a, b) => Math.abs(a.strike - price) <= Math.abs(b.strike - price) ? a : b).strike : null
})
function choose(strike, inspect = true) {
  const index = rows.value.findIndex(row => row.strike === strike)
  if (index < 0) return
  selectedStrike.value = strike
  if (index < start.value || index >= start.value + pageSize.value) start.value = Math.max(0, Math.min(maxStart.value, index - Math.floor(pageSize.value / 2)))
  if (inspect) emit('reading-inspected', { indicator: 'dex_by_strike', strike })
}
watch(data, value => {
  start.value = 0; tablePage.value = 0; selectedStrike.value = null
  if (!value?.strikes.length) return
  choose(nearClose.value ?? value.summary.largest_positive?.strike ?? rows.value[0].strike, false)
})
watch(pageSize, () => { start.value = Math.min(start.value, maxStart.value); choose(selectedStrike.value, false) })
function page(direction) {
  start.value = Math.max(0, Math.min(maxStart.value, start.value + direction * pageSize.value))
  choose(visible.value[Math.floor(visible.value.length / 2)]?.strike)
}
async function move(event, strike) {
  const index = rows.value.findIndex(row => row.strike === strike)
  const next = event.key === 'ArrowLeft' ? index - 1 : event.key === 'ArrowRight' ? index + 1 : event.key === 'Home' ? 0 : event.key === 'End' ? rows.value.length - 1 : null
  if (next === null) return
  event.preventDefault(); choose(rows.value[Math.max(0, Math.min(rows.value.length - 1, next))]?.strike)
  await nextTick(); chart.value?.querySelector('[tabindex="0"]')?.focus()
}
const number = value => Number.isFinite(value) ? value.toLocaleString('en-US', { maximumFractionDigits: 2 }) : '—'
const signed = value => Number.isFinite(value) ? value.toLocaleString('en-US', { notation: 'compact', maximumFractionDigits: 2, signDisplay: 'exceptZero' }).replace('-', '−') : '—'
const tone = value => value > 0 ? 'positive' : value < 0 ? 'negative' : 'neutral'
const chartMax = computed(() => Math.max(1, ...visible.value.map(row => Math.abs(row.net_dex ?? 0))) * 1.1)
const y = value => 150 - value / chartMax.value * 120
const barWidth = computed(() => Math.max(2, (width.value - 80) / Math.max(1, visible.value.length) * .68))
const x = index => 60 + (index + .5) * (width.value - 80) / Math.max(1, visible.value.length)
const tickStep = computed(() => Math.max(1, Math.ceil(visible.value.length / Math.max(2, Math.floor(width.value / 90)))))
function download() {
  const url = URL.createObjectURL(new Blob([JSON.stringify({ dex_by_strike: data.value }, null, 2)], { type: 'application/json' }))
  const link = document.createElement('a'); link.href = url; link.download = `${data.value.symbol}-dex-by-strike-${data.value.data_date}.json`; link.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
</script>

<template>
  <section class="dex-strikes gex-panel" aria-label="Delta exposure by strike" :aria-busy="loading">
    <header><div><p class="eyebrow">{{ levels?.symbol }} · {{ scopeLabel }} · End of day</p><h2>Delta exposure by strike</h2><p>See where delta exposure is concentrated, then compare GEX at the same strike.</p></div></header>
    <p v-if="loading" class="loading" role="status">Loading {{ levels?.symbol }} delta exposure…</p>
    <div v-else-if="error" class="load-error" role="alert"><p>{{ error }}</p><button type="button" @click="retry">Retry delta exposure</button></div>
    <template v-else-if="data">
      <div class="context"><span>EOD {{ data.data_date }} · {{ data.expiration_dates.length }} expirations</span><span v-if="data.reference_price.value != null">Close {{ number(data.reference_price.value) }} · {{ data.reference_price.date }}</span><span>DEX in share equivalents</span></div>
      <div class="summary-cards">
        <article><h3>Net delta exposure</h3><strong :data-tone="tone(data.summary.net_dex)">{{ signed(data.summary.net_dex) }}</strong><p>Across {{ data.summary.strike_count }} strikes in this scope</p></article>
        <article><h3>Largest positive DEX</h3><strong data-tone="positive">{{ signed(data.summary.largest_positive?.net_dex) }}</strong><button v-if="data.summary.largest_positive" type="button" @click="choose(data.summary.largest_positive.strike)">Inspect strike {{ number(data.summary.largest_positive.strike) }}</button><p v-else>No positive net exposure in this scope</p></article>
        <article><h3>Largest negative DEX</h3><strong data-tone="negative">{{ signed(data.summary.largest_negative?.net_dex) }}</strong><button v-if="data.summary.largest_negative" type="button" @click="choose(data.summary.largest_negative.strike)">Inspect strike {{ number(data.summary.largest_negative.strike) }}</button><p v-else>No negative net exposure in this scope</p></article>
      </div>
      <div v-if="rows.length" class="dex-layout">
        <div class="chart-panel">
          <div class="toolbar"><label>Inspect strike <select :value="selectedStrike" aria-label="Inspect DEX strike" @change="choose(Number($event.target.value))"><option v-for="row in rows" :key="row.strike" :value="row.strike">{{ number(row.strike) }}</option></select></label><button v-if="nearClose != null" type="button" @click="choose(nearClose)">Near close</button></div>
          <div class="legend"><span class="positive">Positive DEX</span><span class="negative">Negative DEX</span><span>Net share equivalents</span></div>
          <svg ref="chart" class="plot" :viewBox="`0 0 ${width} 315`" role="group" aria-label="Net delta exposure by strike; select a bar or use the strike selector for exact values">
            <g v-for="tick in [-1, -.5, 0, .5, 1]" :key="tick"><line x1="60" :x2="width - 20" :y1="y(tick * chartMax)" :y2="y(tick * chartMax)" stroke="currentColor" :opacity="tick === 0 ? .5 : .12"/><text x="50" :y="y(tick * chartMax) + 4" text-anchor="end">{{ signed(tick * chartMax) }}</text></g>
            <g v-for="(row, index) in visible" :key="row.strike" class="dex-bar" role="button" :tabindex="row.strike === selectedStrike ? 0 : -1" :aria-pressed="row.strike === selectedStrike" :aria-label="`Strike ${number(row.strike)}, net DEX ${signed(row.net_dex)} share equivalents`" @click="choose(row.strike)" @keydown="move($event, row.strike)" @keydown.enter.prevent="choose(row.strike)" @keydown.space.prevent="choose(row.strike)">
              <rect :x="x(index) - barWidth / 2 - 3" y="20" :width="barWidth + 6" height="262" fill="transparent"/>
              <rect v-if="row.net_dex != null" :x="x(index) - barWidth / 2" :y="row.net_dex >= 0 ? y(row.net_dex) : y(0)" :width="barWidth" :height="Math.max(1.5, Math.abs(y(row.net_dex) - y(0)))" rx="2" :fill="row.net_dex > 0 ? '#7edcaf' : row.net_dex < 0 ? '#efa391' : '#99b5d8'" :stroke="row.strike === selectedStrike ? '#dce8ff' : 'none'" stroke-width="2"/>
              <text v-else :x="x(index)" :y="y(0) + 4" text-anchor="middle">—</text>
              <text v-if="index % tickStep === 0" :x="x(index)" y="302" text-anchor="middle">{{ number(row.strike) }}</text>
            </g>
          </svg>
          <div class="chart-pages"><button type="button" :disabled="start === 0" @click="page(-1)">Lower strikes</button><span>{{ start + 1 }}–{{ Math.min(rows.length, start + pageSize) }} of {{ rows.length }} strikes</span><button type="button" :disabled="start === maxStart" @click="page(1)">Higher strikes</button></div>
          <p class="hint">Click a bar to compare DEX and GEX. Arrow keys move between strikes.</p>
        </div>
        <aside v-if="selected" class="inspector" aria-label="Selected delta exposure">
          <p class="eyebrow">Selected strike</p><h3>{{ number(selected.strike) }}</h3>
          <p>Net DEX</p><strong class="selected-dex" :data-tone="tone(selected.net_dex)">{{ signed(selected.net_dex) }}</strong><small>Share equivalents</small>
          <dl><div><dt>Call DEX</dt><dd>{{ signed(selected.call_dex) }}</dd></div><div><dt>Put DEX</dt><dd>{{ signed(selected.put_dex) }}</dd></div></dl>
          <div class="gex-comparison"><p>Net GEX at this strike</p><strong :data-tone="tone(selected.net_gex)">{{ signed(selected.net_gex) }}</strong><small>USD per 1% underlying move</small></div>
          <p class="hint">DEX shows directional exposure. GEX shows how exposure responds as price moves. Compare their locations and signs; their values use different units.</p>
        </aside>
      </div>
      <p v-else>No option strikes in this expiry scope. Choose another scope.</p>
      <p class="interpretation">Larger bars identify concentrations of modeled exposure. A positive or negative DEX reading alone does not tell you whether dealers will buy or sell.</p>
      <details class="guide"><summary>How to read delta exposure</summary><div><h3>Start with the levels near price</h3><p>Use Near close, then inspect a large bar. The selected-strike panel shows delta exposure and gamma exposure for the same expirations. This helps identify where both kinds of exposure are concentrated.</p><h3>Read the sign and units</h3><p>Call delta contributes positively and put delta negatively under the platform’s signed-delta convention. Net DEX combines them and is measured in share equivalents. For example, +100K represents the directional sensitivity of roughly 100,000 shares under this model; it is not an order to buy 100,000 shares.</p><h3>Use price behavior for confirmation</h3><p>A large bar identifies exposure, not a confirmed support, resistance or entry. Open Intraday → Wall tracking to see recorded price tests, touches and reactions at the walls.</p><h3>Calculation and scope</h3><p>DEX = signed option delta × open interest × 100, summed across the selected expirations. Net GEX = call GEX minus put GEX, in dollars per 1% price move. Both use the selected EOD option rows. The 100-share convention matches existing positioning; actual dealer holdings and future hedge trades are not observed.</p><p>The dealer-positioning card by expiration uses its own reporting window, so its total can differ. Select the same symbol, expirations and EOD view when comparing readings.</p></div></details>
      <footer><button type="button" @click="download">Export DEX by strike JSON</button></footer>
      <details class="all-readings" @toggle="tableOpen = $event.target.open"><summary>All DEX strike readings · {{ rows.length }}</summary><template v-if="tableOpen"><div class="chart-pages"><button type="button" :disabled="tablePage === 0" @click="tablePage--">Previous readings</button><span>Page {{ tablePage + 1 }} of {{ lastTablePage + 1 }}</span><button type="button" :disabled="tablePage === lastTablePage" @click="tablePage++">Next readings</button></div><div class="table-scroll"><table><caption>DEX in share equivalents · GEX in USD per 1% move</caption><thead><tr><th>Strike</th><th>Call DEX</th><th>Put DEX</th><th>Net DEX</th><th>Net GEX</th></tr></thead><tbody><tr v-for="row in tableRows" :key="row.strike"><td><button type="button" @click="choose(row.strike)">{{ number(row.strike) }}</button></td><td>{{ signed(row.call_dex) }}</td><td>{{ signed(row.put_dex) }}</td><td :data-tone="tone(row.net_dex)">{{ signed(row.net_dex) }}</td><td :data-tone="tone(row.net_gex)">{{ signed(row.net_gex) }}</td></tr></tbody></table></div></template></details>
    </template>
  </section>
</template>

<style scoped>
.dex-strikes{padding:22px;border:1px solid #364254;border-radius:14px;background:#171d28;color:#e8edf5;min-width:0}h2{font-size:22px;font-weight:700;margin:4px 0 7px}p{font-size:13px;color:#b6c6dc;line-height:1.6}.eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:#9cbbdf}.context,.toolbar,.legend,.chart-pages,footer{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.context{font-size:12px;color:#b6c6dc;margin:20px 0 16px;border-top:1px solid #364254;padding-top:14px}.summary-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:20px}.summary-cards article{padding:16px;border:1px solid #364254;border-radius:10px}.summary-cards h3{font-size:12px;color:#b6c6dc}.summary-cards strong{display:block;font-size:28px;margin:8px 0;font-variant-numeric:tabular-nums}.summary-cards p{font-size:11px}.summary-cards button{padding:4px 8px;font-size:11px}.dex-layout{display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:18px}.chart-panel{min-width:0;border:1px solid #364254;padding:16px;border-radius:10px;background:#1c2330}.toolbar{margin-bottom:12px}label{font-size:12px;color:#b6c6dc;display:flex;align-items:center;gap:8px}button,select{background:#243146;border:1px solid #455775;border-radius:7px;color:#e8edf5;padding:8px 10px;font-size:12px}button:hover{background:#30435d}button:disabled{opacity:.45;cursor:default}button:focus-visible,select:focus-visible,summary:focus-visible{outline:2px solid #a6cdff;outline-offset:3px}.legend{justify-content:flex-start;font-size:11px;margin:12px 0}.positive,[data-tone=positive]{color:#7edcaf}.negative,[data-tone=negative]{color:#efa391}.plot{width:100%;height:315px;color:#b6c6dc}.plot text{fill:#b6c6dc;font-size:10px;font-variant-numeric:tabular-nums}.dex-bar{cursor:pointer}.dex-bar:focus{outline:none}.dex-bar:focus-visible rect:first-child{stroke:#a6cdff;stroke-width:2}.chart-pages{font-size:11px;color:#b6c6dc;margin-top:12px}.hint{font-size:11px;margin-top:14px}.inspector{padding:18px;background:#1e2735;border:1px solid #435269;border-radius:12px;align-self:start}.inspector h3{font-size:28px;margin:5px 0 18px;font-weight:700}.selected-dex{display:block;font-size:30px;font-weight:700;font-variant-numeric:tabular-nums}.inspector small{display:block;font-size:11px;color:#b6c6dc;margin-top:3px}.inspector dl{margin-top:16px;display:grid;gap:9px;font-size:12px}.inspector dl div{display:flex;justify-content:space-between;gap:12px}.inspector dt{color:#b6c6dc}.inspector dd{font-variant-numeric:tabular-nums}.gex-comparison{border-block:1px solid #435269;padding:14px 0;margin-top:16px}.gex-comparison strong{font-size:24px;display:block;margin-top:4px}.interpretation{margin:16px 0}.guide,.all-readings{border-top:1px solid #364254;margin-top:8px}summary{cursor:pointer;color:#a6cdff;font-size:13px;padding:12px 0}.guide h3{font-size:13px;font-weight:650;margin:12px 0 5px}.guide p{max-width:100ch}.guide>div{padding-bottom:16px}footer{margin-top:14px}.table-scroll{overflow:auto;max-height:420px}table{width:100%;border-collapse:collapse;font-size:12px;white-space:nowrap}caption{text-align:left;color:#b6c6dc;padding:12px 0}th,td{padding:10px;border-bottom:1px solid #364254;text-align:right}th:first-child,td:first-child{text-align:left}th{background:#1e2735;position:sticky;top:0}tr:nth-child(even){background:#1e2735}.loading,.load-error{padding:20px 0}.load-error button{margin-top:10px}@media(max-width:1050px){.dex-layout{grid-template-columns:1fr}.inspector{display:grid;grid-template-columns:1fr 1fr;column-gap:20px}.inspector>.eyebrow,.inspector>h3,.inspector>.hint{grid-column:1/-1}.gex-comparison,.inspector dl{grid-column:1/-1}}@media(max-width:600px){.dex-strikes{padding:14px}.summary-cards{grid-template-columns:1fr}.summary-cards strong{font-size:25px}.chart-panel{padding:10px}.inspector{display:block}.chart-pages{gap:7px}.chart-pages button{font-size:11px;padding:8px}.context{gap:6px}}
</style>
