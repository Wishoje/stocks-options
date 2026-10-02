<script setup>
import { computed, ref, watch, nextTick } from 'vue'
import { useGexExpiryStrike } from '@/composables/useGexExpiryStrike'
import UiHelpDialog from './UI/UiHelpDialog.vue'
import { compact, dateLabel } from './UI/numbers'

const props = defineProps({ levels: Object, scopeLabel: String, enabled: { type: Boolean, default: true } })
const emit = defineEmits(['reading-inspected'])
const { data, loading, error, retry } = useGexExpiryStrike(() => props.levels, () => props.enabled)
const selectedStrike = ref(null), selectedExpiry = ref(''), strikeStart = ref(0), expiryStart = ref(0)
const tableOpen = ref(false), tablePage = ref(0), mapElement = ref(null)
const ROWS = 21, COLS = 8, PAGE = 50
const strikes = computed(() => data.value?.strikes || [])
const expiries = computed(() => data.value?.expiration_dates || [])
const selected = computed(() => strikes.value.find(row => row.strike === selectedStrike.value))
const selectedCell = computed(() => selected.value?.expirations.find(cell => cell.expiration === selectedExpiry.value))
const visibleStrikes = computed(() => strikes.value.slice(strikeStart.value, strikeStart.value + ROWS))
const visibleExpiries = computed(() => expiries.value.slice(expiryStart.value, expiryStart.value + COLS))
const cells = computed(() => new Map(strikes.value.map(row => [row.strike, new Map(row.expirations.map(cell => [cell.expiration, cell]))])))
const maxMagnitude = computed(() => strikes.value.reduce((max, row) => row.expirations.reduce((m, cell) => Math.max(m, Math.abs(cell.net_gex)), max), 0))
const tableRows = computed(() => tableOpen.value ? strikes.value.flatMap(row => row.expirations.map(cell => ({ strike: row.strike, ...cell }))) : [])
const tableSlice = computed(() => tableRows.value.slice(tablePage.value * PAGE, (tablePage.value + 1) * PAGE))
const maxTablePage = computed(() => Math.max(0, Math.ceil(tableRows.value.length / PAGE) - 1))
const rankedExpiries = computed(() => [...(selected.value?.expirations || [])].sort((a, b) => Math.abs(b.net_gex) - Math.abs(a.net_gex)))
const pct = ratio => ratio == null ? '—' : `${(100 * ratio).toFixed(1)}%`
const money = value => value == null ? '—' : compact(value)
const price = value => value == null ? '—' : Number(value).toLocaleString('en-US', { maximumFractionDigits: 2 })
const tone = value => value > 0 ? 'positive' : value < 0 ? 'negative' : 'neutral'
const cellAt = (strike, expiry) => cells.value.get(strike)?.get(expiry)
function cellColor(cell) {
  if (!cell || !cell.net_gex) return '#202a38'
  const alpha = .16 + .74 * Math.sqrt(Math.abs(cell.net_gex) / (maxMagnitude.value || 1))
  return `rgba(${cell.net_gex > 0 ? '94,218,176' : '244,145,126'},${alpha})`
}
function windowFor(index, length, size) { return Math.max(0, Math.min(Math.max(0, length - size), index - Math.floor(size / 2))) }
function chooseStrike(strike, expiry = null, notify = true) {
  selectedStrike.value = Number(strike)
  const row = selected.value
  if (!row) return
  selectedExpiry.value = expiry || row.dominant_expiry || row.expirations[0]?.expiration || expiries.value[0]
  strikeStart.value = windowFor(strikes.value.indexOf(row), strikes.value.length, ROWS)
  expiryStart.value = windowFor(Math.max(0, expiries.value.indexOf(selectedExpiry.value)), expiries.value.length, COLS)
  if (notify) emit('reading-inspected')
}
function centerClose(notify = true) {
  if (!strikes.value.length) return
  const target = data.value.reference_price?.value ?? data.value.summary.put_wall ?? strikes.value[0].strike
  const row = strikes.value.reduce((best, row) => Math.abs(row.strike - target) < Math.abs(best.strike - target) ? row : best)
  chooseStrike(row.strike, null, notify)
}
watch(data, value => { strikeStart.value = 0; expiryStart.value = 0; tablePage.value = 0; selectedStrike.value = null; selectedExpiry.value = ''; if (value) centerClose(false) })
function selectCell(strike, expiry) { selectedStrike.value = strike; selectedExpiry.value = expiry; emit('reading-inspected') }
async function moveCell(event, strike, expiry) {
  const moves = { ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1] }
  const move = moves[event.key]
  if (!move) return
  event.preventDefault()
  const si = Math.max(0, Math.min(strikes.value.length - 1, strikes.value.findIndex(row => row.strike === strike) + move[0]))
  const ei = Math.max(0, Math.min(expiries.value.length - 1, expiries.value.indexOf(expiry) + move[1]))
  chooseStrike(strikes.value[si].strike, expiries.value[ei])
  await nextTick(); mapElement.value?.querySelector('[data-selected="true"]')?.focus()
}
function pageStrikes(delta) {
  strikeStart.value = Math.max(0, Math.min(Math.max(0, strikes.value.length - ROWS), strikeStart.value + delta * ROWS))
  selectedStrike.value = strikes.value[strikeStart.value].strike
}
function pageExpiries(delta) {
  expiryStart.value = Math.max(0, Math.min(Math.max(0, expiries.value.length - COLS), expiryStart.value + delta * COLS))
  selectedExpiry.value = expiries.value[expiryStart.value]
}
const cellLabel = (strike, expiry) => `Strike ${price(strike)}, ${expiry}, ${cellAt(strike, expiry) ? 'net GEX ' + money(cellAt(strike, expiry).net_gex) : 'no contracts at this combination'}`
function download() {
  const url = URL.createObjectURL(new Blob([JSON.stringify({ gex_expiry_strike: data.value }, null, 2)], { type: 'application/json' }))
  const link = document.createElement('a'); link.href = url
  link.download = `${data.value.symbol}-gex-expirations-${data.value.timeframe}-${data.value.view_context?.view || 'eod'}-${data.value.data_date}.json`
  link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000)
}
</script>

<template>
  <section class="expiry-map" aria-label="GEX by strike and expiration" :aria-busy="loading">
    <header class="map-header">
      <div><p class="eyebrow">{{ levels?.symbol }} · {{ scopeLabel }} · End of day</p><h2>What is behind each level?</h2><p>Explore GEX by strike and expiration. See where exposure sits and when it rolls off.</p></div>
      <UiHelpDialog id="gex-expiry-guide" title="How to read GEX by strike and expiration">
        <div class="guide">
          <p><strong>Start with a level.</strong> Jump to the put wall, call wall, or EOD close. Rows are strikes and columns are expiration dates. Select a cell to see its call, put, and net exposure.</p>
          <p><strong>Read the color and sign together.</strong> Teal is positive net GEX; coral is negative. Brighter cells have larger absolute net GEX on one scale across the whole selection. The scale stays fixed as you browse. An equals sign means measured zero net exposure; a dash means there are no contracts at that strike/expiry combination.</p>
          <p><strong>Compare expirations at the same strike.</strong> The dominant-expiry share divides that expiry's absolute net GEX by the sum of absolute net GEX across expirations at this strike. For example, −6M and +4M contribute 60% and 40%, even though their signed total is −2M. It is not a share of gross call-plus-put exposure.</p>
          <p><strong>Check what expires next.</strong> The next-expiry share uses the earliest expiration in the selected dashboard scope. A large share means more measured exposure is tied to that expiry rolling off. It does not predict what the remaining book or price will do afterward.</p>
          <p><strong>Keep the scope consistent.</strong> Latest EOD data and next-session preparation can include different expirations. The controls follow the dashboard; these are stored EOD exposures, not intraday flows. Next-session preparation selects future expirations without forecasting new positions.</p>
          <p><strong>Units.</strong> Both GEX charts in this Strikes view show USD per 1% underlying move. Net GEX is call GEX minus put GEX using stored gamma and open interest, per-contract underlying prices, and a 100-share multiplier. This is an exposure model, not observed dealer inventory. Older raw API exports retain their existing units.</p>
          <p><strong>Use concentration as context.</strong> Expiry distribution does not confirm support, resistance, a hold, or a breakout. A dash in a concentration metric means that comparison is not calculated for this selection. Every recorded cell remains available in the table and JSON export.</p>
        </div>
      </UiHelpDialog>
    </header>
    <div v-if="loading" class="map-loading" role="status"><span class="loading-line"/>Loading {{ levels?.symbol }} expiration map…</div>
    <div v-else-if="error" class="map-error" role="alert"><p>{{ error }}</p><button type="button" @click="retry">Retry expiration map</button></div>
    <p v-else-if="!strikes.length" role="status">Choose an EOD selection with strike readings to explore its expiration map.</p>
    <template v-else>
      <div class="map-context"><span>{{ data.data_date }} · {{ strikes.length }} strikes · {{ expiries.length }} expirations</span><span>USD per 1% underlying move</span></div>
      <div class="map-layout">
        <div class="map-chart">
          <div class="toolbar">
            <label>Inspect strike <select :value="selectedStrike" @change="chooseStrike($event.target.value)"><option v-for="row in strikes" :key="row.strike" :value="row.strike">{{ price(row.strike) }}</option></select></label>
            <div class="shortcuts"><button v-if="data.summary.put_wall != null" type="button" class="put" @click="chooseStrike(data.summary.put_wall)">Put wall {{ price(data.summary.put_wall) }}</button><button v-if="data.summary.call_wall != null" type="button" class="call" @click="chooseStrike(data.summary.call_wall)">Call wall {{ price(data.summary.call_wall) }}</button><button type="button" @click="centerClose()">Near EOD close</button></div>
          </div>
          <div class="mobile-reading" aria-live="polite">
            <span>Strike {{ price(selectedStrike) }} · {{ dateLabel(selectedExpiry) }}</span>
            <strong :data-tone="tone(selectedCell?.net_gex)">{{ money(selectedCell?.net_gex) }}</strong>
            <small>Net GEX · USD per 1% move</small>
          </div>
          <div class="map-scroll">
            <div ref="mapElement" class="heatmap" role="group" aria-label="Expiration heatmap. Select a cell, then use arrow keys to explore." :style="{ '--columns': visibleExpiries.length }">
              <div class="axis-corner">Strike / expiry</div><div v-for="expiry in visibleExpiries" :key="expiry" class="axis-date" :title="expiry">{{ dateLabel(expiry) }}</div>
              <template v-for="row in visibleStrikes" :key="row.strike">
                <div class="axis-strike" :class="{ active: row.strike === selectedStrike }">{{ price(row.strike) }}</div>
                <button v-for="expiry in visibleExpiries" :key="expiry" type="button" class="heat-cell"
                  :style="{ backgroundColor: cellColor(cellAt(row.strike, expiry)) }" :aria-label="cellLabel(row.strike, expiry)"
                  :aria-pressed="row.strike === selectedStrike && expiry === selectedExpiry"
                  :data-selected="row.strike === selectedStrike && expiry === selectedExpiry"
                  :tabindex="row.strike === selectedStrike && expiry === selectedExpiry ? 0 : -1"
                  @click="selectCell(row.strike, expiry)" @keydown="moveCell($event, row.strike, expiry)">
                  <span aria-hidden="true">{{ !cellAt(row.strike, expiry) ? '—' : cellAt(row.strike, expiry).net_gex > 0 ? '+' : cellAt(row.strike, expiry).net_gex < 0 ? '−' : '=' }}</span>
                </button>
              </template>
            </div>
          </div>
          <div class="legend"><span><i class="swatch negative"/> Negative</span><span><i class="swatch positive"/> Positive</span><span>Brighter = larger exposure · Max |GEX| {{ money(maxMagnitude).replace('+', '') }}</span></div>
          <div class="map-navigation">
            <div><button type="button" :disabled="strikeStart === 0" @click="pageStrikes(-1)">Lower strikes</button><button type="button" :disabled="strikeStart + ROWS >= strikes.length" @click="pageStrikes(1)">Higher strikes</button><small>{{ strikeStart + 1 }}–{{ Math.min(strikes.length, strikeStart + ROWS) }} of {{ strikes.length }} strikes</small></div>
            <div><button type="button" :disabled="expiryStart === 0" @click="pageExpiries(-1)">Earlier expiries</button><button type="button" :disabled="expiryStart + COLS >= expiries.length" @click="pageExpiries(1)">Later expiries</button><small>{{ expiryStart + 1 }}–{{ Math.min(expiries.length, expiryStart + COLS) }} of {{ expiries.length }} expirations</small></div>
          </div>
        </div>
        <aside v-if="selected" class="inspector" aria-label="Selected exposure details">
          <p class="eyebrow">Strike {{ price(selected.strike) }} · {{ dateLabel(selectedExpiry) }}</p>
          <h3>Selected expiration</h3>
          <p class="hero-number" :data-tone="tone(selectedCell?.net_gex)" aria-live="polite">{{ money(selectedCell?.net_gex) }}</p>
          <p class="small">Net GEX · USD per 1% move</p>
          <p v-if="!selectedCell" class="small">No contracts at this strike/expiry combination.</p>
          <div v-else class="leg-values"><span>Call GEX <strong>{{ money(selectedCell.call_gex) }}</strong></span><span>Put GEX <strong>{{ money(selectedCell.put_gex) }}</strong></span></div>
          <div class="strike-total"><span>All selected expirations at {{ price(selected.strike) }}</span><strong :data-tone="tone(selected.net_gex)">{{ money(selected.net_gex) }}</strong></div>
          <div class="concentrations">
            <article><span>Dominant expiry</span><strong>{{ pct(selected.wall_expiry_concentration) }}</strong><small>{{ selected.dominant_expiry ? dateLabel(selected.dominant_expiry) : '—' }}</small></article>
            <article><span>Expiring next</span><strong>{{ pct(selected.expiring_next_ratio) }}</strong><small>{{ dateLabel(data.next_expiry_dates[0]) }}</small></article>
          </div>
          <h4>What drives this strike?</h4><p class="small">Share of absolute net GEX at {{ price(selected.strike) }}.</p>
          <div class="expiry-contributions">
            <button v-for="cell in rankedExpiries" :key="cell.expiration" type="button" class="contribution" :aria-pressed="cell.expiration === selectedExpiry" @click="chooseStrike(selected.strike, cell.expiration)">
              <span>{{ dateLabel(cell.expiration) }}</span><strong>{{ cell.contribution_pct == null ? '—' : cell.contribution_pct.toFixed(1) + '%' }}</strong>
              <span class="contribution-track"><i :style="{ width: `${cell.contribution_pct ?? 0}%` }" :data-tone="tone(cell.net_gex)"/></span>
            </button>
          </div>
          <p class="small interpretation">A concentrated expiry can change the exposure picture as it rolls off. Concentration alone does not confirm a hold or break.</p>
        </aside>
      </div>
      <footer><p>Click a cell to inspect. Arrow keys move between strikes and expirations.</p><button type="button" @click="download">Export expiration map JSON</button></footer>
      <details class="all-readings" @toggle="tableOpen = $event.target.open"><summary>All strike and expiration readings · {{ data.summary.cell_count }}</summary>
        <div v-if="tableOpen"><div class="table-pager"><button type="button" :disabled="tablePage === 0" @click="tablePage--">Previous readings</button><span>Page {{ tablePage + 1 }} of {{ maxTablePage + 1 }}</span><button type="button" :disabled="tablePage === maxTablePage" @click="tablePage++">Next readings</button></div>
          <div class="table-scroll"><table><caption>GEX by strike and expiration · USD per 1% move</caption><thead><tr><th>Strike</th><th>Expiration</th><th>Call GEX</th><th>Put GEX</th><th>Net GEX</th><th>Share at strike</th></tr></thead><tbody><tr v-for="row in tableSlice" :key="`${row.strike}-${row.expiration}`"><td><button type="button" @click="chooseStrike(row.strike, row.expiration)">{{ price(row.strike) }}</button></td><td>{{ row.expiration }}</td><td>{{ money(row.call_gex) }}</td><td>{{ money(row.put_gex) }}</td><td :data-tone="tone(row.net_gex)">{{ money(row.net_gex) }}</td><td>{{ row.contribution_pct == null ? '—' : row.contribution_pct.toFixed(1) + '%' }}</td></tr></tbody></table></div>
        </div>
      </details>
    </template>
  </section>
</template>

<style scoped>
.mobile-reading{display:none}@media(max-width:1050px){.mobile-reading{display:grid;grid-template-columns:1fr auto;gap:5px;position:sticky;top:76px;z-index:5;margin-bottom:10px;padding:10px 12px;background:#1e2735;border:1px solid #56759a;border-radius:8px;font-size:12px;box-shadow:0 4px 12px #10151e66}.mobile-reading strong{font-size:18px}.mobile-reading small{grid-column:1/-1;color:#b6c6dc;font-size:11px}}
.expiry-map{padding:22px;border:1px solid #364254;border-radius:14px;background:#171d28;color:#e8edf5;min-width:0}.map-header,.map-context,.toolbar,footer,.shortcuts,.legend,.map-navigation,.table-pager{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}.map-header{align-items:flex-start}h2{font-size:22px;font-weight:700;margin:4px 0 7px}p{font-size:13px;color:#b6c6dc;line-height:1.6}.eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:#9cbbdf}.map-context{font-size:12px;color:#b6c6dc;margin:20px 0 14px;padding-top:14px;border-top:1px solid #364254}.map-layout{display:grid;grid-template-columns:minmax(0,1fr) 310px;gap:20px}.map-chart,.inspector{min-width:0}.toolbar{margin-bottom:12px}label{font-size:12px;color:#b6c6dc;display:flex;align-items:center;gap:9px}select{background:#243146;border:1px solid #455775;border-radius:8px;color:#e8edf5;min-width:100px;font-size:13px}.shortcuts{gap:6px;justify-content:flex-start}button{border:1px solid #455775;border-radius:7px;padding:8px 10px;background:#243146;color:#dce8f8;font-size:12px;transition:background-color .15s ease,border-color .15s ease}button:hover{background:#30435d}button:focus-visible,summary:focus-visible,select:focus-visible{outline:2px solid #a6cdff;outline-offset:3px}button:disabled{opacity:.4;cursor:default}.put{color:#efa391}.call{color:#7edcaf}.map-scroll{overflow-x:auto;border:1px solid #364254;border-radius:10px;padding:10px;background:#131a25}.heatmap{display:grid;grid-template-columns:68px repeat(var(--columns),minmax(48px,1fr));gap:4px;min-width:max-content}.axis-date,.axis-corner,.axis-strike{font-size:11px;color:#b6c6dc;display:flex;align-items:center;justify-content:center;height:25px;font-variant-numeric:tabular-nums}.axis-corner{font-size:10px;justify-content:flex-start}.axis-strike{justify-content:flex-end;padding-right:12px}.axis-strike.active{color:#fff;font-weight:700}.heat-cell{height:23px;padding:0;border:1px solid transparent;border-radius:3px;color:#dce8f8;font-size:11px}.heat-cell:hover{border-color:#c3d5ec}.heat-cell[data-selected=true]{border-color:#fff;box-shadow:inset 0 0 0 1px #fff}.legend{justify-content:flex-start;font-size:11px;color:#b6c6dc;margin:12px 0}.legend span{display:flex;align-items:center;gap:6px}.swatch{width:9px;height:9px;display:inline-block;border-radius:2px}.positive{background:#7edcaf}.negative{background:#efa391}.map-navigation{margin-top:12px;gap:10px}.map-navigation>div{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.map-navigation small{display:block;flex-basis:100%;color:#b6c6dc;font-size:11px;margin-top:4px}.inspector{padding:18px;background:#1e2735;border:1px solid #435269;border-radius:12px;align-self:start}h3{font-size:15px;margin-top:7px;font-weight:600}.hero-number{font-size:32px;line-height:1.25;font-weight:700;margin-top:12px;color:#a6cdff;font-variant-numeric:tabular-nums}.small{font-size:11px}.leg-values{display:flex;gap:12px;margin:14px 0;color:#b6c6dc;font-size:11px}.leg-values span{flex:1}.leg-values strong{display:block;color:#e8edf5;margin-top:4px;font-size:13px}.strike-total{display:flex;justify-content:space-between;align-items:center;gap:10px;border-block:1px solid #435269;padding:14px 0;margin-top:14px;font-size:11px;color:#b6c6dc}.strike-total strong{font-size:18px;white-space:nowrap}.concentrations{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:18px 0}.concentrations span,.concentrations small{display:block;color:#b6c6dc;font-size:11px}.concentrations strong{display:block;color:#a6cdff;font-size:25px;margin:5px 0}.concentrations small{font-size:12px}h4{font-size:13px;font-weight:600}.expiry-contributions{max-height:180px;overflow-y:auto;margin-top:10px;padding-right:4px}.contribution{display:grid;width:100%;grid-template-columns:1fr auto;gap:5px;padding:7px 4px;background:transparent;border-color:transparent;font-size:11px;text-align:left}.contribution[aria-pressed=true]{background:#29384b;border-color:#56759a}.contribution-track{grid-column:1/-1;height:4px;background:#374252;border-radius:2px;overflow:hidden}.contribution-track i{height:100%;display:block;background:#9abdeb}.contribution-track i[data-tone=positive]{background:#7edcaf}.contribution-track i[data-tone=negative]{background:#efa391}.interpretation{margin-top:14px}footer{margin:14px 0}footer p{font-size:12px}.all-readings{border-top:1px solid #364254;padding-top:6px}summary{padding:10px 0;font-size:13px;color:#a6cdff;cursor:pointer}.table-pager{justify-content:flex-start;margin:10px 0;font-size:12px}.table-scroll{overflow:auto;max-height:430px}table{width:100%;font-size:12px;border-collapse:collapse;white-space:nowrap}caption{text-align:left;color:#b6c6dc;padding:8px 0}th,td{padding:9px 12px;border-bottom:1px solid #364254;text-align:right}th:first-child,td:first-child,th:nth-child(2),td:nth-child(2){text-align:left}th{color:#b6c6dc;background:#1e2735;position:sticky;top:0}tr:nth-child(even){background:#1e2735}[data-tone=positive]{color:#7edcaf}[data-tone=negative]{color:#efa391}.guide p+p{margin-top:14px}.map-loading,.map-error{margin:20px 0;color:#b6c6dc;font-size:13px}.loading-line{display:block;height:3px;width:120px;margin-bottom:16px;background:#93bdfa;animation:map-pulse 1.5s ease-in-out infinite}@keyframes map-pulse{50%{opacity:.35}}@media(max-width:1050px){.map-layout{grid-template-columns:1fr}.inspector{display:grid;grid-template-columns:1fr 1fr;column-gap:22px}.inspector>.eyebrow,.inspector>h3,.inspector>.interpretation{grid-column:1/-1}.strike-total,.concentrations{grid-column:1/-1}.expiry-contributions{grid-column:1/-1}.inspector>h4{grid-column:1/-1}}@media(max-width:600px){.expiry-map{padding:14px}.shortcuts button{font-size:11px;padding:8px}.inspector{display:block}.map-context{gap:5px}.map-navigation{align-items:flex-start}.map-navigation button{font-size:11px}}@media(prefers-reduced-motion:reduce){button{transition:none}.loading-line{animation:none}}
</style>
