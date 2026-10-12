<script setup>
import { computed, ref, watch, onScopeDispose } from 'vue'
import { useWallWatchlist } from '@/composables/useWallWatchlist'
import { wallInteractionTitle } from '@/utils/wallInteraction'

const props = defineProps({ selectedSymbol: String, chartHref: Function })
const emit = defineEmits(['open'])
const { data, loading, refreshing, error, demo, load, chooseDemo } = useWallWatchlist()
const setupsOnly = ref(false), limit = ref(6)
// A retained response cannot keep an expired assessment on screen between polls.
const clock = ref(Date.now())
const timer = setInterval(() => { clock.value = Date.now() }, 10000)
onScopeDispose(() => clearInterval(timer))
const items = computed(() => (data.value?.items || []).filter(row => {
  if (demo.value) return true
  const cutoff = clock.value - (data.value.quote_delay_seconds * 1000 + 30000)
  const at = Date.parse(row.observed_at)
  return Date.parse(data.value.refresh_until) > clock.value && at <= cutoff && cutoff - at <= 600000
    && (row.status !== 'conditions_met' || Date.parse(row.setup?.expires_at) >= cutoff)
}))
const qualifiedCount = computed(() => items.value.filter(row => row.status === 'conditions_met').length)
const visible = computed(() => (setupsOnly.value ? items.value.filter(row => row.status === 'conditions_met') : items.value).slice(0, limit.value))
const total = computed(() => setupsOnly.value ? qualifiedCount.value : items.value.length)
const time = value => new Date(value).toLocaleTimeString('en-US', { timeZone: 'America/New_York', hour: 'numeric', minute: '2-digit' })
const number = value => Number.isFinite(value) ? value.toLocaleString('en-US', { maximumFractionDigits: 3 }) : '—'
const checks = row => (row.setup?.evidence_checks || []).filter(check => check.passed).map(check => ({
  ordered_price_confirmation: 'Price confirmed', wall_persistence: 'Wall stable', supported_geometry: 'Target room checked',
}[check.key])).filter(Boolean)
const closed = computed(() => data.value?.state === 'outside_session' || (!demo.value && data.value?.refresh_until && Date.parse(data.value.refresh_until) <= clock.value))
watch(setupsOnly, () => { limit.value = 6 })
watch(qualifiedCount, count => { if (!count) setupsOnly.value = false })
watch(() => data.value?.session, () => { setupsOnly.value = false; limit.value = 6 })
</script>

<template>
  <section class="wall-watchlist" aria-label="Watchlist wall activity" :aria-busy="loading || refreshing">
    <header><div><h3>Which symbols need attention?</h3><p>All saved symbols · 1W expiries · Prices delayed 15 min</p></div>
      <button type="button" :disabled="loading || refreshing" @click="load()">{{ refreshing ? 'Refreshing…' : 'Refresh watchlist' }}</button></header>
    <p v-if="loading" class="notice" role="status">Checking your watchlist’s wall assessments…</p>
    <p v-if="error" class="notice error" role="alert">{{ error }} <button type="button" @click="load()">Retry</button></p>
    <template v-if="data">
      <div v-if="demo" class="demo" role="status">Local demonstration · Synthetic historical examples <button type="button" @click="chooseDemo(false)">Back to my watchlist</button></div>
      <div v-else class="watchlist-summary"><strong>{{ qualifiedCount ? `${qualifiedCount} setup${qualifiedCount === 1 ? '' : 's'} with conditions met` : data.assessed_count ? 'No confirmed setups in your watchlist' : 'Waiting for current wall assessments' }}</strong>
        <span>{{ data.assessed_count }} of {{ data.watchlist_count }} symbols assessed · {{ data.session }}</span></div>
      <div v-if="qualifiedCount" class="filters" aria-label="Wall activity filter">
        <button type="button" :aria-pressed="!setupsOnly" @click="setupsOnly = false">All activity</button>
        <button type="button" :aria-pressed="setupsOnly" @click="setupsOnly = true">Conditions met · {{ qualifiedCount }}</button>
      </div>
      <div v-if="visible.length" class="activity-list">
        <article v-for="row in visible" :key="`${row.symbol}:${row.side}`" :class="['activity-row', { selected: row.symbol === selectedSymbol, qualified: row.status === 'conditions_met' }]">
          <div class="activity-heading"><div><strong class="symbol">{{ row.symbol }}</strong><span :class="row.side">{{ row.side === 'put' ? 'Put' : 'Call' }} wall {{ number(row.strike) }}</span></div>
            <span class="observed-time">{{ time(row.observed_at) }} ET · Close {{ number(row.close) }}</span></div>
          <div class="activity-body"><div><strong>{{ row.status === 'conditions_met' ? 'Setup conditions met' : wallInteractionTitle(row.reaction) }}</strong>
            <p v-if="row.setup">{{ row.setup.direction === 'bullish' ? 'Higher-price' : 'Lower-price' }} scenario · {{ checks(row).join(' · ') }}</p>
            <p v-else>Price reaction only · Entry confirmation pending</p></div>
            <dl v-if="row.setup"><div><dt>Entry area</dt><dd>{{ number(row.setup.entry_zone.low) }}–{{ number(row.setup.entry_zone.high) }}</dd></div>
              <div><dt>Invalid if 5m close</dt><dd>{{ row.setup.invalidation.close_side }} {{ number(row.setup.invalidation.price) }}</dd></div>
              <div><dt>Reference target</dt><dd>{{ number(row.setup.target.strike) }}</dd></div></dl>
            <a v-if="chartHref" :href="chartHref({ ...row, demonstration: demo })" :aria-label="`Open ${row.symbol} wall chart`" class="chart-link">Open chart <span aria-hidden="true">→</span></a>
            <button v-else type="button" :aria-label="`Open ${row.symbol} wall chart`" @click="emit('open', { ...row, demonstration: demo })">Open chart <span aria-hidden="true">→</span></button>
          </div>
        </article>
      </div>
      <p v-else-if="!error" class="notice" role="status">{{ closed ? 'The market is closed. Current wall activity will appear during the next session.' : !data.watchlist_count ? 'Add symbols to your watchlist to follow their wall activity here.' : !data.assessed_count ? 'Waiting for today’s completed wall assessments. Your list updates automatically.' : 'No current wall reactions to highlight. You can still open any symbol’s chart below.' }}</p>
      <button v-if="total > limit" class="show-more" type="button" @click="limit += 6">Show more · {{ total - limit }} remaining</button>
      <p v-if="qualifiedCount" class="footnote">Conditions met refers to the recorded close. Check current price against the entry area before using a setup.</p>
      <button v-if="data.local_demo_available && !demo" class="preview" type="button" @click="chooseDemo(true)">Preview local demonstration</button>
    </template>
  </section>
</template>

<style scoped>
.wall-watchlist{margin-bottom:22px;padding:20px;border:1px solid #43536a;border-radius:14px;background:#171d29;color:#edf3fc}header,.activity-heading,.activity-heading>div,.watchlist-summary,.filters{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}h3{font-size:1.15rem;font-weight:700;margin:0 0 6px}p{font-size:.8rem;line-height:1.5;color:#bfccde;margin:5px 0}button{border:1px solid #536382;border-radius:7px;background:#26334a;color:#edf3fc;padding:8px 12px;font-size:.8rem;white-space:nowrap}button:hover,button[aria-pressed=true]{background:#37486a;border-color:#acbaff}button:disabled{opacity:.6}button:focus-visible{outline:2px solid #b5bcff;outline-offset:3px}.watchlist-summary{margin:16px 0;font-size:.83rem}.watchlist-summary>span,.observed-time{font-size:.73rem;color:#bac9dc}.activity-list{display:grid;gap:10px}.activity-row{padding:15px;border:1px solid #3b495f;border-radius:9px;background:#1c2433}.activity-row.selected{border-color:#a6b4e9}.activity-row.qualified{border-left:3px solid #7ee0b0}.symbol{font-size:1rem}.put{color:#ef9d8f}.call{color:#7ee0b0}.activity-heading>div{justify-content:flex-start;font-size:.8rem}.activity-body{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:12px;flex-wrap:wrap}.activity-body>div{flex:1;min-width:180px}.activity-body strong{font-size:.88rem}.qualified .activity-body strong{color:#7ee0b0}dl{display:flex;flex-wrap:wrap;gap:18px;margin:0}dt{font-size:.7rem;color:#bac9dc}dd{font-size:.8rem;font-weight:600;margin:4px 0 0;font-variant-numeric:tabular-nums}.filters{justify-content:flex-start;margin:12px 0}.notice,.demo{margin:14px 0;padding:12px;border-radius:8px;background:#242c3e;font-size:.83rem;color:#c4d2e6}.error{color:#ffc8bb}.demo{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;border:1px dashed #8e9dbe}.show-more{margin-top:12px}.footnote{font-size:.73rem;margin-top:14px}.preview{margin-top:12px;background:transparent}@media(max-width:650px){.wall-watchlist{padding:14px}.activity-body{align-items:flex-start;flex-direction:column}dl{gap:16px}.activity-heading{align-items:flex-start;flex-direction:column;gap:6px}.watchlist-summary{align-items:flex-start;flex-direction:column;gap:6px}}
.chart-link{display:inline-flex;align-items:center;gap:8px;border:1px solid #536382;border-radius:7px;background:#26334a;color:#edf3fc;padding:8px 12px;font-size:.8rem;white-space:nowrap;text-decoration:none}.chart-link:hover{background:#37486a;border-color:#acbaff}.chart-link:focus-visible{outline:2px solid #b5bcff;outline-offset:3px}
</style>
