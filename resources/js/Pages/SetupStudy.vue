<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'
import {
  defaultStudyFilters, studyAdverseR, studyCohorts, studyCsv, studyEvidenceEntries, studyFilterParams,
  studyLabel, studyNumber, studyPercent, studyR, studyRate, studyTime,
} from '@/Support/setupStudy'

const props = defineProps({ localDemoAvailable: Boolean })
const filters = reactive(defaultStudyFilters())
const demonstration = ref(false)
const report = ref(null)
const loading = ref(false)
const refreshing = ref(false)
const error = ref('')
const refreshMessage = ref('')
const refreshWarnings = ref([])
const expandedPlans = ref(new Set())
let request = 0
let controller
let refreshController
let disposed = false

const rows = computed(() => report.value?.rows || [])
const summary = computed(() => report.value?.summary || {})
const filterError = computed(() => !filters.from || !filters.to ? 'Choose both dates.'
  : filters.from > filters.to ? 'From date must be on or before To date.' : '')
const busy = computed(() => loading.value || refreshing.value)
const counts = computed(() => [
  { label: 'Recorded plans', value: summary.value.total },
  { label: 'Qualified', value: summary.value.qualified },
  { label: 'Watching', value: summary.value.watching },
  { label: 'In progress', value: summary.value.in_progress },
  { label: 'Completed', value: summary.value.completed },
  { label: 'Needs update', value: summary.value.needs_update },
])
const rates = computed(() => [
  { key: 'strict_target', label: 'Target before invalidation', note: 'Exact recorded target', metric: summary.value.strict_target },
  { key: 'favorable_05r', label: 'Favorable move ≥ 0.5R', note: 'Before invalidation', metric: summary.value.favorable_05r },
  { key: 'favorable_1r', label: 'Favorable move ≥ 1R', note: 'Before invalidation', metric: summary.value.favorable_1r },
  { key: 'entry_revisit', label: 'Entry area revisited', note: 'Full observed bars after availability', metric: summary.value.entry_revisit },
])
const breakdowns = computed(() => [
  { key: 'patterns', title: 'By setup pattern', label: 'Pattern', rows: report.value?.breakdowns?.patterns || [] },
  { key: 'symbols', title: 'By symbol', label: 'Symbol', rows: report.value?.breakdowns?.symbols || [] },
])
const yesNo = value => value === true ? 'Yes' : value === false ? 'No' : '—'
const rowStatus = row => row.needs_update ? 'needs_update' : row.status
const risk = row => row.signal_price != null && row.invalidation != null
  ? Math.abs(Number(row.signal_price) - Number(row.invalidation)) : null
const bestClose = row => row.metrics?.close_mfe_r ?? row.metrics?.mfe_r
const worstClose = row => row.metrics?.close_mae_r ?? row.metrics?.mae_r
const moveLabel = (row, key) => {
  const value = row.metrics?.[key]
  if (value === true) return 'Yes'
  if (value !== false || row.metrics?.observations === 0) return '—'
  if (row.followup_complete && !row.needs_update) return 'No'
  return row.needs_update ? 'Incomplete' : 'Pending'
}

function togglePlan(id) {
  const next = new Set(expandedPlans.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  expandedPlans.value = next
}

function requestError(cause, fallback) {
  if ([401, 403].includes(cause?.response?.status)) return 'Setup study is available to the owner account only.'
  if (cause?.response?.status === 429) return 'Please wait a moment before trying again.'
  return cause?.response?.data?.message || fallback
}

async function load() {
  if (filterError.value) return
  const run = ++request
  const expectedDemo = demonstration.value
  controller?.abort()
  controller = new AbortController()
  loading.value = true
  error.value = ''
  report.value = null
  expandedPlans.value = new Set()
  try {
    const { data } = await axios.get('/api/setup-study', {
      params: { ...studyFilterParams(filters), ...(expectedDemo ? { demo: 1 } : {}) }, signal: controller.signal, timeout: 30000,
    })
    if (run !== request || disposed) return
    if (!data || !Array.isArray(data.rows) || !data.summary || typeof data.summary !== 'object') throw new Error('Invalid report')
    if (Boolean(data.demonstration) !== expectedDemo || (expectedDemo && !props.localDemoAvailable)) throw new Error('Unexpected report source')
    report.value = data
  } catch (cause) {
    if (run === request && !disposed && cause?.code !== 'ERR_CANCELED') {
      error.value = requestError(cause, 'The study could not load. Try again.')
    }
  } finally {
    if (run === request && !disposed) loading.value = false
  }
}

function applyFilters() {
  refreshMessage.value = ''
  refreshWarnings.value = []
  filters.symbol = filters.symbol.trim().toUpperCase()
  load()
}

function selectCohort(cohort) {
  if (refreshing.value || filters.cohort === cohort) return
  filters.cohort = cohort
  applyFilters()
}

function toggleExamples() {
  if (!props.localDemoAvailable || refreshing.value || filterError.value) return
  demonstration.value = !demonstration.value
  applyFilters()
}

async function refreshRecords() {
  if (demonstration.value || report.value?.demonstration || busy.value || filterError.value || report.value?.collection_enabled === false) return
  refreshing.value = true
  error.value = ''
  refreshMessage.value = ''
  refreshWarnings.value = []
  refreshController = new AbortController()
  try {
    const { data } = await axios.post('/api/setup-study/refresh', studyFilterParams(filters), {
      signal: refreshController.signal, timeout: 60000,
    })
    if (disposed) return
    await load()
    if (!disposed) {
      refreshWarnings.value = Array.isArray(data?.warnings) ? data.warnings : []
      if (data?.busy && !refreshWarnings.value.length) refreshWarnings.value = ['Record collection is already running. The saved report is shown below.']
      if (data?.prepared === false && !refreshWarnings.value.length) refreshWarnings.value = ['Record collection is not ready.']
      if (!error.value && data?.prepared !== false && !data?.busy) refreshMessage.value = 'Records refreshed from saved evidence.'
    }
  } catch (cause) {
    if (!disposed && cause?.code !== 'ERR_CANCELED') error.value = requestError(cause, 'Records could not refresh. Try again.')
  } finally {
    if (!disposed) refreshing.value = false
  }
}

function downloadCsv() {
  if (demonstration.value || report.value?.demonstration || !rows.value.length) return
  const url = URL.createObjectURL(new Blob(['\uFEFF', studyCsv(rows.value)], { type: 'text/csv;charset=utf-8;' }))
  const anchor = document.createElement('a')
  const scope = report.value?.filters || filters
  anchor.href = url
  anchor.download = `setup-study-${scope.cohort}-${scope.from}-${scope.to}.csv`
  anchor.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

onMounted(load)
onBeforeUnmount(() => { disposed = true; request++; controller?.abort(); refreshController?.abort() })
</script>

<template>
  <AppLayout title="Setup study">
    <main class="study-page gex-ui" data-theme="dark">
      <header class="study-heading">
        <div><p class="study-eyebrow">OWNER RESEARCH</p><h1>Setup study</h1><p>Review what price did after a recorded setup.</p></div>
        <div class="study-actions">
          <button type="button" class="study-button" :disabled="demonstration || busy || !rows.length" @click="downloadCsv">Download CSV</button>
          <button type="button" class="study-button study-button--primary" :disabled="demonstration || busy || !!filterError || report?.collection_enabled === false" @click="refreshRecords">{{ refreshing ? 'Refreshing records…' : 'Refresh records' }}</button>
        </div>
      </header>
      <p class="study-notice">Recorded scenarios, not executed trades. Partial moves are measured separately from target outcomes.</p>
      <div v-if="props.localDemoAvailable" class="study-preview-control"><button type="button" class="study-preview-toggle" :disabled="refreshing || !!filterError" @click="toggleExamples">{{ demonstration ? 'Back to saved records' : 'Preview example' }}</button></div>
      <aside v-if="demonstration" class="study-demo" role="status"><strong>Local demonstration · Synthetic examples</strong><p>These records are not saved and do not represent market results.</p></aside>
      <nav class="study-cohorts" aria-label="Study holding period">
        <button v-for="cohort in studyCohorts" :key="cohort.key" type="button" :aria-pressed="filters.cohort === cohort.key" :disabled="refreshing" @click="selectCohort(cohort.key)">{{ cohort.label }}</button>
      </nav>
      <form class="study-filters study-panel" aria-label="Study filters" @submit.prevent="applyFilters">
        <label>From<input v-model="filters.from" type="date" required aria-label="From date" :disabled="refreshing" /></label>
        <label>To<input v-model="filters.to" type="date" required aria-label="To date" :disabled="refreshing" /></label>
        <label>Symbol<input v-model="filters.symbol" type="text" placeholder="All symbols" maxlength="16" aria-label="Symbol" autocomplete="off" :disabled="refreshing" /></label>
        <label>Direction<select v-model="filters.direction" aria-label="Direction" :disabled="refreshing"><option value="">Both directions</option><option value="bullish">Bullish</option><option value="bearish">Bearish</option></select></label>
        <button type="submit" class="study-button" :disabled="busy || !!filterError">Apply filters</button>
        <p v-if="filterError" class="study-filter-error" role="status">{{ filterError }}</p>
      </form>
      <div class="study-meta"><p>Qualification dates; watching plans use publication dates · Times in ET · Default: 14 calendar days</p><p>{{ demonstration ? 'Example preview · Collection and CSV are disabled.' : 'Records are collected in scheduled batches. Refresh this view to see the latest saved evidence.' }}</p></div>
      <p v-if="refreshMessage" role="status" class="study-success">{{ refreshMessage }}</p>
      <div v-for="(warning, index) in refreshWarnings" :key="index" class="study-warning" role="status">{{ typeof warning === 'string' ? warning : warning.message || studyLabel(warning.code) }}</div>
      <div v-if="error" role="alert" class="study-panel study-error"><p>{{ error }}</p><button type="button" class="study-button" :disabled="busy" @click="load">Retry report</button></div>
      <div v-if="loading" role="status" class="study-panel study-empty" aria-live="polite"><h2>Loading recorded setups…</h2><p>Reading the selected study period.</p></div>
      <template v-else-if="report">
        <div class="study-meta"><p>Showing {{ studyLabel(report.filters?.cohort) }} · {{ report.filters?.from }} to {{ report.filters?.to }} · {{ report.filters?.symbol || 'All symbols' }} · {{ report.filters?.direction ? studyLabel(report.filters.direction) : 'Both directions' }}</p></div>
        <div class="study-meta"><p v-if="!demonstration">Last collection: {{ studyTime(report.last_collected_at) }}</p><p>Report: {{ studyTime(report.generated_at) }}</p></div>
        <div v-if="!demonstration && report.collection_enabled === false" class="study-warning" role="status">Record collection is disabled. Existing records remain available.</div>
        <div v-for="(warning, index) in report.warnings || []" :key="index" class="study-warning" role="status">{{ typeof warning === 'string' ? warning : warning.message || studyLabel(warning.code) }}</div>
        <section class="study-counts" aria-label="Plan counts"><div v-for="count in counts" :key="count.label" class="study-panel"><span>{{ count.label }}</span><strong>{{ studyNumber(count.value, 0) }}</strong></div></section>
        <div class="study-section-heading"><h2>Signal-close response</h2><p>Observed price moves from the qualifying close, including moves before the app received the record.</p></div>
        <section class="study-metrics" aria-label="Observed outcomes">
          <article v-for="item in rates" :key="item.key" class="study-panel study-metric" :data-metric="item.key"><h2>{{ item.label }}</h2><strong>{{ studyRate(item.metric) }}</strong><p>{{ studyNumber(item.metric?.hits, 0) }} of {{ studyNumber(item.metric?.eligible, 0) }} eligible plans</p><span>{{ item.note }}</span></article>
          <article class="study-panel study-metric"><h2>Average best close</h2><strong>{{ studyR(summary.avg_mfe_r) }}</strong><p>Favorable distance from signal</p><span>Completed follow-up only</span></article>
          <article class="study-panel study-metric"><h2>Average worst close</h2><strong>{{ studyAdverseR(summary.avg_mae_r) }}</strong><p>Adverse distance from signal</p><span>Completed follow-up only</span></article>
        </section>
        <p class="study-method-note">Rates use completed plans with sufficient follow-up. Watching, pending and needs-update plans are excluded. R is the distance from signal price to invalidation.</p>
        <section class="study-panel study-availability" aria-label="After app availability">
          <div><h2>After app availability</h2><p>Completed plans still open when the app received them. Price response does not establish an entry.</p></div>
          <dl><div data-metric="after_publication_target"><dt>Target before invalidation</dt><dd>{{ studyRate(summary.after_publication_target) }}</dd><small>{{ studyNumber(summary.after_publication_target?.hits, 0) }} of {{ studyNumber(summary.after_publication_target?.eligible, 0) }} eligible plans</small></div><div><dt>Resolved before app availability</dt><dd>{{ studyNumber(summary.resolved_before_publication, 0) }}</dd><small>Excluded from this target rate</small></div><div><dt>Availability timing unknown</dt><dd>{{ studyNumber(summary.publication_timing_unknown, 0) }}</dd><small>Shown separately</small></div></dl>
        </section>
        <section v-if="breakdowns.some(group => group.rows.length)" class="study-breakdowns" aria-label="Grouped results">
          <article v-for="group in breakdowns" :key="group.key" class="study-panel study-breakdown"><h2>{{ group.title }}</h2><div class="study-table-scroll"><table :aria-label="group.title"><thead><tr><th scope="col">{{ group.label }}</th><th scope="col">Plans</th><th scope="col">Target</th><th scope="col">≥ 0.5R</th><th scope="col">≥ 1R</th></tr></thead><tbody><tr v-for="item in group.rows" :key="item.label"><th scope="row">{{ group.key === 'symbols' ? item.label : studyLabel(item.label) }}</th><td>{{ studyNumber(item.total, 0) }}<small>{{ studyNumber(item.completed, 0) }} complete</small></td><td v-for="key in ['strict_target', 'favorable_05r', 'favorable_1r']" :key="key">{{ studyRate(item[key]) }}<small>{{ studyNumber(item[key]?.hits, 0) }} / {{ studyNumber(item[key]?.eligible, 0) }}</small></td></tr><tr v-if="!group.rows.length"><td colspan="5">No grouped records.</td></tr></tbody></table></div></article>
        </section>
        <section class="study-panel study-records" aria-label="Recorded plans">
          <header class="study-records-heading"><div><h2>Recorded plans</h2><p>Repeated confirmations at the same level count as one plan.</p></div><span>{{ rows.length }} {{ rows.length === 1 ? 'plan' : 'plans' }}</span></header>
          <div v-if="!rows.length" class="study-empty" role="status"><h3>{{ demonstration ? 'No synthetic examples match this view' : 'No recorded plans in this view' }}</h3><p>{{ demonstration ? 'Choose another period, symbol or direction to inspect the examples.' : 'Choose another period or refresh records to collect available saved evidence.' }}</p></div>
          <div v-else class="study-table-scroll"><table class="study-table"><caption class="sr-only">Recorded setup scenarios and their observed price outcomes</caption><thead><tr><th scope="col">Plan</th><th scope="col">Signal time</th><th scope="col">State</th><th scope="col">Target outcome</th><th scope="col">Best / worst close</th><th scope="col">Favorable moves</th><th scope="col">Details</th></tr></thead><tbody>
            <template v-for="row in rows" :key="row.id">
              <tr><th scope="row"><strong>{{ row.symbol }}</strong><span class="study-direction" :class="row.direction">{{ studyLabel(row.direction) }}</span></th><td>{{ studyTime(row.signal_at) }}</td><td><span class="study-state" :class="{ 'study-state--stale': row.needs_update }">{{ studyLabel(rowStatus(row)) }}</span></td><td>{{ studyLabel(row.metrics?.strict_outcome || row.outcome) }}<span v-if="row.metrics?.publication_timing" class="study-cell-line" :class="{ 'study-timing-late': row.metrics.publication_timing === 'resolved_before_publication' }">{{ studyLabel(row.metrics.publication_timing) }}</span></td><td><span class="study-tabular">{{ studyR(bestClose(row)) }} / {{ studyAdverseR(worstClose(row)) }}</span></td><td><span>0.5R: {{ moveLabel(row, 'reached_05r') }}</span><span class="study-cell-line">1R: {{ moveLabel(row, 'reached_1r') }}</span></td><td><button type="button" class="study-inspect" :aria-expanded="expandedPlans.has(row.id)" :aria-controls="`study-plan-${row.id}`" @click="togglePlan(row.id)">{{ expandedPlans.has(row.id) ? 'Close details' : 'Inspect plan' }}</button></td></tr>
              <tr v-if="expandedPlans.has(row.id)" class="study-expanded-row"><td colspan="7"><div :id="`study-plan-${row.id}`" class="study-detail-content">
                <h3>{{ row.symbol }} · {{ studyLabel(row.direction) }}</h3>
                <dl class="study-detail-grid"><div><dt>Original signal time</dt><dd>{{ studyTime(row.signal_at) }}</dd></div><div><dt>Available to the app</dt><dd>{{ studyTime(row.available_at) }}</dd></div><div><dt>Signal price</dt><dd>{{ studyNumber(row.signal_price) }}</dd></div><div><dt>Entry area</dt><dd>{{ studyNumber(row.entry_low) }}–{{ studyNumber(row.entry_high) }}</dd></div><div><dt>Invalidation</dt><dd>{{ studyNumber(row.invalidation) }}</dd></div><div><dt>Recorded target</dt><dd>{{ studyNumber(row.target) }}</dd></div><div><dt>1R distance</dt><dd>{{ studyNumber(risk(row)) }}</dd></div><div><dt>Confirmations · one plan</dt><dd>{{ studyNumber(row.confirmation_count, 0) }}</dd></div><div><dt>Qualified</dt><dd>{{ yesNo(row.qualified) }}</dd></div><div><dt>Follow-up complete</dt><dd>{{ yesNo(row.followup_complete) }}</dd></div><div><dt>Entry area revisited</dt><dd>{{ yesNo(row.metrics?.entry_revisited) }}</dd></div><div><dt>Entry revisit observable</dt><dd>{{ yesNo(row.metrics?.entry_observable) }}</dd></div><div><dt>Outcome time</dt><dd>{{ studyTime(row.metrics?.strict_at) }}</dd></div><div><dt>Best / adverse move from signal</dt><dd>{{ studyPercent(row.metrics?.mfe_pct) }} / {{ studyPercent(row.metrics?.mae_pct) }}</dd></div><div><dt>Observed closes</dt><dd>{{ studyNumber(row.metrics?.observations, 0) }}</dd></div><div><dt>Latest observed close</dt><dd>{{ studyTime(row.metrics?.last_at) }}</dd></div></dl>
                <dl class="study-detail-grid study-timing-detail"><div><dt>State at app availability</dt><dd>{{ studyLabel(row.metrics?.publication_timing) }}</dd></div><div><dt>First favorable 0.5R close</dt><dd>{{ studyTime(row.metrics?.first_05r_at) }}</dd></div><div><dt>First favorable 1R close</dt><dd>{{ studyTime(row.metrics?.first_1r_at) }}</dd></div></dl>
                <p class="study-detail-note">Price moves use observed closes from the signal, without an assumed entry fill. Target and favorable-move tests stop at invalidation. An outcome before app availability belongs only to the signal-close response.</p>
                <section><h4>Source provenance</h4><dl class="study-evidence"><div v-for="entry in studyEvidenceEntries(row.source)" :key="entry.label"><dt>{{ entry.label }}</dt><dd>{{ entry.value }}</dd></div></dl><p v-if="!studyEvidenceEntries(row.source).length">No source details recorded.</p></section>
                <section><h4>Recorded evidence</h4><dl class="study-evidence"><div v-for="entry in studyEvidenceEntries(row.evidence)" :key="entry.label"><dt>{{ entry.label }}</dt><dd>{{ entry.value }}</dd></div></dl><p v-if="!studyEvidenceEntries(row.evidence).length">No additional evidence recorded.</p></section>
              </div></td></tr>
            </template>
          </tbody></table></div>
        </section>
        <details class="study-panel study-methodology"><summary>How the study is measured</summary><p>The original signal, entry area, target and invalidation stay fixed. The available time shows when the app had the source evidence. A repeated same-level confirmation adds evidence to its existing plan.</p><p>The strict outcome asks whether an observed close reached the recorded target before invalidation. Favorable moves of 0.5R and 1R are separate measurements. A smaller favorable move can occur even when the target is never reached. Best and worst closes measure the price path from the signal.</p><p>Each rate shows its own eligible-plan count. Unfinished or insufficient follow-up does not count as a failed target. No-data rates appear as a dash.</p><dl v-if="studyEvidenceEntries(report.methodology).length" class="study-evidence"><div v-for="entry in studyEvidenceEntries(report.methodology)" :key="entry.label"><dt>{{ entry.label }}</dt><dd>{{ entry.value }}</dd></div></dl></details>
      </template>
    </main>
  </AppLayout>
</template>

<style scoped>
.study-page{max-width:1540px;margin:auto;padding:30px 26px 64px;color:#eaf0fb}.study-heading,.study-actions,.study-records-heading,.study-meta{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}.study-heading{align-items:flex-start}.study-eyebrow{font-size:.68rem;letter-spacing:.14em;color:#a8b9d8;margin:0 0 8px}h1{font-size:1.85rem;line-height:1.2;font-weight:700;margin:0}h2{font-size:1rem;font-weight:650}p{font-size:.83rem;line-height:1.65;color:#b9c8de;margin:8px 0}.study-heading>div>p:last-child{margin-bottom:0}.study-actions{justify-content:flex-end;gap:9px}.study-button{padding:10px 14px;border:1px solid #596780;border-radius:7px;background:#293248;color:#e7edfa;font-size:.8rem;font-weight:600;white-space:nowrap}.study-button:hover:enabled{border-color:#a9b8df;background:#323d55}.study-button--primary{background:#6265b0;border-color:#9295db;color:#fff}.study-button--primary:hover:enabled{background:#7477c4}.study-button:disabled{opacity:.5;cursor:not-allowed}.study-notice{margin:22px 0 0;border-left:3px solid #9c9eff;padding:10px 14px;background:#242b41;color:#d7e0f0;border-radius:0 6px 6px 0}.study-cohorts{display:flex;gap:8px;flex-wrap:wrap;margin:24px 0 14px}.study-cohorts button{padding:11px 17px;border:1px solid #52617e;border-radius:8px;background:#252e43;color:#e3eaff;font-size:.84rem}.study-cohorts button[aria-pressed=true]{background:#3b456a;border-color:#b7b9ff}.study-panel{background:#1b2131;border:1px solid #414f68;border-radius:10px}.study-filters{display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;padding:17px}.study-filters label{font-size:.74rem;color:#becce0;display:grid;gap:7px;flex:1;min-width:145px}.study-filters input,.study-filters select{width:100%;border:1px solid #52617a;border-radius:6px;background:#252e43;color:#e3eaff;padding:9px 11px;font-size:.83rem;color-scheme:dark;min-height:40px}.study-filters input::placeholder{color:#9cabc5}.study-filters input[aria-label=Symbol]{text-transform:uppercase}.study-filter-error{flex-basis:100%;color:#ffb3a7;margin:0}.study-meta{gap:2px 16px;margin:10px 0}.study-meta p{font-size:.72rem;margin:0;color:#aebdd4}.study-success{color:#a7e5c5}.study-error{border-color:#bb756e;padding:17px;margin:18px 0;display:flex;align-items:center;justify-content:space-between;gap:16px}.study-error p{color:#ffc0b6}.study-warning{background:#3b3424;border:1px solid #756547;border-radius:7px;color:#ead7ad;padding:11px 14px;font-size:.8rem;margin:12px 0;line-height:1.6}.study-counts{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;margin:20px 0 14px}.study-counts>div{padding:15px 17px}.study-counts span{font-size:.73rem;color:#bcc9dd;display:block}.study-counts strong{font-size:1.5rem;display:block;margin-top:7px;font-variant-numeric:tabular-nums}.study-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.study-metric{padding:18px}.study-metric h2{font-size:.8rem;color:#c9d5e9;font-weight:500}.study-metric strong{font-size:1.8rem;font-weight:650;display:block;margin-top:9px;font-variant-numeric:tabular-nums}.study-metric:first-child strong{color:#c5c4ff}.study-metric p{font-size:.75rem;margin:4px 0}.study-metric>span{font-size:.7rem;color:#9bacc7}.study-method-note{font-size:.75rem;margin:14px 0 22px;max-width:1000px}.study-records{overflow:hidden}.study-records-heading{padding:18px 20px}.study-records-heading p{margin:5px 0 0;font-size:.75rem}.study-records-heading>span{font-size:.73rem;color:#bac9df}.study-table-scroll{overflow-x:auto}.study-table{border-collapse:collapse;width:100%;text-align:left;font-size:.76rem;min-width:1050px}.study-table thead{background:#252d40;color:#b8c8e0}.study-table th,.study-table td{padding:15px 16px;vertical-align:top}.study-table thead th{font-weight:500;white-space:nowrap}.study-table tbody tr{border-top:1px solid #39455d}.study-table tbody th{font-weight:500}.study-table tbody th strong{font-size:.94rem}.study-direction,.study-cell-line{display:block;margin-top:6px;font-size:.7rem;color:#b9c8de}.study-direction.bullish{color:#9cddbb}.study-direction.bearish{color:#efa48e}.study-tabular{font-variant-numeric:tabular-nums;white-space:nowrap}.study-state{display:inline-block;border:1px solid #53627d;background:#2a344a;padding:4px 7px;border-radius:5px;white-space:nowrap;font-size:.69rem}.study-state--stale{border-color:#806d46;color:#eed6a4;background:#3b3425}.study-row-details summary{cursor:pointer;color:#c4c9ff;white-space:nowrap;font-size:.76rem;padding:3px 0}.study-row-details[open] summary{margin-bottom:14px}.study-detail-content{width:480px;max-width:70vw;background:#222b3d;border:1px solid #4c5a73;border-radius:8px;padding:18px;white-space:normal}.study-detail-content h3{font-size:.95rem;font-weight:650;margin:0 0 18px}.study-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.study-detail-content dt,.study-evidence dt{font-size:.7rem;color:#b7c7df}.study-detail-content dd{font-size:.8rem;color:#e4ecfa;margin-top:4px;overflow-wrap:anywhere}.study-detail-note{font-size:.72rem;margin:18px 0}.study-detail-content section{border-top:1px solid #47546b;margin-top:17px;padding-top:16px}.study-detail-content h4{font-size:.8rem;font-weight:650;margin-bottom:12px}.study-evidence{display:grid;gap:11px;margin-top:14px}.study-evidence>div{display:grid;grid-template-columns:minmax(120px,1fr) minmax(0,2fr);gap:14px}.study-evidence dd{font-size:.74rem;color:#dce7fa;margin:0;overflow-wrap:anywhere;white-space:pre-wrap}.study-empty{padding:34px 22px;text-align:center}.study-empty h2,.study-empty h3{font-size:1rem;font-weight:600}.study-empty p{font-size:.8rem}.study-methodology{margin-top:22px;padding:18px 20px}.study-methodology summary{font-size:.84rem;color:#d6e5ff;cursor:pointer}.study-methodology p{max-width:1050px;font-size:.77rem;margin-top:14px}.study-methodology .study-evidence{border-top:1px solid #3d4b64;padding-top:16px}button:focus-visible,input:focus-visible,select:focus-visible,summary:focus-visible{outline:2px solid #b6baff;outline-offset:3px}@media(max-width:1000px){.study-counts{grid-template-columns:repeat(3,minmax(0,1fr))}.study-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:550px){.study-page{padding:23px 14px 42px}.study-heading{gap:20px}.study-actions{width:100%;justify-content:stretch}.study-actions button{flex:1}.study-cohorts{gap:7px}.study-cohorts button{padding:10px 12px;font-size:.76rem}.study-filters{padding:14px;gap:12px}.study-filters label{min-width:120px}.study-filters button{width:100%}.study-counts{gap:8px;grid-template-columns:repeat(2,minmax(0,1fr))}.study-counts>div{padding:14px}.study-metrics{gap:9px}.study-metric{padding:14px 12px}.study-metric h2{line-height:1.5;min-height:2.4em;font-size:.74rem}.study-metric strong{font-size:1.55rem}.study-metric p{font-size:.69rem}.study-meta{margin-top:12px}.study-detail-content{max-width:85vw;width:370px}.study-detail-grid{grid-template-columns:1fr}.study-evidence>div{grid-template-columns:1fr;gap:3px}.study-error{align-items:flex-start;flex-direction:column}}
.study-breakdowns{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin:0 0 22px}.study-breakdown{min-width:0;overflow:hidden}.study-breakdown h2{padding:17px 18px}.study-breakdown table{width:100%;min-width:420px;text-align:left;font-size:.74rem;border-collapse:collapse}.study-breakdown th,.study-breakdown td{padding:12px 14px;border-top:1px solid #39455d;font-weight:400;vertical-align:top}.study-breakdown thead{background:#252d40;color:#bac9df}.study-breakdown tbody th{color:#e4ebfa;font-weight:550;max-width:200px}.study-breakdown small{display:block;color:#a8bad3;font-size:.65rem;margin-top:5px;white-space:nowrap}.study-inspect{cursor:pointer;color:#c4c9ff;white-space:nowrap;font-size:.76rem;padding:3px 0;background:none;border:0;text-decoration:underline;text-underline-offset:4px}.study-table .study-expanded-row td{padding:18px 16px;background:#20283a}.study-detail-content{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 28px;width:min(1150px,calc(100vw - 120px));max-width:none;min-width:280px;padding:20px}.study-detail-content h3,.study-detail-grid,.study-detail-note{grid-column:1 / -1}.study-detail-content h3,.study-detail-note{margin:0}.study-detail-grid{grid-template-columns:repeat(4,minmax(0,1fr));gap:19px}.study-detail-content section{min-width:0;margin:0}.study-detail-content .study-evidence>div{grid-template-columns:minmax(100px,1fr) minmax(0,2fr)}@media(max-width:1000px){.study-breakdowns{grid-template-columns:1fr}.study-detail-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:650px){.study-detail-content{width:calc(100vw - 64px);grid-template-columns:1fr;padding:16px;gap:18px}.study-detail-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.study-detail-content .study-evidence>div{grid-template-columns:1fr;gap:4px}.study-detail-content h3,.study-detail-grid,.study-detail-note{grid-column:auto}}
.study-preview-control{margin:13px 0 0}.study-preview-toggle{font-size:.78rem;color:#c4c9ff;text-decoration:underline;text-underline-offset:4px;padding:5px 0}.study-preview-toggle:disabled{opacity:.5;cursor:not-allowed}.study-demo{background:#2c2949;border:1px solid #8375ba;border-radius:8px;padding:16px 18px;margin-top:14px;color:#e5dfff}.study-demo strong{font-size:.87rem;font-weight:650}.study-demo p{color:#d2c7f2;font-size:.8rem;margin:6px 0 0}
.study-section-heading{margin:22px 0 13px}.study-section-heading h2{font-size:.94rem}.study-section-heading p{font-size:.74rem;margin:5px 0 0}.study-availability{display:grid;grid-template-columns:minmax(200px,1fr) minmax(0,2fr);gap:24px;padding:18px 20px;margin:0 0 22px}.study-availability h2{font-size:.9rem}.study-availability p{font-size:.74rem;margin-bottom:0}.study-availability dl{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:20px}.study-availability dt{font-size:.7rem;color:#bac9df;line-height:1.5}.study-availability dd{font-size:1.35rem;font-weight:650;margin:7px 0 4px;font-variant-numeric:tabular-nums}.study-availability small{display:block;font-size:.66rem;color:#a7b9d2;line-height:1.5}.study-timing-late{color:#edc895}.study-timing-detail{border-top:1px solid #46546c;padding-top:18px;grid-template-columns:repeat(3,minmax(0,1fr))}@media(max-width:800px){.study-availability{grid-template-columns:1fr;gap:18px}}@media(max-width:550px){.study-availability{padding:16px}.study-availability dl{grid-template-columns:repeat(2,minmax(0,1fr));gap:17px}.study-timing-detail{grid-template-columns:1fr}}
</style>
