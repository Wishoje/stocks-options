<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({ reviews: { type: Object, required: true } })
const outcome = ref('qualified'), side = ref('all'), page = ref(0), selectedId = ref('')
const pageSize = 8
const labels = {
  target_reached: 'Target reached', invalidated: 'Invalidated', expired: 'Expired',
  unresolved: 'Unresolved', in_progress: 'In progress', entry_missed: 'Entry missed',
  no_setup: 'No setup', watching: 'Watching',
}
const cases = computed(() => props.reviews.cases || [])
const filtered = computed(() => cases.value.filter(row =>
  (outcome.value === 'all' || (outcome.value === 'qualified' ? row.qualified : row.outcome === outcome.value)) && (side.value === 'all' || row.side === side.value)))
const pages = computed(() => Math.max(1, Math.ceil(filtered.value.length / pageSize)))
const visible = computed(() => filtered.value.slice(page.value * pageSize, (page.value + 1) * pageSize))
const selected = computed(() => cases.value.find(row => row.id === selectedId.value))
const counts = computed(() => props.reviews.summary || {})
const qualifiedCounts = computed(() => counts.value.qualified_outcomes || {})
const time = value => value ? new Date(value).toLocaleTimeString('en-US', { timeZone: 'America/New_York', hour: 'numeric', minute: '2-digit' }) : '—'
const number = value => Number.isFinite(value) ? value.toLocaleString('en-US', { maximumFractionDigits: 3 }) : '—'
const pattern = row => `${row.direction === 'bullish' ? 'Bullish' : 'Bearish'} ${row.pattern === 'continuation' ? 'break & retest' : row.pattern === 'reclaim' ? 'reclaim & retest' : row.direction === 'bullish' ? 'bounce' : 'rejection'}`

watch([outcome, side], () => { page.value = 0; selectedId.value = '' })
watch(() => [props.reviews.symbol, props.reviews.session, props.reviews.timeframe], () => {
  outcome.value = 'qualified'; side.value = 'all'; page.value = 0; selectedId.value = ''
})
watch(cases, () => {
  page.value = Math.min(page.value, pages.value - 1)
  if (!cases.value.some(row => row.id === selectedId.value)) selectedId.value = ''
})
</script>

<template>
  <section class="setup-history" aria-label="Recorded setup results">
    <header>
      <div><p class="eyebrow">SETUP REVIEW</p><h3>What happened after the setups?</h3>
        <p>Review the later price closes and the levels each scenario used.</p></div>
      <span class="context">{{ reviews.symbol }} · {{ reviews.session }} · {{ reviews.horizon_minutes }}-minute review</span>
    </header>
    <p v-if="reviews.demonstration" class="review-note">Local synthetic example · These scenarios demonstrate the review rules.</p>
    <p v-else class="review-note">Recorded price behavior · The counts below follow setups whose conditions were met. They do not assume an executed trade.</p>
    <div class="review-counts">
      <div><span>Conditions met</span><strong>{{ counts.qualified || 0 }}</strong><small>One count per setup</small></div>
      <div><span>Target reached</span><strong class="positive">{{ qualifiedCounts.target_reached || 0 }}</strong><small>At a later completed close</small></div>
      <div><span>Invalidated</span><strong class="negative">{{ qualifiedCounts.invalidated || 0 }}</strong><small>Close crossed the boundary</small></div>
      <div><span>Expired</span><strong>{{ qualifiedCounts.expired || 0 }}</strong><small>Time or wall comparison ended</small></div>
      <div><span>Unresolved</span><strong>{{ qualifiedCounts.unresolved || 0 }}</strong><small>Comparison ended without a result</small></div>
      <div><span>In progress</span><strong>{{ qualifiedCounts.in_progress || 0 }}</strong><small>Still within the review window</small></div>
    </div>
    <p class="sample-note">{{ counts.scenarios || 0 }} total scenarios in this session · {{ Math.max(0, (counts.scenarios || 0) - (counts.qualified || 0)) }} did not reach an eligible entry close.<template v-if="counts.overlapping_reviews"> {{ counts.overlapping_reviews }} reviews overlap another review.</template></p>
    <p v-if="reviews.capture_limit_reached" class="sample-note">This view contains the first 160 saved assessments for the session.</p>
    <p v-if="!cases.length" class="empty">The review starts when today’s price sequence creates a setup.</p>
    <template v-else>
      <div class="review-filters">
        <label>Outcome<select v-model="outcome" aria-label="Filter setup outcomes"><option value="qualified">Conditions met setups</option><option value="all">All scenarios</option><option v-for="(label, key) in labels" :key="key" :value="key">{{ label }}</option></select></label>
        <label>Wall<select v-model="side" aria-label="Filter setup wall"><option value="all">Both walls</option><option value="put">Put wall</option><option value="call">Call wall</option></select></label>
        <span>{{ filtered.length }} scenarios shown</span>
      </div>
      <div class="review-table">
        <table><caption>Recorded scenarios · Times in ET</caption>
          <thead><tr><th>Setup</th><th>Recorded close</th><th>Outcome</th><th>Evidence</th><th><span class="sr-only">Inspect scenario</span></th></tr></thead>
          <tbody><tr v-for="row in visible" :key="row.id" :class="{ selected: selectedId === row.id }">
            <td><strong>{{ row.side === 'put' ? 'Put' : 'Call' }} wall {{ number(row.strike) }}</strong><small>{{ pattern(row) }}</small></td>
            <td>{{ time(row.observed_at) }}<small>{{ row.qualified ? 'Conditions met' : 'Before an eligible close' }}</small></td>
            <td><span :class="['outcome', row.outcome]">{{ labels[row.outcome] || 'Watching' }}</span><small v-if="row.outcome_at">{{ time(row.outcome_at) }} ET</small></td>
            <td class="grade">{{ row.evidence_grade || '—' }}<small>{{ row.review_basis === 'saved_assessment' ? 'Saved assessment' : 'Session replay' }}</small></td>
            <td><button type="button" :aria-label="`Inspect ${row.side} wall ${row.strike} setup at ${time(row.observed_at)}`" :aria-expanded="selectedId === row.id" aria-controls="setup-review-details" @click="selectedId = selectedId === row.id ? '' : row.id">{{ selectedId === row.id ? 'Close' : 'Inspect' }}</button></td>
          </tr></tbody>
        </table>
      </div>
      <p v-if="!filtered.length" class="empty">No scenarios match these filters.</p>
      <nav v-if="pages > 1" class="review-pages" aria-label="Setup review pages"><button type="button" :disabled="page === 0" @click="page--; selectedId = ''">Previous</button><span>Page {{ page + 1 }} of {{ pages }}</span><button type="button" :disabled="page + 1 >= pages" @click="page++; selectedId = ''">Next</button></nav>
      <article v-if="selected" id="setup-review-details" class="review-detail" aria-label="Selected setup review" aria-live="polite">
        <header><div><h4>{{ pattern(selected) }} · {{ selected.side }} wall {{ number(selected.strike) }}</h4><p>{{ labels[selected.outcome] }} · {{ time(selected.observed_at) }} ET</p></div><button type="button" @click="selectedId = ''">Close review</button></header>
        <p>{{ selected.reason }}</p>
        <dl class="detail-levels">
          <div><dt>Recorded close</dt><dd>{{ number(selected.reference_close) }}</dd></div>
          <div><dt>Entry zone</dt><dd>{{ number(selected.entry_zone?.low) }}–{{ number(selected.entry_zone?.high) }}</dd></div>
          <div><dt>Invalidation</dt><dd>{{ number(selected.invalidation?.price) }}</dd></div>
          <div><dt>Frozen target</dt><dd>{{ number(selected.target?.strike) }}</dd></div>
        </dl>
        <p v-if="selected.review_until">Follow-up window ends at {{ time(selected.review_until) }} ET. The setup's entry window is separate from this price review.</p>
        <p v-if="selected.first_recorded_at">Assessment saved at {{ time(selected.first_recorded_at) }} ET. The original qualifying levels remain fixed in this review.</p>
        <p v-else>Session replay uses the retained observations under the current setup rules.</p>
        <p v-if="selected.overlaps_another_review">This review overlaps another scenario. Treat their outcomes as related observations.</p>
        <div v-if="selected.follow_up_bars?.length" class="review-table evidence-table"><table><caption>Completed closes after the eligible setup</caption><thead><tr><th>Time ET</th><th>Close</th></tr></thead><tbody><tr v-for="bar in selected.follow_up_bars" :key="bar.id"><td>{{ time(bar.observed_at) }}</td><td>{{ number(bar.close) }}</td></tr></tbody></table></div>
        <p v-else>No later closes are included in this review.</p>
      </article>
    </template>
    <details class="review-guide"><summary>How to read setup results</summary>
      <p><b>Start with Conditions met.</b> It counts each distinct setup once at its first eligible five-minute close. Repeated readings of the same setup do not add samples. The table also keeps scenarios that expired, missed their zone or never qualified.</p>
      <p><b>Target and invalidation use completed closes.</b> The first later close at or beyond the frozen target is Target reached. A close beyond the opposite invalidation boundary is Invalidated. A wick touching a level does not determine either result. Levels remain fixed from the qualifying assessment.</p>
      <p><b>Review up to one hour.</b> The window ends sooner at the market close or when the wall comparison changes. Expired means the complete review ended without either boundary closing. Unresolved means the continuous comparison ended before an outcome could be determined. In progress means the review is still running.</p>
      <p><b>Keep the basis visible.</b> Saved assessment uses the first qualifying scenario preserved by collection. Session replay reconstructs the scenario from retained session evidence. Later price bars can be revised; this review is descriptive and is not an as-published trading backtest.</p>
      <p>Counts describe observed price paths, without simulated orders, profit or loss, or a win rate. Overlapping review windows share market observations. Evidence grades describe the original setup checks and do not measure its future chance of success.</p>
    </details>
    <p class="review-footnote">Review rules and supporting closes are included in Export timeline JSON and Intraday walls &amp; setups AI Export.</p>
  </section>
</template>

<style scoped>
.setup-history{margin-top:22px;padding:22px;border:1px solid #42475d;border-radius:14px;background:#1b1e29;color:#e8ecfa;min-width:0}
header{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap}.eyebrow{font-size:11px;letter-spacing:.12em;color:#a7b9df}h3{font-size:20px;font-weight:650;margin:5px 0}h4{font-size:17px;font-weight:600}p{font-size:13px;line-height:1.6;color:#c4cce0;margin:8px 0}.context{font-size:12px;color:#c4cce0}.review-note{background:#25283a;border-radius:7px;padding:10px 12px}
.review-counts{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin:18px 0}.review-counts>div{border:1px solid #41465e;border-radius:9px;padding:14px}.review-counts span{font-size:12px;color:#c4cce0}.review-counts strong{display:block;font-size:27px;color:#b9c4ff;margin:7px 0}.review-counts .positive{color:#9ce2b4}.review-counts .negative{color:#e2a08e}small{display:block;font-size:11px;line-height:1.5;color:#bac5df;margin-top:5px}.sample-note{font-size:12px}.review-filters{display:flex;gap:18px;flex-wrap:wrap;align-items:end;margin:18px 0}.review-filters label{font-size:12px;display:flex;flex-direction:column;gap:6px}.review-filters span{font-size:12px;padding-bottom:10px;color:#bac5df}select,button{background:#25293a;color:#e8ecfa;border:1px solid #505873;border-radius:7px;padding:9px;min-height:40px;font-size:12px}button{cursor:pointer}button:disabled{opacity:.45;cursor:default}button:focus-visible,select:focus-visible,summary:focus-visible{outline:2px solid #b5acff;outline-offset:3px}
.review-table{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:12px;text-align:left}caption{text-align:left;font-size:12px;color:#c4cce0;padding:10px 0}th{font-weight:500;color:#bac5df}th,td{padding:12px 10px;border-bottom:1px solid #41465e;vertical-align:top}td:first-child{min-width:165px}td:last-child{width:78px}.selected{background:#2a3047}.grade{text-transform:capitalize}.outcome{font-weight:600;white-space:nowrap}.outcome.target_reached{color:#9ce2b4}.outcome.invalidated{color:#e2a08e}.review-pages{display:flex;justify-content:flex-end;align-items:center;gap:14px;margin-top:14px;font-size:12px}.empty{padding:18px;background:#25283a;border-radius:8px}.review-detail{border:1px solid #697397;border-radius:10px;padding:18px;margin:18px 0}.detail-levels{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;padding:16px 0}.detail-levels dt{font-size:12px;color:#bac5df}.detail-levels dd{font-size:18px;margin:7px 0;font-variant-numeric:tabular-nums}.evidence-table{max-width:450px}.review-guide{border-top:1px solid #41465e;margin-top:18px}.review-guide p{max-width:1050px}summary{cursor:pointer;font-size:13px;font-weight:600;padding:14px 0}.review-footnote{font-size:11px}
@media(max-width:1150px){.review-counts{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:720px){.setup-history{padding:14px}.review-counts{grid-template-columns:repeat(2,minmax(0,1fr))}.detail-levels{grid-template-columns:1fr 1fr}.review-detail{padding:14px}.review-table table{min-width:650px}}
</style>
