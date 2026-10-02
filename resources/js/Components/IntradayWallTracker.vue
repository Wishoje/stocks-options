<template>
  <section class="wall-tracker gex-panel" aria-label="Intraday wall timeline" :aria-busy="loading">
    <header class="wall-tracker__header">
      <div>
        <p class="wall-tracker__eyebrow">{{ symbol }} · Intraday modeled walls</p>
        <h2>How the walls move</h2>
        <p>Follow put and call wall levels through the session.</p>
      </div>
      <div class="wall-tracker__actions">
        <button v-if="showEodLink" type="button" @click="$emit('open-eod')">Explore EOD wall analysis</button>
        <button type="button" :disabled="loading || refreshing" @click="load">{{ loading ? 'Loading…' : refreshing ? 'Refreshing…' : 'Refresh timeline' }}</button>
        <button v-if="data?.segments.length" type="button" @click="download">Export timeline JSON</button>
      </div>
    </header>

    <fieldset class="wall-tracker__scope">
      <legend>Expiry scope</legend>
      <div class="wall-tracker__actions" aria-label="Wall tracking expiry scope">
        <button v-for="option in scopeOptions" :key="option.value" type="button" :aria-pressed="scope === option.value" @click="chooseScope(option.value)">{{ option.label }}</button>
      </div>
      <p>Choose which option expirations shape the walls. The timeline follows their movement during the selected session.</p>
    </fieldset>

    <p v-if="demo" class="wall-tracker__demo" role="status">Local demonstration · Synthetic prices and contracts. These are example readings for reviewing the interface.</p>
    <p v-if="error" role="alert">{{ error }}</p>
    <div v-if="loading" class="wall-tracker__loading" role="status">Loading {{ symbol }} wall history…</div>
    <template v-else-if="data">
      <div class="wall-tracker__context">
        <span v-if="!demo && data.quote_delay_seconds > 0">Quotes delayed {{ Math.round(data.quote_delay_seconds / 60) }} min · Times shown are market times</span>
        <label v-if="data.sessions?.length">Session
          <select aria-label="Wall timeline session" :value="data.session" @change="chooseSession($event.target.value)">
            <option v-for="date in data.sessions" :key="date" :value="date">{{ date }}</option>
          </select>
        </label>
        <span v-if="sessionLatest">As of {{ time(sessionLatest.observed_at) }} ET · {{ sessionReadingCount }} {{ sessionReadingCount === 1 ? 'reading' : 'readings' }} this session</span>
        <button v-if="data.local_demo_available" type="button" @click="chooseDemo(!demo)">{{ demo ? 'Return to recorded sessions' : 'Preview local demonstration' }}</button>
      </div>
      <div v-if="!data.segments.length" class="wall-tracker__empty" role="status">
        <template v-if="data.available_scopes?.length">
          <h3>No readings recorded for {{ symbol }} · {{ scopeLabel(scope) }}{{ data.session ? ` · ${data.session}` : '' }}</h3>
          <p>Open a recorded scope below. Each scope follows a different set of option expirations.</p>
          <div class="wall-tracker__recorded-scopes" aria-label="Recorded wall scopes">
            <button v-for="option in data.available_scopes" :key="option.timeframe" type="button" @click="openRecordedScope(option)">
              <strong>Open {{ scopeLabel(option.timeframe) }}</strong>
              <span>{{ option.readings }} {{ option.readings === 1 ? 'reading' : 'readings' }} · {{ option.session }}</span>
            </button>
          </div>
        </template>
        <p v-else>{{ data.availability?.message || `No wall readings have been recorded yet for ${symbol}. Eligible symbols are checked every five minutes during market sessions.` }}</p>
      </div>
      <template v-else>
        <p class="wall-tracker__model">Modeled with changing price and time. Prior-session open interest and IV stay fixed.</p>
        <p v-if="data.truncated">Showing the latest 500 readings for this session.</p>
        <div v-if="data.segments.length > 1" class="wall-tracker__context">
          <label>Comparison window
            <select v-model.number="segmentIndex" aria-label="Wall comparison window">
              <option v-for="(item, index) in data.segments" :key="item.id" :value="index">{{ time(item.observations[0].observed_at) }} ET · {{ reason(item.start_reason) }}</option>
            </select>
          </label>
          <span>Each window compares the same inputs. Lines do not bridge changes or long pauses.</span>
        </div>
        <div class="wall-tracker__metrics">
          <article v-for="side in sides" :key="side" :class="`wall-tracker__metric wall-tracker__metric--${side}`">
            <h3>{{ side === 'put' ? 'Put' : 'Call' }} wall</h3>
            <strong>{{ number(selected?.walls[side]?.[0]?.strike) }}</strong>
            <p>{{ migrationLabel(segment?.migration[side]) }}</p>
            <small>Net migration in this comparison window</small>
          </article>
          <article class="wall-tracker__metric">
            <h3>Price at {{ time(selected?.observed_at) }} ET</h3>
            <strong>{{ number(selected?.spot, 2) }}</strong>
            <p>Net modeled GEX {{ compact(selected?.net_gex) }}</p>
            <small>USD per 1% price move</small>
          </article>
        </div>
        <div class="wall-tracker__chart">
          <div class="wall-tracker__legend"><span class="put">● Put wall</span><span class="call">● Call wall</span><span class="price">— Price</span></div>
          <p v-if="data.segments.length > 1">Showing {{ observations.length }} of {{ sessionReadingCount }} session readings in this comparison window.</p>
          <p v-if="observations.length === 1" role="status">One reading in this window. The dots show the current levels; lines appear after another reading.</p>
          <svg ref="chartElement" class="wall-tracker__plot" :viewBox="`0 0 ${chartWidth} 260`" role="img" :aria-label="`Put and call wall levels for ${symbol}; select a reading below for values`">
            <g v-for="tick in ticks" :key="tick">
              <line x1="65" :y1="y(tick)" :x2="chartWidth - 20" :y2="y(tick)" stroke="currentColor" opacity=".14" />
              <text x="55" :y="y(tick) + 4" text-anchor="end">{{ number(tick, 1) }}</text>
            </g>
            <path :d="path('spot')" fill="none" stroke="#93bdfa" stroke-width="2" stroke-dasharray="5 4" />
            <path v-for="side in sides" :key="side" :d="path(side)" fill="none" :stroke="side === 'put' ? '#ef9d8f' : '#7ee0b0'" stroke-width="3" />
            <line :x1="x(selectedIndex)" y1="20" :x2="x(selectedIndex)" y2="225" stroke="#dbe6f6" stroke-dasharray="3 4" opacity=".6" />
            <template v-for="side in sides" :key="`${side}-point`">
              <circle v-if="selected?.walls[side]?.[0]" :cx="x(selectedIndex)" :cy="y(selected.walls[side][0].strike)" r="5" :fill="side === 'put' ? '#ef9d8f' : '#7ee0b0'" />
            </template>
            <circle v-if="Number.isFinite(selected?.spot)" class="wall-tracker__price-point" :cx="x(selectedIndex)" :cy="y(selected.spot)" r="6" fill="#1b2230" stroke="#93bdfa" stroke-width="2"><title>Price {{ number(selected.spot, 2) }}</title></circle>
            <g v-for="event in visibleEvents" :key="event.id" :class="['wall-tracker__event', event.side]" role="button" tabindex="0"
              :aria-label="`${interactionTitle(event.status)}, ${event.side} wall ${number(event.strike)}, ${time(event.observed_at)} ET`"
              aria-haspopup="dialog" :aria-expanded="previewEvent?.id === event.id" :aria-controls="previewEvent?.id === event.id ? 'wall-event-summary' : undefined"
              @click="previewInteraction(event, $event)" @keydown.enter.prevent="previewInteraction(event, $event)" @keydown.space.prevent="previewInteraction(event, $event)">
              <circle :cx="eventX(event)" :cy="y(event.strike)" r="12" fill="#182537" stroke="currentColor" stroke-width="2" />
              <WallInteractionIcon :status="event.status" :x="eventX(event) - 9" :y="y(event.strike) - 9" width="18" height="18" />
            </g>
            <text v-if="observations.length === 1" :x="x(0)" y="251" text-anchor="middle">{{ time(latest?.observed_at) }} ET</text>
            <template v-else>
              <text x="65" y="251">{{ time(observations[0]?.observed_at) }} ET</text>
              <text :x="chartWidth - 20" y="251" text-anchor="end">{{ time(latest?.observed_at) }} ET</text>
            </template>
          </svg>
          <ul v-if="eventLegend.length" class="wall-tracker__event-legend" aria-label="Price event symbols">
            <li v-for="status in eventLegend" :key="status"><WallInteractionIcon :status="status" width="20" height="20" />{{ interactionTitle(status) }}</li>
          </ul>
          <p v-if="visibleEvents.length" class="wall-tracker__event-help">Click an icon for a summary. Coral marks put-wall events; green marks call-wall events. The selector below includes every event.</p>
          <label class="wall-tracker__scrubber">Inspect reading <strong>{{ time(selected?.observed_at) }} ET</strong>
            <input v-model.number="selectedIndex" type="range" min="0" :max="Math.max(0, observations.length - 1)" :disabled="observations.length < 2" aria-label="Inspect wall reading" :aria-valuetext="`${time(selected?.observed_at)} ET, put ${number(selected?.walls.put?.[0]?.strike)}, call ${number(selected?.walls.call?.[0]?.strike)}`" />
          </label>
        </div>
        <p class="wall-tracker__interpretation">The leading wall strike can stay unchanged while price and exposure change. Price can trade above or below either wall. A stable wall is an area to monitor, not confirmation of support or resistance. Migration shows a change in the leading strike; it does not confirm a breakout.</p>
        <WallInteractionPanel v-if="data.wall_interaction" ref="interactionPanel" :interaction="data.wall_interaction" :selected-event-id="selectedEventId" @inspect="inspectInteraction" />
        <details>
          <summary>Session readings and model details</summary>
          <p>{{ data.model_description }}</p>
          <p>Open interest and IV date: {{ latest?.provenance.oi_date }}. Each expiry uses its own remaining time to the modeled regular-session close.</p>
          <div class="wall-tracker__table"><table>
            <caption>Selected comparison window · {{ observations.length }} {{ observations.length === 1 ? 'reading' : 'readings' }} · Times in ET</caption>
            <thead><tr><th>Time</th><th>Price</th><th>Put wall</th><th>Call wall</th><th>Net GEX / 1%</th></tr></thead>
            <tbody><tr v-for="(row, index) in observations" :key="row.observed_at" :class="{ selected: index === selectedIndex }">
              <td><button type="button" @click="selectedIndex = index">{{ time(row.observed_at) }}</button></td><td>{{ number(row.spot, 2) }}</td>
              <td>{{ number(row.walls.put?.[0]?.strike) }}</td><td>{{ number(row.walls.call?.[0]?.strike) }}</td><td>{{ compact(row.net_gex) }}</td>
            </tr></tbody>
          </table></div>
        </details>
      </template>
    </template>
    <Teleport to="body">
      <div v-if="previewEvent" id="wall-event-summary" ref="eventSummary" class="wall-event-summary" role="dialog" aria-modal="false"
        aria-labelledby="wall-event-summary-title" aria-describedby="wall-event-summary-reason" :style="summaryPosition" tabindex="-1">
        <header>
          <span :class="previewEvent.side">{{ previewEvent.side === 'put' ? 'Put' : 'Call' }} wall {{ number(previewEvent.strike) }} · {{ time(previewEvent.observed_at) }} ET</span>
          <button type="button" aria-label="Close event summary" @click="closePreview(true)">×</button>
        </header>
        <h3 id="wall-event-summary-title"><WallInteractionIcon :status="previewEvent.status" width="22" height="22" />{{ interactionTitle(previewEvent.status) }}</h3>
        <p id="wall-event-summary-reason">{{ previewEvent.reason }}</p>
        <p class="wall-event-summary__close">Completed close <strong>{{ number(previewEvent.close, 2) }}</strong></p>
        <button type="button" class="wall-event-summary__evidence" @click="openPreviewEvidence">View evidence below <span aria-hidden="true">↓</span></button>
      </div>
    </Teleport>
  </section>
</template>

<script setup>
import { computed, ref, watch, onScopeDispose, nextTick } from 'vue'
import { useIntradayWalls } from '@/composables/useIntradayWalls'
import WallInteractionPanel from '@/Components/WallInteractionPanel.vue'
import WallInteractionIcon from '@/Components/WallInteractionIcon.vue'
import { wallInteractionTitle as interactionTitle } from '@/utils/wallInteraction'
const props = defineProps({ symbol: { type: String, required: true }, showEodLink: Boolean })
defineEmits(['open-eod'])
const scopeOptions = [{ value: '0d', label: '0DTE' }, { value: '1d', label: '1DTE' }, { value: '7d', label: '1W' }, { value: '14d', label: '2W' }, { value: '30d', label: '1M' }, { value: '90d', label: '3M' }]
const scopeLabel = value => scopeOptions.find(option => option.value === value)?.label || value
const readScope = () => {
  const value = new URLSearchParams(window.location.search).get('wall_timeframe')
  return scopeOptions.some(option => option.value === value) ? value : '14d'
}
const scope = ref(readScope())
function chooseScope(value) {
  scope.value = value
  const url = new URL(window.location.href)
  url.searchParams.set('wall_timeframe', value)
  window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash)
}
const restoreScope = () => { scope.value = readScope() }
window.addEventListener('popstate', restoreScope)
onScopeDispose(() => window.removeEventListener('popstate', restoreScope))
const { data, loading, refreshing, error, demo, load, chooseSession, chooseDemo } = useIntradayWalls(() => props.symbol, () => scope.value)
function openRecordedScope(option) {
  // A different scope loads its latest recorded session. The same scope can
  // also offer a newer session when the user chose a date without readings.
  if (scope.value === option.timeframe) chooseSession(option.session)
  else chooseScope(option.timeframe)
}
const sides = ['put', 'call'], segmentIndex = ref(0), selectedIndex = ref(0)
const chartElement = ref(null), chartWidth = ref(840)
let observer
watch(chartElement, element => {
  observer?.disconnect()
  if (!element || typeof ResizeObserver === 'undefined') return
  const resize = () => { chartWidth.value = Math.max(240, element.getBoundingClientRect().width) }
  resize()
  observer = new ResizeObserver(resize)
  observer.observe(element)
}, { flush: 'post' })
onScopeDispose(() => observer?.disconnect())
const segment = computed(() => data.value?.segments[segmentIndex.value])
const observations = computed(() => segment.value?.observations || [])
const latest = computed(() => observations.value.at(-1))
const selected = computed(() => observations.value[selectedIndex.value])
const sessionReadingCount = computed(() => data.value?.segments.reduce((total, item) => total + item.observations.length, 0) || 0)
const sessionLatest = computed(() => data.value?.segments.at(-1)?.observations.at(-1))
const selectedEventId = ref(null)
const interactionPanel = ref(null)
const previewId = ref(null), eventSummary = ref(null), summaryPosition = ref({ visibility: 'hidden' })
const previewEvent = computed(() => data.value?.wall_interaction?.events?.find(event => event.id === previewId.value))
let previewTrigger
async function previewInteraction(event, input) {
  if (previewId.value === event.id) { closePreview(true); return }
  previewTrigger = input.currentTarget
  previewId.value = event.id
  summaryPosition.value = { visibility: 'hidden' }
  await nextTick()
  if (!eventSummary.value || !previewTrigger?.isConnected) return
  const anchor = previewTrigger.getBoundingClientRect(), box = eventSummary.value.getBoundingClientRect()
  const viewport = { width: document.documentElement.clientWidth, height: window.innerHeight }
  const left = Math.max(12, Math.min(anchor.left + anchor.width / 2 - box.width / 2, viewport.width - box.width - 12))
  const above = anchor.top - box.height - 12
  const top = Math.max(12, Math.min(above >= 12 ? above : anchor.bottom + 12, viewport.height - box.height - 12))
  summaryPosition.value = { left: `${left}px`, top: `${top}px` }
  await nextTick()
  eventSummary.value?.focus({ preventScroll: true })
}
function closePreview(restoreFocus = false) {
  previewId.value = null
  if (restoreFocus && previewTrigger?.isConnected) previewTrigger.focus({ preventScroll: true })
}
function dismissPreview(input) {
  if (!previewId.value) return
  if (input.type === 'keydown') {
    if (input.key === 'Escape') { input.preventDefault(); closePreview(true) }
    return
  }
  if (!eventSummary.value?.contains(input.target) && !previewTrigger?.contains(input.target)) closePreview()
}
const closePreviewOnMove = () => closePreview()
document.addEventListener('pointerdown', dismissPreview)
document.addEventListener('focusin', dismissPreview)
document.addEventListener('keydown', dismissPreview)
window.addEventListener('scroll', closePreviewOnMove, { passive: true })
window.addEventListener('resize', closePreviewOnMove)
onScopeDispose(() => {
  document.removeEventListener('pointerdown', dismissPreview)
  document.removeEventListener('focusin', dismissPreview)
  document.removeEventListener('keydown', dismissPreview)
  window.removeEventListener('scroll', closePreviewOnMove)
  window.removeEventListener('resize', closePreviewOnMove)
})
async function openPreviewEvidence() {
  const event = previewEvent.value
  closePreview()
  if (!event) return
  await inspectInteraction(event, true)
  interactionPanel.value?.$el.querySelector('.evidence')?.focus({ preventScroll: true })
}
watch([scope, segmentIndex, loading], () => closePreview())
const eventLegend = computed(() => [...new Set(visibleEvents.value.map(event => event.status))])
const visibleEvents = computed(() => {
  const candidates = (data.value?.wall_interaction?.events || []).filter(event =>
    Date.parse(event.observed_at) >= Date.parse(observations.value[0]?.observed_at)
    && Date.parse(event.observed_at) <= Date.parse(latest.value?.observed_at)
    && event.strike >= range.value.min && event.strike <= range.value.max)
  // Keep touch targets apart on small charts. The event selector retains every event.
  const visible = []
  for (const event of [...candidates].reverse()) {
    if (!visible.some(other => Math.abs(eventX(other) - eventX(event)) < 29 && Math.abs(y(other.strike) - y(event.strike)) < 29)) visible.unshift(event)
  }
  return visible
})
const eventX = event => {
  const start = Date.parse(observations.value[0]?.observed_at), end = Date.parse(latest.value?.observed_at)
  return start === end ? (chartWidth.value + 45) / 2 : 65 + (Date.parse(event.observed_at) - start) / (end - start) * (chartWidth.value - 85)
}
async function inspectInteraction(event, scroll = false) {
  selectedEventId.value = event?.id || null
  await nextTick()
  if (scroll && interactionPanel.value?.$el) {
    const contextBottom = document.querySelector('.gex-dashboard-context')?.getBoundingClientRect().bottom || 68
    const top = window.scrollY + interactionPanel.value.$el.getBoundingClientRect().top - contextBottom - 16
    window.scrollTo({ top: Math.max(0, top), behavior: 'auto' })
  }
}
watch(segmentIndex, () => { selectedIndex.value = Math.max(0, observations.value.length - 1) }, { flush: 'sync' })
watch(data, (value, previous) => {
  const oldWindow = previous?.segments[segmentIndex.value]
  const oldReading = oldWindow?.observations[selectedIndex.value]
  const sameContext = previous && value && ['symbol', 'session', 'timeframe', 'dataset'].every(key => previous[key] === value[key])
  if (!sameContext || !value?.wall_interaction?.events?.some(event => event.id === selectedEventId.value)) selectedEventId.value = null
  if (!sameContext || !value?.wall_interaction?.events?.some(event => event.id === previewId.value)) closePreview()
  const followingLatest = segmentIndex.value === previous?.segments.length - 1 && selectedIndex.value === oldWindow?.observations.length - 1
  const preservedWindow = sameContext && !followingLatest
    ? value.segments.findIndex(item => item.id === oldWindow?.id && item.observations[0]?.observed_at === oldWindow?.observations[0]?.observed_at) : -1
  segmentIndex.value = preservedWindow >= 0 ? preservedWindow : Math.max(0, (value?.segments.length || 1) - 1)
  const preservedReading = preservedWindow >= 0 ? observations.value.findIndex(item => item.observed_at === oldReading?.observed_at) : -1
  selectedIndex.value = preservedReading >= 0 ? preservedReading : Math.max(0, observations.value.length - 1)
})
const number = (value, digits = 0) => Number.isFinite(value) ? value.toLocaleString('en-US', { maximumFractionDigits: digits }) : '—'
const compact = value => Number.isFinite(value) ? value.toLocaleString('en-US', { notation: 'compact', maximumFractionDigits: 2, signDisplay: 'exceptZero' }) : '—'
const time = value => value ? new Date(value).toLocaleTimeString('en-US', { timeZone: 'America/New_York', hour: 'numeric', minute: '2-digit' }) : '—'
const reason = value => ({ first_observation: 'First recorded reading', scope_changed: 'Expiry scope changed', inputs_changed: 'Model inputs updated', observation_gap: 'New observations after a pause', same_time_revision: 'Revised reading', quote_source_changed: 'Price basis changed' }[value] || 'New comparison')
const migrationLabel = migration => migration?.amount == null ? 'Building the comparison' : migration.amount === 0 ? 'Unchanged · 0 points' : `${migration.amount > 0 ? '↑ Up' : '↓ Down'} ${number(Math.abs(migration.amount), 2)} points`
const range = computed(() => {
  const values = observations.value.flatMap(row => [row.spot, ...sides.map(side => row.walls[side]?.[0]?.strike)]).filter(Number.isFinite)
  const min = values.length ? Math.min(...values) : 0, max = values.length ? Math.max(...values) : 1
  const pad = Math.max((max - min) * .15, 1)
  return { min: min - pad, max: max + pad }
})
const ticks = computed(() => Array.from({ length: 5 }, (_, index) => range.value.min + index * (range.value.max - range.value.min) / 4))
// Position readings by elapsed time, not by array index.
const x = index => {
  const start = Date.parse(observations.value[0]?.observed_at), end = Date.parse(latest.value?.observed_at)
  return !Number.isFinite(start) || end === start ? (65 + chartWidth.value - 20) / 2 : 65 + (Date.parse(observations.value[index]?.observed_at) - start) / (end - start) * (chartWidth.value - 85)
}
const y = value => 225 - (value - range.value.min) / (range.value.max - range.value.min) * 205
function path(side) {
  let previous = null
  return observations.value.map((row, index) => {
    const value = side === 'spot' ? row.spot : row.walls[side]?.[0]?.strike
    if (!Number.isFinite(value)) { previous = null; return '' }
    const point = previous === null ? `M ${x(index)} ${y(value)}` : side === 'spot' ? `L ${x(index)} ${y(value)}` : `H ${x(index)} V ${y(value)}`
    previous = value
    return point
  }).join(' ')
}
function download() {
  const blob = new Blob([JSON.stringify({ intraday_wall_history: data.value }, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob), link = document.createElement('a')
  link.href = url; link.download = `${props.symbol}-wall-history-${data.value.session}${demo.value ? '-demo' : ''}.json`
  link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000)
}
</script>

<style scoped>
.wall-tracker__event{cursor:pointer}.wall-tracker__event:focus-visible{outline:2px solid #a2ceff;outline-offset:3px}.wall-tracker__event:hover circle,.wall-tracker__event[aria-expanded=true] circle{fill:#30445e;stroke-width:3}
.wall-tracker__event-legend{display:flex;flex-wrap:wrap;gap:8px 18px;list-style:none;margin:6px 0 8px;padding:0;color:#d2deed;font-size:.76rem}.wall-tracker__event-legend li{display:flex;align-items:center;gap:6px}.wall-tracker__event-help{font-size:.78rem;margin-bottom:12px}
.wall-event-summary{position:fixed;z-index:1000;width:320px;max-width:calc(100vw - 24px);max-height:calc(100dvh - 24px);overflow:auto;border:1px solid #657b98;border-radius:12px;background:#192434;color:#edf3fc;padding:14px;box-shadow:0 12px 36px #0008;outline:none}.wall-event-summary header{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:.78rem}.wall-event-summary header button{font-size:1.2rem;line-height:1;padding:4px 8px}.wall-event-summary h3{display:flex;align-items:center;gap:8px;font-size:1rem;font-weight:700;margin:12px 0 8px}.wall-event-summary h3 svg{flex-shrink:0}.wall-event-summary p{font-size:.82rem;line-height:1.5}.wall-event-summary__close{display:flex;justify-content:space-between;gap:12px;margin:12px 0;color:#bfccde}.wall-event-summary__close strong{color:#edf3fc;font-variant-numeric:tabular-nums}.wall-event-summary__evidence{width:100%;text-align:left;display:flex;justify-content:space-between}
.wall-tracker__empty h3{font-size:1rem;font-weight:650;margin-bottom:6px}.wall-tracker__recorded-scopes{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px}.wall-tracker__recorded-scopes button{text-align:left;display:grid;gap:5px;min-width:180px;padding:12px 16px;border-color:#526f93}.wall-tracker__recorded-scopes strong{color:#a7ceff;font-size:.95rem}.wall-tracker__recorded-scopes span{color:#bfccdd;font-size:.78rem}
.wall-tracker__scope{margin:20px 0 12px;min-width:0}.wall-tracker__scope legend{font-size:.8rem;font-weight:650;margin-bottom:8px}.wall-tracker__scope .wall-tracker__actions{justify-content:flex-start;gap:6px}.wall-tracker__scope button[aria-pressed="true"]{background:#304869;border-color:#93bdfa;color:#fff}.wall-tracker__scope p{margin-top:8px;font-size:.78rem}
.wall-tracker{padding:clamp(16px,2vw,26px);margin-bottom:20px;border:1px solid #354052;border-radius:16px;background:#171c27;color:#e7edf6}
.wall-tracker__header,.wall-tracker__actions,.wall-tracker__context,.wall-tracker__legend{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
h2{font-size:1.2rem;font-weight:700;margin:3px 0 7px}p,small{color:#b8c5d8}p{font-size:.88rem;line-height:1.6}button,select{border:1px solid #43516a;border-radius:8px;background:#242d3e;color:#e7edf6;padding:8px 12px;font-size:.82rem}button:hover{background:#303e54}button:focus-visible,select:focus-visible,input:focus-visible{outline:2px solid #93bdfa;outline-offset:3px}button:disabled{opacity:.55;cursor:wait}.wall-tracker__eyebrow{text-transform:uppercase;letter-spacing:.08em;font-size:.7rem;color:#99b5d8}.wall-tracker__context{justify-content:flex-start;margin:18px 0;font-size:.8rem;color:#b8c5d8}.wall-tracker__context label{display:flex;gap:10px;align-items:center}.wall-tracker__demo{border-left:3px solid #e6c86f;background:#302d22;padding:12px;margin-top:16px;color:#f2df9d}.wall-tracker__model{margin:14px 0}.wall-tracker__metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.wall-tracker__metric{border:1px solid #354052;border-top:2px solid #93bdfa;border-radius:12px;padding:18px}.wall-tracker__metric h3{font-size:.8rem;color:#bdcce0}.wall-tracker__metric strong{display:block;font-size:1.9rem;font-weight:750;margin:8px 0;color:#93bdfa}.wall-tracker__metric--put{border-top-color:#ef9d8f}.wall-tracker__metric--put strong,.put{color:#ef9d8f}.wall-tracker__metric--call{border-top-color:#7ee0b0}.wall-tracker__metric--call strong,.call{color:#7ee0b0}.wall-tracker__metric small{font-size:.72rem}.wall-tracker__chart{margin-top:20px;border:1px solid #354052;border-radius:12px;padding:16px;background:#1b2230}.wall-tracker__legend{justify-content:flex-start;font-size:.8rem;gap:20px}.price{color:#93bdfa}.wall-tracker__plot{width:100%;height:260px;margin-top:12px}svg text{fill:#b8c5d8;font-size:11px}.wall-tracker__scrubber{display:flex;align-items:center;flex-wrap:wrap;gap:10px;font-size:.8rem}.wall-tracker__scrubber strong{margin-left:auto}.wall-tracker__scrubber input{width:100%;accent-color:#93bdfa;margin-top:8px;min-height:28px}.wall-tracker__interpretation{margin:16px 0}.wall-tracker__table{overflow:auto;margin-top:16px}table{width:100%;border-collapse:collapse;white-space:nowrap;font-size:.8rem}caption{text-align:left;color:#b8c5d8;padding:8px 0}th,td{text-align:right;padding:10px;border-bottom:1px solid #354052}th:first-child,td:first-child{text-align:left}.selected{background:#25344b}summary{cursor:pointer;font-size:.85rem;padding:12px 0}details p{margin:10px 0}.wall-tracker__empty,.wall-tracker__loading{padding:24px 0}@media(max-width:640px){.wall-tracker__metrics{grid-template-columns:1fr}.wall-tracker__metric strong{font-size:1.65rem}.wall-tracker__header{align-items:flex-start}.wall-tracker__actions{justify-content:flex-start}.wall-tracker__plot{height:260px}.wall-tracker__context select{max-width:240px}}
</style>
