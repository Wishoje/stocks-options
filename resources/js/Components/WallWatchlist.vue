<script setup>
import { computed, ref, watch, onScopeDispose } from 'vue'
import { useWallWatchlist } from '@/composables/useWallWatchlist'
import { wallReactionTitle, wallSetupTitle } from '@/utils/wallDecision'
import { activeWallRows, filterWallRows, wallLifecycleRows, wallStatusLabel } from '@/Support/wallLifecycle'
import WallAlertControls from '@/Components/WallAlertControls.vue'
import WallStatusFilters from '@/Components/WallStatusFilters.vue'
import WallStatusBadge from '@/Components/WallStatusBadge.vue'

const props = defineProps({ selectedSymbol: String, chartHref: Function })
const emit = defineEmits(['open'])
const { data, loading, refreshing, error, demo, load, chooseDemo } = useWallWatchlist()
const selectedStatus = ref('conditions_met'), symbol = ref(''), direction = ref('all'), limit = ref(6)
const clock = ref(Date.now())
const timer = setInterval(() => { clock.value = Date.now() }, 10000)
onScopeDispose(() => clearInterval(timer))
const items = computed(() => activeWallRows(wallLifecycleRows(data.value, clock.value, !!error.value)))
const qualifiedCount = computed(() => items.value.filter(row => row.status_group === 'conditions_met').length)
const filtered = computed(() => filterWallRows(items.value, { status: selectedStatus.value, symbol: symbol.value, direction: direction.value }))
const visible = computed(() => filtered.value.slice(0, limit.value))
const time = value => new Date(value).toLocaleTimeString('en-US', { timeZone: 'America/New_York', hour: 'numeric', minute: '2-digit' })
const number = value => Number.isFinite(value) ? value.toLocaleString('en-US', { maximumFractionDigits: 6 }) : '—'
const hasLevels = row => row.qualified && row.setup?.entry_zone && row.setup?.invalidation && row.setup?.target
const checks = row => (row.setup?.evidence_checks || []).filter(check => check.passed).map(check => ({
  ordered_price_confirmation: 'Price confirmed', wall_persistence: 'Wall stable', supported_geometry: 'Target room checked',
}[check.key])).filter(Boolean)
const progress = row => Number.isInteger(row.next_condition?.completed_closes) && row.next_condition.required_closes > 0
  ? `${row.next_condition.completed_closes} of ${row.next_condition.required_closes} confirming closes recorded` : ''
const closed = computed(() => data.value?.state === 'outside_session' || (!demo.value && data.value?.refresh_until && Date.parse(data.value.refresh_until) <= clock.value))
watch([selectedStatus, symbol, direction], () => { limit.value = 6 })
watch(() => data.value?.session, () => { limit.value = 6 })
</script>

<template>
  <section class="wall-watchlist" aria-label="Watchlist wall activity" :aria-busy="loading || refreshing">
    <header><div><h3>Which symbols need attention?</h3><p>Intraday setups · All saved symbols · 1W expiries · Prices delayed 15 min</p></div>
      <button type="button" :disabled="loading || refreshing" @click="load()">{{ refreshing ? 'Refreshing…' : 'Refresh watchlist' }}</button></header>
    <WallAlertControls />
    <p v-if="loading" class="notice" role="status">Checking your watchlist’s wall assessments…</p>
    <p v-if="error" class="notice error" role="alert">{{ error }} <button type="button" @click="load()">Retry</button></p>
    <template v-if="data">
      <div v-if="demo" class="demo" role="status">Local demonstration · Synthetic historical examples <button type="button" @click="chooseDemo(false)">Back to my watchlist</button></div>
      <div v-else class="watchlist-summary"><strong>{{ qualifiedCount ? `${qualifiedCount} setup${qualifiedCount === 1 ? '' : 's'} with conditions met` : data.assessed_count ? 'No confirmed setups in your watchlist' : 'Waiting for current wall assessments' }}</strong>
        <span>{{ data.assessed_count }} of {{ data.watchlist_count }} symbols assessed · {{ data.session }}</span></div>
      <WallStatusFilters v-model="selectedStatus" v-model:symbol="symbol" v-model:direction="direction" :rows="items" current-only />
      <div v-if="visible.length" class="activity-list">
        <article v-for="row in visible" :key="row.id || `${row.symbol}:${row.side}`" :class="['activity-row', { selected: row.symbol === selectedSymbol, qualified: row.status_group === 'conditions_met' }]">
          <div class="activity-heading"><div><strong class="symbol">{{ row.symbol }}</strong><span :class="row.side">{{ row.side === 'put' ? 'Put' : 'Call' }} wall {{ number(row.strike) }}</span></div><WallStatusBadge :status="row.status_group" :outcome="row.outcome" /></div>
          <p class="observed-time"><template v-if="row.qualified_at">Qualified {{ time(row.qualified_at) }} ET · </template>Recorded close {{ number(row.close) }} · {{ time(row.observed_at) }} ET</p>
          <div class="activity-body"><div><strong>{{ row.setup?.direction ? wallSetupTitle(row.setup) : row.reaction ? wallReactionTitle(row.reaction) : 'Watch for price confirmation' }}</strong>
            <p v-if="checks(row).length">{{ checks(row).join(' · ') }}</p>
            <p v-if="progress(row)">{{ progress(row) }}</p>
            <p class="activity-next"><b>{{ row.status_group === 'finished' ? 'Result:' : row.status_group === 'needs_update' ? 'Last assessment:' : 'Next:' }}</b> {{ row.reason || row.next_condition?.text || 'Wait for a completed price sequence at this wall.' }}</p>
            <p v-if="row.status_group === 'in_progress'">Following the recorded scenario. No entered position is assumed.</p>
            <p v-if="row.evaluated_through" class="observed-time">Assessed through {{ time(row.evaluated_through) }} ET</p></div>
            <dl v-if="hasLevels(row)"><div><dt>Entry area (recorded)</dt><dd>{{ number(row.setup.entry_zone.low) }}–{{ number(row.setup.entry_zone.high) }}</dd></div>
              <div><dt>Invalid if 5m close</dt><dd>{{ row.setup.invalidation.close_side }} {{ number(row.setup.invalidation.price) }}</dd></div>
              <div><dt>Reference target</dt><dd>{{ number(row.setup.target.strike) }}</dd></div></dl>
            <a v-if="chartHref" :href="chartHref({ ...row, demonstration: demo })" :aria-label="`View ${row.symbol} wall assessment`" class="chart-link">{{ row.assessment_id ? 'View assessment' : 'Open wall tracking' }} <span aria-hidden="true">→</span></a>
            <button v-else type="button" :aria-label="`View ${row.symbol} wall assessment`" @click="emit('open', { ...row, demonstration: demo })">View assessment <span aria-hidden="true">→</span></button>
          </div>
        </article>
      </div>
      <p v-else-if="!error" class="notice" role="status">{{ !data.watchlist_count ? 'Add symbols to your watchlist to follow their wall activity here.' : `No ${selectedStatus === 'all' ? 'setups' : selectedStatus === 'finished' ? 'past setups' : wallStatusLabel(selectedStatus).toLowerCase() + ' setups'} match these filters. Your selection stays in place as updates arrive.` }}</p>
      <p v-if="closed" class="footnote">The market is closed. These are the latest recorded states for this session.</p>
      <button v-if="filtered.length > limit" class="show-more" type="button" @click="limit += 6">Show more · {{ filtered.length - limit }} remaining</button>
      <details class="footnote"><summary>What do these statuses mean?</summary><p>Watching: conditions have not passed. Conditions met: the rules passed at the recorded close. In progress: following a qualified scenario. Needs update: open the assessment to check its latest state. This list focuses on developing and active setups.</p></details>
      <p class="footnote">Conditions met refers to the recorded close. Check current price against the entry area before using a setup.</p>
      <button v-if="data.local_demo_available && !demo" class="preview" type="button" @click="chooseDemo(true)">Preview local demonstration</button>
    </template>
  </section>
</template>

<style scoped>
.activity-next{color:#e1eaf8;max-width:620px}.progress{color:#b9c5fc;font-size:.73rem}
.wall-watchlist{margin-bottom:22px;padding:20px;border:1px solid #43536a;border-radius:14px;background:#171d29;color:#edf3fc}header,.activity-heading,.activity-heading>div,.watchlist-summary,.filters{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}h3{font-size:1.15rem;font-weight:700;margin:0 0 6px}p{font-size:.8rem;line-height:1.5;color:#bfccde;margin:5px 0}button{border:1px solid #536382;border-radius:7px;background:#26334a;color:#edf3fc;padding:8px 12px;font-size:.8rem;white-space:nowrap}button:hover,button[aria-pressed=true]{background:#37486a;border-color:#acbaff}button:disabled{opacity:.6}button:focus-visible{outline:2px solid #b5bcff;outline-offset:3px}.watchlist-summary{margin:16px 0;font-size:.83rem}.watchlist-summary>span,.observed-time{font-size:.73rem;color:#bac9dc}.activity-list{display:grid;gap:10px}.activity-row{padding:15px;border:1px solid #3b495f;border-radius:9px;background:#1c2433}.activity-row.selected{border-color:#a6b4e9}.activity-row.qualified{border-left:3px solid #7ee0b0}.symbol{font-size:1rem}.put{color:#ef9d8f}.call{color:#7ee0b0}.activity-heading>div{justify-content:flex-start;font-size:.8rem}.activity-body{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:12px;flex-wrap:wrap}.activity-body>div{flex:1;min-width:180px}.activity-body strong{font-size:.88rem}.qualified .activity-body strong{color:#7ee0b0}dl{display:flex;flex-wrap:wrap;gap:18px;margin:0}dt{font-size:.7rem;color:#bac9dc}dd{font-size:.8rem;font-weight:600;margin:4px 0 0;font-variant-numeric:tabular-nums}.filters{justify-content:flex-start;margin:12px 0}.notice,.demo{margin:14px 0;padding:12px;border-radius:8px;background:#242c3e;font-size:.83rem;color:#c4d2e6}.error{color:#ffc8bb}.demo{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;border:1px dashed #8e9dbe}.show-more{margin-top:12px}.footnote{font-size:.73rem;margin-top:14px}.preview{margin-top:12px;background:transparent}@media(max-width:650px){.wall-watchlist{padding:14px}.activity-body{align-items:flex-start;flex-direction:column}dl{gap:16px}.activity-heading{align-items:flex-start;flex-direction:column;gap:6px}.watchlist-summary{align-items:flex-start;flex-direction:column;gap:6px}}
.chart-link{display:inline-flex;align-items:center;gap:8px;border:1px solid #536382;border-radius:7px;background:#26334a;color:#edf3fc;padding:8px 12px;font-size:.8rem;white-space:nowrap;text-decoration:none}.chart-link:hover{background:#37486a;border-color:#acbaff}.chart-link:focus-visible{outline:2px solid #b5bcff;outline-offset:3px}
</style>
