<script setup>
import { computed, ref, watch, onScopeDispose } from 'vue'
import { useGammaProfile } from '@/composables/useGammaProfile'
import UiHelpDialog from './UI/UiHelpDialog.vue'
import { compact } from './UI/numbers'

const props = defineProps({ levels: Object, scopeLabel: String })
const { data, loading, error, load } = useGammaProfile(() => props.levels)
const chartOpen = ref(false), chartElement = ref(null), width = ref(720), selectedIndex = ref(0)
const curve = computed(() => data.value?.curve || [])
const selected = computed(() => curve.value[selectedIndex.value])
const price = value => Number.isFinite(value) ? value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '—'
const exposure = value => Number.isFinite(value) ? compact(value) : '—'
const regime = value => ({ positive_gamma: 'Positive gamma', negative_gamma: 'Negative gamma', balanced: 'Balanced gamma' }[value] || '—')
const tone = value => value > 0 ? 'positive' : value < 0 ? 'negative' : 'neutral'
const flipLabel = computed(() => data.value?.flip_status === 'single_crossing' ? price(data.value.gamma_flip)
  : data.value?.flip_status === 'multiple_crossings' ? `${data.value.crossings.length} crossings` : 'No crossing in range')
const nearestIndex = value => curve.value.reduce((best, point, index) => Math.abs(point.price - value) < Math.abs(curve.value[best].price - value) ? index : best, 0)
watch(data, value => { selectedIndex.value = value?.reference_price ? nearestIndex(value.reference_price.value) : 0 })
const domain = computed(() => ({ min: curve.value[0]?.price ?? 0, max: curve.value.at(-1)?.price ?? 1 }))
const maxGex = computed(() => Math.max(1, ...curve.value.map(point => Math.abs(point.net_gex))) * 1.12)
const x = value => 66 + (value - domain.value.min) / (domain.value.max - domain.value.min || 1) * (width.value - 86)
const y = value => 140 - value / maxGex.value * 108
const line = computed(() => curve.value.map((point, index) => `${index ? 'L' : 'M'} ${x(point.price)} ${y(point.net_gex)}`).join(' '))
const ticks = computed(() => [-1, -.5, 0, .5, 1].map(value => value * maxGex.value))
function inspect(event) {
  const rect = chartElement.value.getBoundingClientRect()
  const position = (event.clientX - rect.left) / rect.width * width.value
  const target = domain.value.min + Math.max(0, Math.min(1, (position - 66) / (width.value - 86))) * (domain.value.max - domain.value.min)
  selectedIndex.value = nearestIndex(target)
}
let observer
watch(chartElement, element => {
  observer?.disconnect()
  if (!element || typeof ResizeObserver === 'undefined') return
  const resize = () => { width.value = Math.max(280, element.getBoundingClientRect().width) }
  resize(); observer = new ResizeObserver(resize); observer.observe(element)
}, { flush: 'post' })
onScopeDispose(() => observer?.disconnect())
function download() {
  const url = URL.createObjectURL(new Blob([JSON.stringify({ gamma_profile: data.value }, null, 2)], { type: 'application/json' }))
  const link = document.createElement('a'); link.href = url
  link.download = `${data.value.symbol}-gamma-profile-${data.value.timeframe}-${data.value.view_context?.view || 'eod'}-${data.value.data_date}.json`
  link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000)
}
</script>

<template>
  <section class="gamma-profile" aria-label="Gamma regime and flip" :aria-busy="loading">
    <header>
      <div><p class="eyebrow">{{ levels?.symbol }} · {{ scopeLabel }} · EOD price scenarios</p><h2>Gamma regime &amp; flip</h2><p>See how aggregate modeled exposure changes as the underlying price moves.</p></div>
      <UiHelpDialog id="gamma-profile-guide" title="How to read gamma regime and flip">
        <div class="guide">
          <p><strong>Start at the EOD close.</strong> The regime and net GEX use the same selected contracts and the same scenario curve. These are EOD scenarios, not live readings.</p>
          <p><strong>Find a crossing.</strong> A gamma flip is where aggregate modeled net GEX changes sign as the underlying price changes. It is different from a positive/negative strike bar boundary or HVL.</p>
          <p><strong>Read the signs.</strong> Positive and negative describe the model's call-minus-put exposure convention. They do not predict price direction or establish actual dealer holdings.</p>
          <p><strong>Check all crossings.</strong> Some curves have several crossings. Others have none in the modeled range of 10% below to 10% above the close. No crossing in that range does not mean none exists elsewhere.</p>
          <p><strong>Inspect a scenario.</strong> Open the chart and click it or use the price slider with arrow keys. The selected scenario changes the chart readout, while the summary remains anchored to the EOD close.</p>
          <p><strong>Keep the assumptions in view.</strong> Open interest and each contract's IV stay fixed while gamma is recalculated. The model uses European Black-Scholes, zero interest and dividends, and 100-share contracts. EOD 0DTE uses a one-minute time floor and is particularly sensitive to that assumption.</p>
          <p>Crossings are found on a finite price grid and refined numerically. This profile can differ from the stored provider-Greek GEX used in wall rankings and the separate fixed-2W DEX context. A crossing is context to examine alongside price behavior, not a trade trigger or win probability.</p>
        </div>
      </UiHelpDialog>
    </header>
    <p v-if="loading" role="status">Calculating {{ levels?.symbol }} gamma scenarios…</p>
    <div v-else-if="error" role="alert"><p>{{ error }}</p><button type="button" @click="load">Retry gamma profile</button></div>
    <p v-else-if="data?.status !== 'ready'" role="status">This gamma profile is not ready for the selected EOD view. Try another expiry scope; the wall levels remain available above.</p>
    <template v-else>
      <p class="context">{{ data.data_date }} · EOD close ${{ price(data.reference_price.value) }} · {{ data.expiration_dates.length }} selected expirations · Fixed IV and open interest</p>
      <div class="metrics">
        <article :data-tone="tone(data.current_sign)"><h3>Regime at EOD close</h3><strong>{{ regime(data.regime) }}</strong><p>Selected {{ scopeLabel }} modeled profile</p></article>
        <article><h3>Gamma flip</h3><strong>{{ flipLabel }}</strong><p v-if="data.gamma_flip != null">{{ price(data.distance_to_flip_pct) }}% {{ data.gamma_flip > data.reference_price.value ? 'above' : data.gamma_flip < data.reference_price.value ? 'below' : 'from' }} the EOD close</p><p v-else>Within ±10% of the EOD close</p></article>
        <article :data-tone="tone(data.current_sign)"><h3>Net GEX at EOD close</h3><strong>{{ exposure(data.net_gex_at_spot) }}</strong><p>USD per 1% underlying move</p></article>
      </div>
      <p v-if="data.audit.zero_dte_floor_rows" class="context">Includes 0DTE scenarios with a one-minute time floor at the EOD close.</p>
      <details class="chart-disclosure" @toggle="chartOpen = $event.target.open">
        <summary>Explore price scenarios</summary>
        <div v-if="chartOpen" class="chart-panel">
          <div class="chart-heading"><p><strong>Inspect ${{ price(selected?.price) }}</strong> · Net GEX {{ exposure(selected?.net_gex) }}</p><button type="button" @click="selectedIndex = nearestIndex(data.reference_price.value)">Back to EOD close</button></div>
          <div class="legend"><span>— Modeled net GEX</span><span>┆ EOD close</span><span class="crossing">┆ Zero crossings</span></div>
          <svg ref="chartElement" :viewBox="`0 0 ${width} 280`" role="img" aria-label="Modeled net GEX across underlying prices; use the price slider to inspect exact scenarios" @click="inspect">
            <g v-for="tick in ticks" :key="tick"><line x1="66" :x2="width - 20" :y1="y(tick)" :y2="y(tick)" stroke="currentColor" :opacity="tick === 0 ? .6 : .12" :stroke-dasharray="tick === 0 ? '5 4' : undefined"/><text x="58" :y="y(tick) + 4" text-anchor="end">{{ exposure(tick) }}</text></g>
            <line :x1="x(data.reference_price.value)" :x2="x(data.reference_price.value)" y1="25" y2="250" stroke="#a3b8d4" stroke-dasharray="5 4"/>
            <line v-for="crossing in data.crossings" :key="crossing.price" :x1="x(crossing.price)" :x2="x(crossing.price)" y1="25" y2="250" stroke="#e9c879" stroke-dasharray="2 4"/>
            <path :d="line" fill="none" stroke="#93bdfa" stroke-width="2"/>
            <circle v-if="selected" :cx="x(selected.price)" :cy="y(selected.net_gex)" r="5" fill="#171d28" stroke="#bcd9ff" stroke-width="2"/>
            <text x="66" y="273">${{ price(domain.min) }}</text><text :x="width - 20" y="273" text-anchor="end">${{ price(domain.max) }}</text>
          </svg>
          <label class="slider">Inspect underlying price <input v-model.number="selectedIndex" type="range" min="0" :max="curve.length - 1" aria-label="Inspect gamma scenario price" :aria-valuetext="`Price ${price(selected?.price)}, net GEX ${exposure(selected?.net_gex)}`"/></label>
          <div v-if="data.crossings.length" class="crossings" aria-label="Detected gamma crossings"><button v-for="(crossing, index) in data.crossings" :key="crossing.price" type="button" @click="selectedIndex = nearestIndex(crossing.price)">Crossing {{ index + 1 }} · ${{ price(crossing.price) }} · {{ crossing.below_sign > 0 ? 'positive → negative' : 'negative → positive' }}</button></div>
          <p v-else class="context">No sign change detected inside this price range.</p>
        </div>
      </details>
      <footer><p>Modeled price sensitivity, not a forecast. The chart follows the selected EOD expiry scope.</p><button type="button" @click="download">Export gamma profile JSON</button></footer>
    </template>
  </section>
</template>

<style scoped>
.gamma-profile{padding:22px;border:1px solid #364254;border-radius:14px;background:#171d28;color:#e8edf5}header,.chart-heading,footer{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}h2{font-size:21px;font-weight:700;margin:4px 0 7px}p{font-size:13px;color:#b6c6dc;line-height:1.6}.eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:#9cbbdf}.context{font-size:12px;margin:16px 0}.metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.metrics article{padding:18px;border:1px solid #3b475b;border-top:2px solid #93bdfa;border-radius:10px}.metrics h3{font-size:12px;color:#bdcde1}.metrics strong{display:block;font-size:clamp(20px,2vw,28px);font-weight:700;margin:9px 0;color:#a6cdff;font-variant-numeric:tabular-nums}.metrics [data-tone=positive]{border-top-color:#7edcaf}.metrics [data-tone=positive] strong{color:#7edcaf}.metrics [data-tone=negative]{border-top-color:#efa391}.metrics [data-tone=negative] strong{color:#efa391}.chart-disclosure{margin-top:18px}summary{cursor:pointer;padding:12px 0;color:#a6cdff;font-size:14px}.chart-panel{padding:16px;border:1px solid #364254;border-radius:12px;background:#1b2330}.chart-heading strong{color:#e8edf5}.legend{display:flex;gap:18px;flex-wrap:wrap;font-size:11px;color:#93bdfa;margin-top:12px}.crossing{color:#e9c879}svg{width:100%;height:280px;cursor:crosshair}svg text{fill:#b6c6dc;font-size:10px}.slider{display:block;font-size:12px}.slider input{display:block;width:100%;min-height:32px;accent-color:#93bdfa;margin-top:6px}.crossings{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}button{border:1px solid #455775;border-radius:8px;padding:8px 11px;background:#243146;color:#dce8f8;font-size:12px}button:hover{background:#30435d}button:focus-visible,summary:focus-visible,input:focus-visible{outline:2px solid #93bdfa;outline-offset:3px}footer{margin-top:16px}.guide p+p{margin-top:14px}@media(max-width:700px){.gamma-profile{padding:16px}.metrics{grid-template-columns:1fr}.chart-panel{padding:10px}}
</style>
