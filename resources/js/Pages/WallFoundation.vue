<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import axios from 'axios'
import { wallCoverage, formatWallCoverage } from '@/lib/wall-levels'
import AppLayout from '@/Layouts/AppLayout.vue'
import UiLoading from '@/Components/UI/UiLoading.vue'

const props = defineProps({ productionCaptureAvailable: Boolean })
const dataset = ref(props.productionCaptureAvailable ? 'production_capture' : 'local_review')
const view = ref('latest_eod')
const symbol = ref('SPY')
const report = ref(null)
const loading = ref(false)
const error = ref('')
let controller = null
let sequence = 0
const selected = computed(() => report.value?.snapshots.find(s => s.scope.symbol === symbol.value))
const reasonLabel = reason => ({ missing_contract_inputs: 'Contract input coverage', missing_expirations: 'Expiry coverage', source_coverage_unknown: 'Source coverage details not supplied', snapshot_unavailable: 'No source data available' }[reason] || reason.replaceAll('_', ' '))
const states = { unavailable: 'Unavailable', partial: 'Data available', review_only: 'Inputs reconciled' }
const format = value => value == null ? 'Unavailable' : new Intl.NumberFormat('en-US', {
  notation: 'compact', maximumFractionDigits: 2,
}).format(value)
const bytes = value => value == null ? 'Unavailable' : value >= 1048576
  ? `${(value / 1048576).toFixed(1)} MB` : `${(value / 1024).toFixed(1)} KB`
const time = value => value ? new Date(value).toLocaleString('en-US', { timeZone: 'America/New_York' }) + ' ET' : 'Unavailable'

async function load() {
  const owner = ++sequence
  controller?.abort()
  controller = new AbortController()
  loading.value = true
  error.value = ''
  report.value = null
  try {
    const response = await axios.get('/api/wall-foundation/audit', {
      params: { dataset: dataset.value, view: view.value }, signal: controller.signal,
    })
    if (owner === sequence) report.value = response.data
  } catch (e) {
    if (owner === sequence && e.code !== 'ERR_CANCELED') {
      error.value = e.response?.data?.message || 'The audit could not load. Please retry.'
    }
  } finally {
    if (owner === sequence) loading.value = false
  }
}

function download() {
  if (!report.value) return
  const url = URL.createObjectURL(new Blob([JSON.stringify(report.value, null, 2)], { type: 'application/json' }))
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = `wall-foundation-${dataset.value}-${view.value}.json`
  anchor.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

watch([dataset, view], load, { immediate: true })
onBeforeUnmount(() => { sequence++; controller?.abort() })
</script>

<template>
  <AppLayout title="Wall foundation review">
    <div class="wall-review gex-ui" data-theme="dark">
      <header class="review-heading">
        <div>
          <p class="eyebrow">WALL INTELLIGENCE · BATCH 1</p>
          <h1>Check the evidence first.</h1>
          <p class="muted">Local verification of source data, calculation units, and the foundation for wall history.</p>
        </div>
        <span class="tag">Local review · no setups</span>
      </header>

      <section class="review-controls panel" aria-label="Audit controls">
        <label>Data source
          <select v-model="dataset" aria-label="Data source">
            <option v-if="productionCaptureAvailable" value="production_capture">Recorded production capture</option>
            <option value="local_review">Local review database</option>
          </select>
        </label>
        <label>Expiry view
          <select v-model="view" aria-label="Expiry view">
            <option value="latest_eod">Latest EOD data</option>
            <option value="next_session">Next-session preparation</option>
          </select>
        </label>
        <div><span class="muted">Horizon</span><strong>2W / 14 calendar days</strong></div>
        <button class="review-button" :disabled="loading || !report" @click="download">Download audit JSON</button>
      </section>

      <UiLoading v-if="loading" title="Checking SPY, QQQ and TSLA" message="Reading the selected stored updates. No market-data refresh is started." layout="table" />
      <div v-else-if="error" role="alert" class="panel error-state">
        <p>{{ error }}</p><button class="review-button" @click="load">Retry audit</button>
      </div>

      <template v-else-if="report">
        <section class="source-note" aria-label="Source provenance">
          <p>{{ report.source_note }}</p>
          <p class="muted">Captured {{ time(report.captured_at) }} · Report generated {{ time(report.generated_at) }}</p>
          <p v-if="dataset === 'local_review' && report.review_clock" class="muted">Local market replay clock: {{ time(report.review_clock) }}</p>
        </section>

        <div class="symbol-grid" aria-label="Select audit symbol">
          <button v-for="snapshot in report.snapshots" :key="snapshot.scope.symbol"
            class="symbol-card" :class="{ selected: symbol === snapshot.scope.symbol }"
            :aria-pressed="symbol === snapshot.scope.symbol" @click="symbol = snapshot.scope.symbol">
            <strong>{{ snapshot.scope.symbol }}</strong>
            <span>{{ states[snapshot.quality.state] }}</span>
            <small>{{ snapshot.provenance.source_date || 'No source data' }} · {{ snapshot.reconciliation.strike_count }} strikes</small>
          </button>
        </div>

        <template v-if="selected">
          <section class="panel">
            <div class="section-heading"><h2>{{ symbol }} · scope and readiness</h2><span class="tag warning">Actionable output unavailable</span></div>
            <div class="facts-grid">
              <div><span>Source date</span><strong>{{ selected.provenance.source_date || 'Unavailable' }}</strong></div>
              <div><span>Analysis session</span><strong>{{ selected.analysis_session || 'Unavailable' }}</strong></div>
              <div><span>Included expiries</span><strong>{{ selected.scope.expiry_set.length }}</strong></div>
              <div><span>Input coverage</span><strong>{{ wallCoverage(selected.quality.source_coverage) == null ? 'See source details' : `${formatWallCoverage(wallCoverage(selected.quality.source_coverage))} of OI with gamma` }}</strong></div>
            </div>
            <p class="muted explanation">Input reconciliation checks the recorded response. It does not certify source freshness, dealer positions, or a trading setup.</p>
            <div v-if="selected.scope.expiry_set.length" class="expiry-list" aria-label="Included expiry dates">
              <span v-for="date in selected.scope.expiry_set" :key="date" class="tag">{{ date }}</span>
            </div>
            <p v-if="selected.quality.source_coverage" class="muted explanation">
              {{ selected.quality.source_coverage.missing_input_rows ?? 'Unknown' }} of {{ selected.quality.source_coverage.source_rows ?? 'unknown' }} contract rows are excluded from the calculation because required values were not supplied.
              <template v-if="selected.quality.source_coverage.missing_gamma_oi_share != null">Contracts without gamma represent {{ (selected.quality.source_coverage.missing_gamma_oi_share * 100).toFixed(2) }}% of recorded open interest.</template>
              This is an input-coverage measure, not an estimate of missing GEX.
            </p>
            <details v-if="selected.quality.reasons.length" class="finding"><summary>Source quality details</summary><ul class="reason-list">
              <li v-for="reason in selected.quality.reasons" :key="reason">{{ reasonLabel(reason) }}</li>
            </ul></details>
          </section>

          <section class="panel">
            <div class="section-heading"><h2>Same inputs. Explicit units.</h2><span class="tag">New contract: {{ selected.schema_version }}</span></div>
            <div class="comparison-grid">
              <article class="metric"><span>Legacy net GEX · original units</span><strong>{{ format(selected.measurements.raw_net_gex) }}</strong><small>Existing dashboard and exports remain unchanged.</small></article>
              <article class="metric accented" :class="{ negative: selected.measurements.available_input_net_gex_per_1pct < 0 }"><span>Available-input GEX · USD per 1% move</span><strong>{{ format(selected.measurements.available_input_net_gex_per_1pct) }}</strong><small>Legacy value × 0.01. A unit conversion, not a change in exposure.</small></article>
            </div>
            <p class="muted explanation">Values use the recorded source inputs. Coverage details explain the calculation scope; the export preserves source quality and calculation eligibility.</p>
            <div class="facts-grid">
              <div><span>Recorded put wall</span><strong>{{ selected.measurements.put_wall ?? 'Unavailable' }}</strong></div>
              <div><span>Recorded call wall</span><strong>{{ selected.measurements.call_wall ?? 'Unavailable' }}</strong></div>
              <div><span>Call − put = net</span><strong>{{ selected.reconciliation.call_minus_put_matches_net == null ? 'Unavailable' : selected.reconciliation.call_minus_put_matches_net ? 'Pass' : 'Mismatch' }}</strong></div>
              <div><span>Raw rows retained</span><strong>{{ selected.reconciliation.strike_count }}</strong></div>
            </div>
          </section>

          <section class="panel">
            <h2>What is still unavailable</h2>
            <p class="muted explanation">Missing information stays unavailable. A source date is never substituted for an exact observation time.</p>
            <dl class="missing-list">
              <template v-for="(reason, field) in selected.unavailable_reasons" :key="field">
                <dt>{{ field.replaceAll('_', ' ') }}</dt><dd>{{ reason }}</dd>
              </template>
            </dl>
            <p class="muted explanation">Legacy HVL: {{ selected.measurements.legacy_hvl ?? 'Unavailable' }}. Modeled gamma flip: Unavailable.</p>
          </section>
        </template>

        <section class="panel">
          <h2>Calculation audit findings</h2>
          <details v-for="finding in report.findings" :key="finding.code" class="finding" :open="finding.code === 'gex_units'">
            <summary>{{ finding.title }} <span class="muted">Batch {{ finding.next_batch }}</span></summary>
            <p>{{ finding.detail }}</p><small class="muted">Code source: {{ finding.source }}</small>
          </details>
        </section>

        <section class="panel">
          <div class="section-heading"><h2>Observation storage</h2><span class="tag">Automatic collection off</span></div>
          <div class="facts-grid">
            <div><span>Storage schema</span><strong>{{ report.storage.ready ? 'Installed locally' : 'Migration pending' }}</strong></div>
            <div><span>Stored audit captures · this source</span><strong>{{ report.storage.audit_records }}</strong></div>
            <div><span>Eligible historical outcomes</span><strong>{{ report.storage.outcome_eligible_records }}</strong></div>
            <div><span>Selected report payloads</span><strong>{{ bytes(report.storage.payload_bytes_for_selected_scope) }}</strong></div>
          </div>
          <p class="muted explanation">Audit captures preserve what was inspected, including raw values. They are not reconstructed intraday observations. Identical evidence is stored once; corrections append a new record.</p>
          <details class="finding"><summary>Collection and retention planning</summary>
            <p>{{ report.storage.planning.symbols }} symbols × 1 horizon × {{ report.storage.planning.interval_minutes }}-minute intervals = {{ report.storage.planning.observations_per_session }} observations per regular session.</p>
            <p>Estimated payload size: {{ bytes(report.storage.planning.estimated_payload_bytes_per_session) }} per session. Proposed retention: {{ report.storage.planning.retention_days }} days.</p>
            <p class="muted">{{ report.storage.planning.note }}</p>
          </details>
        </section>

        <footer class="source-note">
          <strong>No additional API subscription needed for Batch 1.</strong>
          <p class="muted">This report makes no provider requests. {{ report.provider_access.future_entitlements }}</p>
          <a href="/dashboard" class="review-link">Open the existing dashboard →</a>
        </footer>
      </template>
    </div>
  </AppLayout>
</template>

<style scoped>
.wall-review { max-width: 1220px; margin: 0 auto; padding: 30px 24px 64px; color: #e9edf5; }
.review-heading, .section-heading { display: flex; justify-content: space-between; align-items: center; gap: 18px; }
.review-heading { margin-bottom: 24px; }
.eyebrow { color: #83baff; font-size: 11px; letter-spacing: .12em; font-weight: 700; margin-bottom: 8px; }
h1 { font-size: clamp(24px, 3vw, 34px); font-weight: 700; margin-bottom: 8px; }
h2 { font-size: 17px; font-weight: 650; }
.muted, .facts-grid span, .metric span, .metric small { color: #b4bdce; }
.panel { padding: 22px; margin-top: 18px; border: 1px solid #343d50; border-radius: 14px; background: #191e2a; }
.review-controls { display: flex; gap: 18px; align-items: end; flex-wrap: wrap; }
.review-controls label { display: grid; gap: 7px; font-size: 12px; color: #b4bdce; flex: 1; min-width: 180px; }
.review-controls select { background: #242b3b; color: #edf2ff; border: 1px solid #43506b; border-radius: 8px; min-height: 42px; padding-right: 34px; }
.review-controls strong { display: block; font-size: 14px; margin-top: 8px; min-height: 35px; }
.review-button { padding: 10px 14px; border: 1px solid #52627f; border-radius: 8px; background: #243149; font-size: 13px; min-height: 42px; }
.review-button:disabled { opacity: .5; cursor: default; }
.source-note { margin: 22px 0; font-size: 13px; line-height: 1.7; }
.tag { display: inline-block; font-size: 11px; color: #b8d7ff; background: #202e43; border: 1px solid #486484; padding: 4px 9px; border-radius: 20px; }
.warning { color: #ead58e; border-color: #716237; background: #302c22; }
.symbol-grid { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 12px; }
.symbol-card { display: grid; gap: 7px; padding: 18px; text-align: left; border: 1px solid #3c465b; border-radius: 12px; background: #191e2a; }
.symbol-card strong { font-size: 20px; }.symbol-card span { font-size: 13px; }.symbol-card small { color: #b4bdce; font-size: 12px; }
.symbol-card.selected { border-color: #8bbcff; box-shadow: inset 3px 0 #8bbcff; background: #202b3c; }
.facts-grid { display: grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap: 20px; margin-top: 22px; }
.facts-grid span, .facts-grid strong { display: block; }.facts-grid span { font-size: 12px; margin-bottom: 8px; }.facts-grid strong { font-size: 15px; overflow-wrap: anywhere; }
.explanation { font-size: 13px; line-height: 1.6; margin-top: 16px; }
.expiry-list { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 15px; }
.reason-list { margin-top: 12px; padding-left: 18px; list-style: disc; color: #e4ce98; font-size: 13px; }
.comparison-grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 16px; margin-top: 20px; }
.metric { padding: 18px; border: 1px solid #3b4558; border-radius: 10px; }.metric span,.metric strong,.metric small { display: block; }.metric span { font-size: 12px; }.metric strong { font-size: 30px; margin: 10px 0; }.metric small { font-size: 12px; line-height: 1.5; }.metric.accented { border-top: 2px solid #77d4b1; }.metric.accented strong { color: #85dfbd; }
.metric.accented.negative { border-top-color: #eca28f; }.metric.accented.negative strong { color: #f2ac99; }
.missing-list { display: grid; grid-template-columns: minmax(130px,1fr) 3fr; gap: 12px 20px; font-size: 13px; line-height: 1.6; margin-top: 18px; }.missing-list dt { text-transform: capitalize; color: #dce6fa; }.missing-list dd { color: #b4bdce; }
.finding { padding: 16px 0; border-bottom: 1px solid #343d50; font-size: 13px; line-height: 1.7; }.finding:last-child { border: 0; padding-bottom: 0; }.finding summary { cursor: pointer; font-weight: 600; }.finding summary span { margin-left: 10px; font-weight: 400; }.finding p { margin: 12px 0 6px; }.review-link { display: inline-block; margin-top: 14px; color: #a2caff; }
.error-state { color: #f4b1a6; }.error-state button { margin-top: 12px; }
button:focus-visible, select:focus-visible, summary:focus-visible, a:focus-visible { outline: 2px solid #9bc8ff; outline-offset: 4px; }
@media(max-width:700px) { .wall-review { padding: 22px 14px 40px; }.review-heading,.section-heading { align-items: flex-start; flex-direction: column; gap: 10px; }.panel { padding: 17px; }.facts-grid { grid-template-columns: repeat(2,minmax(0,1fr)); }.comparison-grid { grid-template-columns: 1fr; }.symbol-grid { gap: 8px; }.symbol-card { padding: 13px 10px; }.symbol-card strong { font-size: 17px; }.missing-list { grid-template-columns: 1fr; gap: 5px; }.missing-list dd { margin-bottom: 12px; }.review-controls label { min-width: 100%; }.tag { overflow-wrap: anywhere; max-width: 100%; } }
</style>
