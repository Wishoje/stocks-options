<script setup>
import { computed, ref } from 'vue'
import UiBadge from '../UI/UiBadge.vue'
import UiButton from '../UI/UiButton.vue'
import UiDataTable from '../UI/UiDataTable.vue'
import UiHelpDialog from '../UI/UiHelpDialog.vue'
import UiMetric from '../UI/UiMetric.vue'
import UiPanel from '../UI/UiPanel.vue'
import UiStatus from '../UI/UiStatus.vue'
import { numeric } from '../UI/numbers.js'
import IntradayFlowChart from './IntradayFlowChart.vue'
import {
  completeVolumeTotal,
  compactUnsigned,
  compactUsd,
  normalizeFlowRows,
  ratioLabel,
} from './intradayFlowUtils.js'

const props = defineProps({
  symbol: { type: String, required: true },
  dataSymbol: { type: String, default: '' },
  totals: { type: Object, default: () => ({}) },
  rows: { type: Array, default: () => [] },
  summary: { type: Object, default: () => ({}) },
  responseMeta: { type: Object, default: () => ({}) },
  snapshotMeta: { type: Object, default: () => ({}) },
  sourceAgeSeconds: { type: Number, default: null },
  sourceLabel: { type: String, default: '' },
  nextOpenLabel: { type: String, default: '' },
  loading: Boolean,
  refreshing: Boolean,
  error: { type: String, default: '' },
})

const emit = defineEmits(['refresh', 'retry', 'reading-inspected'])
const snapshotDetailsOpen = ref(false)
const strikeDetailsOpen = ref(false)

function owns(object, key) {
  return object && typeof object === 'object' && Object.prototype.hasOwnProperty.call(object, key)
}

function firstValue(...values) {
  return values.find(value => value !== undefined && value !== null) ?? null
}

function firstNumber(...values) {
  for (const value of values) {
    const number = numeric(value)
    if (number != null) return number
  }
  return null
}

function yesNo(value) {
  return typeof value === 'boolean' ? (value ? 'Yes' : 'No') : 'Unavailable'
}

function booleanOrNull(value) {
  return typeof value === 'boolean' ? value : null
}

function exact(value) {
  if (value == null || value === '') return 'Unavailable'
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

function timestampEt(value) {
  if (!value) return 'Unavailable'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return String(value)
  return new Intl.DateTimeFormat('en-US', {
    timeZone: 'America/New_York',
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    second: '2-digit',
    hour12: true,
  }).format(date) + ' ET'
}

function safeJson(value) {
  try {
    return JSON.stringify(value, null, 2)
  } catch {
    return 'Returned values could not be serialized.'
  }
}

const normalizedSymbol = computed(() => String(props.symbol || '').trim().toUpperCase())
const normalizedDataSymbol = computed(() => String(props.dataSymbol || '').trim().toUpperCase())
const symbolAligned = computed(() => !normalizedDataSymbol.value || normalizedDataSymbol.value === normalizedSymbol.value)
const alignedRows = computed(() => symbolAligned.value ? props.rows : [])

const summaryTotals = computed(() => props.summary?.totals && typeof props.summary.totals === 'object'
  ? props.summary.totals
  : {})
const snapshotTotals = computed(() => props.snapshotMeta?.totals && typeof props.snapshotMeta.totals === 'object'
  ? props.snapshotMeta.totals
  : {})

const callVolume = computed(() => firstNumber(
  props.totals.call_vol,
  props.totals.call_volume_total,
  snapshotTotals.value.call_vol,
  summaryTotals.value.call_vol,
))
const putVolume = computed(() => firstNumber(
  props.totals.put_vol,
  props.totals.put_volume_total,
  snapshotTotals.value.put_vol,
  summaryTotals.value.put_vol,
))
const totalVolume = computed(() => completeVolumeTotal(
  firstNumber(props.totals.total, snapshotTotals.value.total, summaryTotals.value.total),
  callVolume.value,
  putVolume.value,
))
const premium = computed(() => firstNumber(
  props.totals.premium,
  props.totals.premium_total,
  snapshotTotals.value.premium,
  summaryTotals.value.premium,
))
const suppliedPcr = computed(() => firstNumber(
  props.totals.pcr_vol,
  props.totals.pcr_volume,
  snapshotTotals.value.pcr_vol,
  summaryTotals.value.pcr_vol,
))
const pcr = computed(() => suppliedPcr.value ?? (
  callVolume.value != null && callVolume.value > 0 && putVolume.value != null
    ? putVolume.value / callVolume.value
    : null
))

const marketOpen = computed(() => owns(props.responseMeta, 'open')
  ? booleanOrNull(props.responseMeta.open)
  : owns(props.snapshotMeta, 'open')
    ? booleanOrNull(props.snapshotMeta.open)
    : owns(props.summary, 'open')
      ? booleanOrNull(props.summary.open)
      : null)
const snapshotAvailable = computed(() => owns(props.responseMeta, 'snapshotAvailable')
  ? booleanOrNull(props.responseMeta.snapshotAvailable)
  : owns(props.snapshotMeta, 'snapshot_available')
    ? booleanOrNull(props.snapshotMeta.snapshot_available)
    : owns(props.summary, 'snapshot_available')
      ? booleanOrNull(props.summary.snapshot_available)
      : null)
const sourceAsOf = computed(() => {
  if (owns(props.responseMeta, 'sourceAsOf')) return props.responseMeta.sourceAsOf
  const explicitSourceContract = ['source_asof', 'source_timestamp_complete', 'source_timestamp_status']
    .some(field => owns(props.snapshotMeta, field) || owns(props.summary, field))
  if (explicitSourceContract) return firstValue(props.snapshotMeta.source_asof, props.summary.source_asof)
  return firstValue(props.snapshotMeta.asof, props.summary.asof)
})
const rawAsOf = computed(() => firstValue(
  props.responseMeta.rawAsOf,
  props.snapshotMeta.asof,
  props.summary.asof,
))
const tradeDate = computed(() => firstValue(
  props.responseMeta.tradeDate,
  props.snapshotMeta.trade_date,
  props.summary.trade_date,
))
const receivedAt = computed(() => firstValue(
  props.responseMeta.receivedAt,
  props.snapshotMeta.received_at,
  props.summary.received_at,
))
const capturedAt = computed(() => firstValue(
  props.responseMeta.capturedAt,
  props.snapshotMeta.captured_at,
  props.summary.captured_at,
))
const ingestionCompletedAt = computed(() => firstValue(
  props.responseMeta.ingestionCompletedAt,
  props.snapshotMeta.ingestion_completed_at,
  props.summary.ingestion_completed_at,
))
const marketSession = computed(() => firstValue(
  props.responseMeta.marketSession,
  props.snapshotMeta.market_session,
  props.summary.market_session,
) || {})
const marketPhase = computed(() => marketSession.value?.state ?? marketSession.value?.phase ?? null)
const nextOpen = computed(() => firstValue(
  props.responseMeta.nextOpen,
  marketSession.value?.next_open_at,
))
const refreshEligible = computed(() => owns(props.responseMeta, 'refreshEligible')
  ? props.responseMeta.refreshEligible
  : firstValue(props.snapshotMeta.refresh_eligible, props.summary.refresh_eligible))
const refreshReason = computed(() => firstValue(
  props.responseMeta.refreshReason,
  props.snapshotMeta.refresh_reason,
  props.summary.refresh_reason,
))
const sourceTimestampStatus = computed(() => firstValue(
  props.responseMeta.sourceTimestampStatus,
  props.snapshotMeta.source_timestamp_status,
  props.summary.source_timestamp_status,
))
const legacySourceTime = computed(() => String(sourceTimestampStatus.value || '').toLowerCase() === 'legacy')

const hasPayload = computed(() => {
  if (!symbolAligned.value) return false
  if (snapshotAvailable.value !== null) return snapshotAvailable.value
  return alignedRows.value.length > 0
    || [callVolume.value, putVolume.value, premium.value].some(value => value != null && value !== 0)
})

const ageSeconds = computed(() => {
  if (props.sourceAgeSeconds != null && Number.isFinite(props.sourceAgeSeconds)) return Math.max(0, props.sourceAgeSeconds)
  if (!sourceAsOf.value) return null
  const time = new Date(sourceAsOf.value).getTime()
  return Number.isFinite(time) ? Math.max(0, Math.floor((Date.now() - time) / 1000)) : null
})
const calculatedSourceLabel = computed(() => {
  if (marketOpen.value === false) return 'Market closed'
  if (legacySourceTime.value) {
    if (ageSeconds.value == null) return 'Legacy response time'
    return ageSeconds.value < 90 ? 'Recent legacy response' : `Legacy response (${Math.floor(ageSeconds.value / 60)}m old)`
  }
  if (props.sourceLabel) return props.sourceLabel
  if (ageSeconds.value == null) return 'Provider time unavailable'
  return ageSeconds.value < 90 ? 'Live' : `Delayed (${Math.floor(ageSeconds.value / 60)}m)`
})
const sourceTone = computed(() => {
  if (marketOpen.value === false || legacySourceTime.value || ageSeconds.value == null || ageSeconds.value >= 90) return 'warning'
  return 'positive'
})
const pcrTone = computed(() => pcr.value == null ? 'data' : pcr.value > 1 ? 'negative' : pcr.value < 1 ? 'positive' : 'data')
const pcrContext = computed(() => pcr.value == null
  ? 'Put volume divided by call volume is unavailable'
  : pcr.value > 1
    ? 'Put volume leads call volume'
    : pcr.value < 1
      ? 'Call volume leads put volume'
      : 'Call and put volume are balanced')
const refreshReasonLabel = computed(() => ({
  recently_completed: 'recently completed',
  refresh_due: 'due',
  market_closed: 'market closed',
  pending: 'pending',
  failure_backoff: 'in retry backoff',
}[refreshReason.value] ?? refreshReason.value ?? 'not due'))

const callShare = computed(() => totalVolume.value != null && totalVolume.value > 0 && callVolume.value != null
  ? callVolume.value / totalVolume.value * 100
  : null)
const putShare = computed(() => totalVolume.value != null && totalVolume.value > 0 && putVolume.value != null
  ? putVolume.value / totalVolume.value * 100
  : null)

const statusState = computed(() => {
  if (!symbolAligned.value || (props.loading && !hasPayload.value)) return 'loading'
  if (props.error) return 'error'
  if (props.refreshing) return 'loading'
  if (!hasPayload.value) return marketOpen.value === false ? 'closed' : 'preparing'
  if (marketOpen.value === false) return 'closed'
  if (!sourceAsOf.value || ageSeconds.value == null) return 'sparse'
  if (legacySourceTime.value) return 'sparse'
  return ageSeconds.value < 90 ? 'positive' : 'stale'
})
const statusTitle = computed(() => {
  if (!symbolAligned.value) return `Updating ${normalizedSymbol.value} intraday flow`
  if (props.loading && !hasPayload.value) return `Loading ${normalizedSymbol.value} intraday flow`
  if (props.error) return 'Intraday flow refresh failed'
  if (props.refreshing) return `Refreshing ${normalizedSymbol.value} intraday flow`
  if (!hasPayload.value) return marketOpen.value === false
    ? `No stored intraday snapshot for ${normalizedSymbol.value}`
    : `Waiting for ${normalizedSymbol.value} intraday flow`
  if (marketOpen.value === false) return 'Showing the last stored session snapshot'
  if (!sourceAsOf.value || ageSeconds.value == null) return 'Provider update time is unavailable'
  if (legacySourceTime.value) return ageSeconds.value < 90 ? 'Recent intraday response' : 'Delayed legacy intraday response'
  return ageSeconds.value < 90 ? 'Current intraday snapshot' : 'Delayed intraday snapshot'
})
const statusMessage = computed(() => {
  if (!symbolAligned.value) return 'The prior symbol remains hidden while the selected symbol loads.'
  if (props.loading && !hasPayload.value) return 'Loading the current session totals, source clock, and strike rows.'
  if (props.error) return hasPayload.value
    ? `${props.error} The last aligned snapshot remains visible below.`
    : props.error
  if (props.refreshing) return 'The displayed snapshot stays in place until the refresh completes.'
  if (!hasPayload.value && marketOpen.value === false) {
    return `The first snapshot can be collected ${props.nextOpenLabel || (nextOpen.value ? timestampEt(nextOpen.value) : 'during the next trading session')}.`
  }
  if (!hasPayload.value) return 'No completed snapshot is available yet. The dashboard will retry according to the current refresh state.'
  if (marketOpen.value === false) {
    return `Session updates resume ${props.nextOpenLabel || (nextOpen.value ? timestampEt(nextOpen.value) : 'during the next trading session')}.`
  }
  if (!sourceAsOf.value || ageSeconds.value == null) return 'The data is available, but the provider source clock was not supplied. No receipt or ingestion time is presented as market time.'
  if (legacySourceTime.value) return `Legacy response time ${timestampEt(sourceAsOf.value)}. Provider source time is unavailable in this response. Volume remains cumulative for the stored session across all returned expiries.`
  return `Source as of ${timestampEt(sourceAsOf.value)}. Volume is cumulative for the current stored session across all returned expiries.`
})

const tableRows = computed(() => normalizeFlowRows(alignedRows.value).map(row => ({
  row_key: row.__row_key,
  strike: row.__strike_raw,
  call_volume: row.__call_volume_raw,
  put_volume: row.__put_volume_raw,
  pcr: row.__pcr_raw,
  volume_over_oi: row.__vol_oi_raw,
  call_premium: row.__call_premium_raw,
  put_premium: row.__put_premium_raw,
  call_oi_eod: row.__call_oi_raw,
  put_oi_eod: row.__put_oi_raw,
  net_gex_live: row.__net_gex_live_raw,
  net_gex_delta: row.__net_gex_delta_raw,
})))
const tableColumns = [
  { key: 'strike', label: 'Strike', numeric: true, sortable: true },
  { key: 'call_volume', label: 'Call volume', numeric: true, sortable: true },
  { key: 'put_volume', label: 'Put volume', numeric: true, sortable: true },
  { key: 'pcr', label: 'Returned PCR', numeric: true, sortable: true },
  { key: 'volume_over_oi', label: 'Returned Vol/OI', numeric: true, sortable: true },
  { key: 'call_premium', label: 'Call premium', numeric: true, sortable: true },
  { key: 'put_premium', label: 'Put premium', numeric: true, sortable: true },
  { key: 'call_oi_eod', label: 'EOD call OI', numeric: true, sortable: true },
  { key: 'put_oi_eod', label: 'EOD put OI', numeric: true, sortable: true },
  { key: 'net_gex_live', label: 'Returned net_gex_live', numeric: true, sortable: true },
  { key: 'net_gex_delta', label: 'Returned net_gex_delta', numeric: true, sortable: true },
]

const exactTotals = computed(() => [
  { label: 'Call volume', value: firstValue(props.totals.call_vol, props.totals.call_volume_total, snapshotTotals.value.call_vol, summaryTotals.value.call_vol) },
  { label: 'Put volume', value: firstValue(props.totals.put_vol, props.totals.put_volume_total, snapshotTotals.value.put_vol, summaryTotals.value.put_vol) },
  { label: 'Returned total volume', value: firstValue(props.totals.total, snapshotTotals.value.total, summaryTotals.value.total) },
  { label: 'Returned volume PCR', value: firstValue(props.totals.pcr_vol, props.totals.pcr_volume, snapshotTotals.value.pcr_vol, summaryTotals.value.pcr_vol) },
  { label: 'Estimated premium notional', value: firstValue(props.totals.premium, props.totals.premium_total, snapshotTotals.value.premium, summaryTotals.value.premium) },
])
const metadataRows = computed(() => [
  { label: 'Selected symbol', value: normalizedSymbol.value || null },
  { label: 'Data symbol', value: normalizedDataSymbol.value || null },
  { label: 'Trade date', value: tradeDate.value },
  { label: legacySourceTime.value ? 'Legacy response time' : 'Provider source as of', value: sourceAsOf.value, timestamp: true },
  { label: 'Raw response as of', value: rawAsOf.value, timestamp: true },
  { label: 'Source timestamp status', value: sourceTimestampStatus.value },
  { label: 'Captured at', value: capturedAt.value, timestamp: true },
  { label: 'Received at', value: receivedAt.value, timestamp: true },
  { label: 'Ingestion completed at', value: ingestionCompletedAt.value, timestamp: true },
  { label: 'Snapshot available', value: yesNo(snapshotAvailable.value) },
  { label: 'Market open', value: yesNo(marketOpen.value) },
  { label: 'Market phase', value: marketPhase.value },
  { label: 'Refresh eligible', value: yesNo(refreshEligible.value) },
  { label: 'Refresh reason', value: refreshReason.value },
  { label: 'Next open', value: nextOpen.value, timestamp: true },
])
const returnedMetadataJson = computed(() => safeJson({
  summary: props.summary,
  response_meta: props.responseMeta,
  snapshot_meta: props.snapshotMeta,
  totals: props.totals,
}))
const returnedRowsJson = computed(() => safeJson(alignedRows.value))
</script>

<template>
  <section
    class="gex-ui intraday-flow"
    data-theme="dark"
    data-density="compact"
    aria-label="Intraday flow"
    data-testid="intraday-flow-panel"
  >
    <UiPanel
      title="Intraday flow"
      subtitle="Cumulative current-session contract volume across all returned expiries. Dashboard expiry timeframes do not scope this view."
      tone="data"
    >
      <template #actions>
        <div class="gex-row intraday-flow__actions">
          <UiBadge tone="data">{{ normalizedSymbol }}</UiBadge>
          <UiBadge v-if="tradeDate" tone="data">Trade date {{ tradeDate }}</UiBadge>
          <UiBadge :tone="sourceTone">{{ calculatedSourceLabel }}</UiBadge>
          <UiBadge v-if="refreshEligible === true" tone="warning">Refresh due</UiBadge>
          <UiBadge v-else-if="refreshEligible === false">Refresh {{ refreshReasonLabel }}</UiBadge>
          <UiHelpDialog id="intraday-flow-guide" title="How to read intraday flow">
            <p><strong>Volume</strong> is cumulative contract volume for the stored session, aggregated by strike across every returned expiry. It is not a day-over-day delta.</p>
            <p><strong>Volume PCR</strong> is put volume divided by call volume. Above 1 is put-led; below 1 is call-led. It describes activity mix, not trade direction.</p>
            <p><strong>Estimated premium</strong> is price × volume × 100, summed for calls and puts. The source selects VWAP, day close, last trade, then quote; a missing price contributes zero.</p>
            <p>Focus and grouping change only the plotted range. Every returned strike and exact value remains in the collapsed details.</p>
          </UiHelpDialog>
          <UiButton :disabled="refreshing || loading" @click="emit('refresh')">
            {{ refreshing ? 'Refreshing…' : 'Refresh' }}
          </UiButton>
        </div>
      </template>

      <UiStatus
        :state="statusState"
        :title="statusTitle"
        :message="statusMessage"
        :retry="Boolean(error) && !hasPayload"
        @retry="emit('retry')"
      />

      <template v-if="hasPayload && symbolAligned">
        <div class="intraday-flow__hero-metrics">
          <UiMetric
            label="Session volume"
            :value="compactUnsigned(totalVolume)"
            unit="contracts"
            context="Call plus put volume across all returned expiries"
            tone="data"
            prominence="primary"
            :title="exact(totalVolume)"
          />
          <UiMetric
            label="Volume put/call ratio"
            :value="ratioLabel(pcr)"
            unit="puts ÷ calls"
            :context="pcrContext"
            :tone="pcrTone"
            prominence="primary"
            :title="exact(pcr)"
          />
        </div>

        <div class="intraday-flow__secondary-metrics">
          <UiMetric
            label="Call volume"
            :value="compactUnsigned(callVolume)"
            unit="contracts"
            :context="callShare == null ? 'Share unavailable' : `${callShare.toFixed(1)}% of session volume`"
            tone="positive"
            :title="exact(callVolume)"
          />
          <UiMetric
            label="Put volume"
            :value="compactUnsigned(putVolume)"
            unit="contracts"
            :context="putShare == null ? 'Share unavailable' : `${putShare.toFixed(1)}% of session volume`"
            tone="negative"
            :title="exact(putVolume)"
          />
          <UiMetric
            label="Estimated premium notional"
            :value="compactUsd(premium)"
            context="Returned call-plus-put estimate"
            tone="data"
            :title="exact(premium)"
          />
        </div>

        <div v-if="callShare != null && putShare != null" class="intraday-flow__mix">
          <div class="intraday-flow__mix-heading">
            <span>Session volume mix</span>
            <span class="gex-number">{{ callShare.toFixed(1) }}% calls · {{ putShare.toFixed(1) }}% puts</span>
          </div>
          <div
            class="intraday-flow__mix-track"
            role="img"
            :aria-label="`Call volume ${exact(callVolume)} contracts; put volume ${exact(putVolume)} contracts`"
          >
            <i data-side="call" :style="{ width: `${callShare}%` }" />
            <i data-side="put" :style="{ width: `${putShare}%` }" />
          </div>
        </div>

        <IntradayFlowChart
          :rows="alignedRows"
          :symbol="normalizedSymbol"
          :trade-date="tradeDate || ''"
          :source-as-of="sourceAsOf || ''"
          @reading-inspected="emit('reading-inspected')"
        />

        <details
          class="intraday-flow__details"
          data-testid="intraday-flow-snapshot-details"
          @toggle="snapshotDetailsOpen = $event.currentTarget.open"
        >
          <summary>Snapshot and refresh details</summary>
          <div v-if="snapshotDetailsOpen" class="intraday-flow__details-body">
            <dl class="intraday-flow__definition-grid">
              <template v-for="row in metadataRows" :key="row.label">
                <dt>{{ row.label }}</dt>
                <dd class="gex-number">{{ row.timestamp ? timestampEt(row.value) : exact(row.value) }}</dd>
              </template>
            </dl>
            <h3>Exact returned totals</h3>
            <dl class="intraday-flow__definition-grid">
              <template v-for="row in exactTotals" :key="row.label">
                <dt>{{ row.label }}</dt>
                <dd class="gex-number">{{ exact(row.value) }}</dd>
              </template>
            </dl>
            <details class="intraday-flow__json">
              <summary>Complete returned summary and metadata</summary>
              <pre>{{ returnedMetadataJson }}</pre>
            </details>
          </div>
        </details>

        <details
          class="intraday-flow__details"
          data-testid="intraday-flow-strike-readings"
          @toggle="strikeDetailsOpen = $event.currentTarget.open"
        >
          <summary>All exact strike readings · {{ alignedRows.length }} readings</summary>
          <div v-if="strikeDetailsOpen" class="intraday-flow__details-body">
            <p class="gex-small gex-muted">The table keeps zero distinct from unavailable. Complete returned row objects remain available beneath it.</p>
            <UiDataTable
              caption="Exact intraday flow by strike"
              :rows="tableRows"
              :columns="tableColumns"
              row-key="row_key"
            />
            <details class="intraday-flow__json">
              <summary>Complete returned strike objects</summary>
              <pre>{{ returnedRowsJson }}</pre>
            </details>
          </div>
        </details>
      </template>
    </UiPanel>
  </section>
</template>

<style scoped>
.intraday-flow {
  min-width: 0;
}

.intraday-flow__actions {
  justify-content: flex-end;
}

.intraday-flow__hero-metrics,
.intraday-flow__secondary-metrics {
  display: grid;
  gap: 12px;
  margin-top: 14px;
}

.intraday-flow__hero-metrics {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.intraday-flow__secondary-metrics {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.intraday-flow__mix {
  margin-top: 14px;
  border: 1px solid var(--gex-border);
  border-radius: 10px;
  padding: 12px 14px;
  background: color-mix(in srgb, var(--gex-raised) 40%, transparent);
}

.intraday-flow__mix-heading {
  display: flex;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
  color: var(--gex-muted);
  font-size: 12px;
}

.intraday-flow__mix-track {
  display: flex;
  width: 100%;
  height: 8px;
  margin-top: 9px;
  overflow: hidden;
  border-radius: 999px;
  background: var(--gex-raised);
}

.intraday-flow__mix-track i {
  display: block;
  min-width: 0;
}

.intraday-flow__mix-track i[data-side="call"] {
  background: var(--gex-positive);
}

.intraday-flow__mix-track i[data-side="put"] {
  background: var(--gex-negative);
}

.intraday-flow__details {
  border-top: 1px solid var(--gex-border);
}

.intraday-flow__details > summary,
.intraday-flow__json > summary {
  color: var(--gex-text);
  font-weight: 600;
}

.intraday-flow__details-body {
  display: grid;
  gap: 14px;
  padding: 4px 0 10px;
}

.intraday-flow__definition-grid {
  display: grid;
  grid-template-columns: minmax(150px, .8fr) minmax(0, 1.2fr);
  gap: 0;
  margin: 0;
  border: 1px solid var(--gex-border);
  border-radius: 9px;
  overflow: hidden;
  font-size: 12px;
}

.intraday-flow__definition-grid dt,
.intraday-flow__definition-grid dd {
  margin: 0;
  padding: 8px 10px;
  border-bottom: 1px solid var(--gex-border);
}

.intraday-flow__definition-grid dt {
  color: var(--gex-muted);
  background: color-mix(in srgb, var(--gex-raised) 36%, transparent);
}

.intraday-flow__definition-grid dd {
  min-width: 0;
  overflow-wrap: anywhere;
  color: var(--gex-text);
}

.intraday-flow__definition-grid > :nth-last-child(-n+2) {
  border-bottom: 0;
}

.intraday-flow__json pre {
  max-height: 420px;
  margin: 4px 0 0;
  overflow: auto;
  border: 1px solid var(--gex-border);
  border-radius: 9px;
  padding: 12px;
  background: var(--gex-bg);
  color: var(--gex-muted);
  font: 11px/1.55 ui-monospace, SFMono-Regular, Consolas, monospace;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

@media (max-width: 780px) {
  .intraday-flow__secondary-metrics {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 600px) {
  .intraday-flow__hero-metrics,
  .intraday-flow__secondary-metrics,
  .intraday-flow__definition-grid {
    grid-template-columns: 1fr;
  }

  .intraday-flow__definition-grid dt,
  .intraday-flow__definition-grid dd {
    border-bottom: 0;
  }

  .intraday-flow__definition-grid dd {
    border-bottom: 1px solid var(--gex-border);
    padding-top: 0;
  }

  .intraday-flow__definition-grid > :last-child {
    border-bottom: 0;
  }

  .intraday-flow__actions,
  .intraday-flow__actions :deep(.gex-button) {
    width: 100%;
  }
}

@media (prefers-reduced-motion: reduce) {
  .intraday-flow * {
    scroll-behavior: auto !important;
  }
}
</style>
