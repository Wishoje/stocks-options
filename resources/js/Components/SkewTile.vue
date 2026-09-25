<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import axios from 'axios'
import UiBadge from './UI/UiBadge.vue'
import UiDataTable from './UI/UiDataTable.vue'
import UiHelpDialog from './UI/UiHelpDialog.vue'
import UiHistory from './UI/UiHistory.vue'
import UiMetric from './UI/UiMetric.vue'
import UiPanel from './UI/UiPanel.vue'
import UiStatus from './UI/UiStatus.vue'
import UiTabs from './UI/UiTabs.vue'
import { numeric } from './UI/numbers.js'

const props = defineProps({
  symbol: { type: String, default: 'SPY' },
  active: { type: Boolean, default: true },
})

const emit = defineEmits(['reading-inspected'])

const buckets = [
  { value: '0d', key: '0d', label: '0DTE', days: 0 },
  { value: '1w', key: '1w', label: '1W', days: 7 },
  { value: '1m', key: '1m', label: '1M', days: 21 },
]

// Keep the established public state names for compatibility with existing
// diagnostics and future tests.
const tab = ref('1w')
const row = ref(null)
const err = ref('')
const loading = ref(false)
const skewSeries = ref([])

const summaryPayload = ref(null)
const historyPayload = ref([])
const summaryError = ref('')
const historyError = ref('')

let activeLoad = null
let componentUnmounted = false

function bucket(key = tab.value) {
  return buckets.find(item => item.key === key) || buckets[1]
}

function percent(value, digits = 1) {
  const number = numeric(value)
  return number == null ? null : (number * 100).toFixed(digits)
}

function percentagePoints(value, digits = 1) {
  return percent(value, digits)
}

function fixed(value, digits = 3) {
  const number = numeric(value)
  return number == null ? null : number.toFixed(digits)
}

function fieldLabel(key) {
  return String(key)
    .replaceAll('_', ' ')
    .replace(/\b\w/g, letter => letter.toUpperCase())
}

function rawDisplay(value) {
  if (value == null || value === '') return null
  if (typeof value === 'object') {
    try { return JSON.stringify(value) } catch { return String(value) }
  }
  return String(value)
}

function errorMessage(reason, fallback) {
  return reason?.response?.data?.message
    || reason?.response?.data?.error
    || (typeof reason?.response?.data === 'string' ? reason.response.data : '')
    || reason?.message
    || fallback
}

const activeBucket = computed(() => bucket())
const scopeKey = computed(() => `${props.symbol}:${tab.value}`)
const scopeLabel = computed(() => `${activeBucket.value.label} target · nearest expiry by calendar days for each observation date`)
const dailySkewChangeDisplay = computed(() => {
  const change = numeric(row.value?.skew_pc_dod)
  if (change == null) return null
  const direction = change > 0 ? '↑' : change < 0 ? '↓' : '→'
  return `${direction} ${percentagePoints(change)}`
})

const historyChartRows = computed(() => historyPayload.value.map((source, index) => {
  const value = numeric(source?.skew_pc)
  return {
    ...source,
    date: source?.data_date ?? `Reading ${index + 1}`,
    value: value == null ? null : value * 100,
  }
}))

const historyFieldOrder = [
  'data_date',
  'exp_date',
  'dte',
  'iv_put_25d',
  'iv_call_25d',
  'skew_pc',
  'curvature',
  'source_chain_date',
]
const historyNumericFields = new Set(['dte', 'iv_put_25d', 'iv_call_25d', 'skew_pc', 'curvature'])
const historyColumns = computed(() => {
  const keys = new Set(historyPayload.value.flatMap(item => item && typeof item === 'object' ? Object.keys(item) : []))
  const ordered = [
    ...historyFieldOrder.filter(key => keys.has(key)),
    ...[...keys].filter(key => !historyFieldOrder.includes(key)),
  ]
  return ordered.map(key => ({
    key,
    label: fieldLabel(key),
    numeric: historyNumericFields.has(key),
    sortable: true,
    format: rawDisplay,
  }))
})

const summaryDte = computed(() => {
  if (row.value?.dte != null && numeric(row.value.dte) != null) return numeric(row.value.dte)
  const start = Date.parse(`${row.value?.data_date ?? ''}T12:00:00Z`)
  const end = Date.parse(`${row.value?.exp ?? ''}T12:00:00Z`)
  return Number.isFinite(start) && Number.isFinite(end) ? Math.round((end - start) / 86_400_000) : null
})

const hasQuoteDiagnostics = computed(() => numeric(row.value?.k_span) != null || numeric(row.value?.n_points) != null)
const qualityText = computed(() => {
  if (!hasQuoteDiagnostics.value) return null
  const span = numeric(row.value?.k_span)
  const points = numeric(row.value?.n_points)
  const enoughPoints = points == null || points >= 20
  const enoughSpan = span == null || span >= 0.05
  if (enoughPoints && enoughSpan) return 'OK'
  if (!enoughPoints && enoughSpan) return `Thin (${points} points)`
  if (enoughPoints && !enoughSpan) return 'Narrow span'
  return 'Thin and narrow'
})

const qualityTone = computed(() => qualityText.value === 'OK' ? 'positive' : 'warning')

const regime = computed(() => {
  const skew = numeric(row.value?.skew_pc)
  const curvature = numeric(row.value?.curvature)
  if (skew == null) return { label: 'Regime unavailable', tip: 'The selected bucket has no skew reading.' }
  const points = skew * 100
  if (points >= 5) return { label: 'Steep put skew', tip: 'Puts carry more implied volatility; calls are relatively cheap.' }
  if (points >= 2) return { label: 'Put skew', tip: 'Put debits may be relatively expensive; compare structures carefully.' }
  if (points <= -1) return { label: 'Call skew tilt', tip: 'Calls carry more implied volatility than comparable puts.' }
  if (Math.abs(points) < 1) {
    if (curvature != null && curvature > 0.02) return { label: 'Convex smile', tip: 'Wings are relatively expensive.' }
    if (curvature != null && curvature < -0.02) return { label: 'Concave smirk', tip: 'Wings are relatively inexpensive.' }
  }
  return { label: 'Near flat', tip: 'Put and call 25-delta volatility are comparatively balanced.' }
})

const advice = computed(() => {
  const skew = numeric(row.value?.skew_pc)
  if (skew == null) return 'Wait for a complete reading before comparing option structures.'
  if (skew > 0.05) return 'Steep put skew: put debits and diagonals pay elevated downside volatility; tight call credits collect less premium because calls are relatively cheap.'
  if (skew > 0.02) return 'Moderate put skew: compare put debits with balanced calendars and defined-risk alternatives.'
  if (skew < -0.01) return 'Call skew tilt: call debits may be more expensive than usual; compare with spreads.'
  return 'Near-flat skew: select the structure from the trade thesis and curvature rather than a strong wing imbalance.'
})

function stopLoad() {
  activeLoad?.controller.abort()
  activeLoad = null
  loading.value = false
}

function isCurrentLoad(request) {
  return !componentUnmounted
    && activeLoad === request
    && props.symbol === request.symbol
    && tab.value === request.bucketKey
    && props.active
    && !request.controller.signal.aborted
}

function clearScope() {
  row.value = null
  skewSeries.value = []
  summaryPayload.value = null
  historyPayload.value = []
  summaryError.value = ''
  historyError.value = ''
  err.value = ''
}

async function fetchSkew() {
  if (componentUnmounted || !props.active) return
  stopLoad()
  clearScope()

  const selectedBucket = bucket()
  const request = {
    symbol: props.symbol,
    bucketKey: selectedBucket.key,
    days: selectedBucket.days,
    controller: new AbortController(),
  }
  activeLoad = request
  loading.value = true

  const summaryRequest = axios.get('/api/iv/skew/by-bucket', {
    params: { symbol: request.symbol, days: request.days },
    signal: request.controller.signal,
  })
  const historyRequest = axios.get('/api/iv/skew/history/bucket', {
    params: { symbol: request.symbol, days: request.days, limit: 30 },
    signal: request.controller.signal,
  })

  const [summaryResult, historyResult] = await Promise.allSettled([summaryRequest, historyRequest])
  if (!isCurrentLoad(request)) return

  if (summaryResult.status === 'fulfilled') {
    const source = summaryResult.value?.data
    summaryPayload.value = source && typeof source === 'object' && !Array.isArray(source) ? source : {}
    row.value = source && (source.exp_date || source.exp)
      ? { ...source, exp: source.exp_date ?? source.exp }
      : null
  } else {
    summaryError.value = errorMessage(summaryResult.reason, 'Skew summary request failed.')
  }

  if (historyResult.status === 'fulfilled') {
    const source = historyResult.value?.data
    historyPayload.value = Array.isArray(source) ? source : []
    skewSeries.value = historyPayload.value
  } else {
    historyError.value = errorMessage(historyResult.reason, 'Skew history request failed.')
  }

  if (summaryError.value && historyError.value) {
    err.value = `${summaryError.value} ${historyError.value}`
  } else if (summaryError.value || historyError.value) {
    err.value = summaryError.value || historyError.value
  } else if (!row.value && historyPayload.value.length === 0) {
    err.value = 'No skew data is available for the selected bucket.'
  }

  loading.value = false
  activeLoad = null
}

// Kept as a named action for compatibility; a history refresh belongs to the
// same captured scope as its summary and therefore reloads both atomically.
async function loadSkewHistory() {
  return fetchSkew()
}

function selectBucket(key) {
  if (!buckets.some(item => item.key === key) || key === tab.value) return
  tab.value = key
  fetchSkew()
}

onMounted(() => {
  if (props.active) fetchSkew()
})

watch(() => props.symbol, () => {
  stopLoad()
  clearScope()
  if (props.active) fetchSkew()
})

watch(() => props.active, (active) => {
  if (!active) {
    stopLoad()
    return
  }
  if (!row.value && historyPayload.value.length === 0) fetchSkew()
})

onUnmounted(() => {
  componentUnmounted = true
  stopLoad()
})
</script>

<template>
  <UiPanel
    title="Put-versus-call pricing"
    subtitle="25-delta implied-volatility skew and curvature"
    tone="brand"
    data-testid="skew-positioning"
  >
    <template #actions>
      <div class="gex-row">
        <UiTabs
          id="positioning-skew"
          label="Skew expiry bucket"
          :model-value="tab"
          :items="buckets"
          @update:model-value="selectBucket"
        />
        <UiHelpDialog
          id="skew-reading-guide"
          title="How to read skew and curvature"
          trigger-label="Reading guide"
        >
          <div class="gex-stack">
            <p><strong>Skew:</strong> put IV minus call IV at 25 delta, shown in percentage points. About −1 to +1 pp is near flat, +2 to +5 pp is moderate put skew, +5 pp or more is steep put skew, and −1 pp or less is a call-skew tilt.</p>
            <p><strong>Curvature:</strong> the quadratic term of implied volatility versus log-moneyness near at-the-money. Positive curvature indicates a stronger smile and relatively expensive wings; negative curvature indicates relatively inexpensive wings.</p>
            <p><strong>Quality:</strong> the badge appears only when quote-fit diagnostics are supplied. At least 20 usable quotes and a k span of at least 0.05 are treated as adequate.</p>
            <p>The server chooses the expiry nearest to the target calendar days for each observation date and prefers a non-past expiry on ties. DTE can therefore vary across history rows.</p>
          </div>
        </UiHelpDialog>
      </div>
    </template>

    <div
      id="positioning-skew-panel"
      role="tabpanel"
      :aria-labelledby="`positioning-skew-${tab}`"
      tabindex="0"
    >
      <UiStatus
        v-if="loading"
        state="loading"
        title="Loading skew scope"
        :message="`${symbol} · ${scopeLabel}. Summary and history update together.`"
      />
    <UiStatus v-else-if="!row && !historyPayload.length" :state="summaryError && historyError ? 'error' : 'sparse'" title="Skew data is not available yet" :message="err || 'Try another expiry bucket or retry when the data set is ready.'" retry @retry="fetchSkew" />
    <template v-else>
    <UiStatus
      v-if="err"
      :state="summaryError && historyError ? 'error' : 'sparse'"
      :title="summaryError && historyError ? 'Skew unavailable' : 'Some skew readings are not available yet'"
      :message="err"
      retry
      @retry="fetchSkew"
    />

    <div class="gex-row" style="margin: 16px 0">
      <UiBadge tone="data">{{ activeBucket.label }} target</UiBadge>
      <UiBadge v-if="row?.exp" tone="neutral">Expiry {{ row.exp }}</UiBadge>
      <UiBadge v-if="summaryDte != null" tone="neutral">{{ summaryDte }} calendar DTE at the data date</UiBadge>
      <UiBadge v-if="row?.data_date" tone="data">Data as of {{ row.data_date }}</UiBadge>
      <UiBadge v-if="hasQuoteDiagnostics" :tone="qualityTone">Quality {{ qualityText }}</UiBadge>
      <UiBadge tone="neutral">{{ historyPayload.length }} daily readings</UiBadge>
    </div>

    <div class="gex-grid">
      <UiMetric
        label="Put IV (25Δ)"
        :value="percent(row?.iv_put_25d)"
        unit="%"
        tone="data"
        context="25-delta put implied volatility"
      />
      <UiMetric
        label="Call IV (25Δ)"
        :value="percent(row?.iv_call_25d)"
        unit="%"
        tone="data"
        context="25-delta call implied volatility"
      />
      <UiMetric
        prominence="primary"
        label="Skew"
        :value="percentagePoints(row?.skew_pc)"
        unit="pp"
        :tone="numeric(row?.skew_pc) == null || numeric(row?.skew_pc) === 0 ? 'neutral' : numeric(row?.skew_pc) > 0 ? 'positive' : 'negative'"
        :context="regime.label"
      />
      <UiMetric
        prominence="primary"
        label="Daily skew change"
        :value="dailySkewChangeDisplay"
        unit="pp"
        :tone="numeric(row?.skew_pc_dod) == null || numeric(row?.skew_pc_dod) === 0 ? 'neutral' : numeric(row?.skew_pc_dod) > 0 ? 'positive' : 'negative'"
        context="Compared with the prior available reading for this expiry"
      />
    </div>

    <div v-if="numeric(row?.curvature) != null || numeric(row?.curvature_dod) != null" class="gex-grid gex-secondary-metrics">
      <UiMetric
        v-if="numeric(row?.curvature) != null"
        label="Curvature"
        :value="fixed(row?.curvature)"
        tone="data"
        context="Quadratic coefficient of IV versus log-moneyness"
      />
      <UiMetric
        v-if="numeric(row?.curvature_dod) != null"
        label="Daily curvature change"
        :value="fixed(row?.curvature_dod)"
        tone="data"
        context="Compared with the prior available reading for this expiry"
      />
    </div>
    <p v-else class="gex-inline-status" role="status">Curvature is not available for this expiry. The IV and skew readings above remain available.</p>

    <p class="gex-small" style="margin-top: 14px"><strong>{{ regime.label }}.</strong> {{ regime.tip }} {{ advice }}</p>

    <div class="gex-stack" style="margin-top: 22px">
      <UiHistory
        title="Skew history"
        :items="historyChartRows"
        unit="pp"
        :scope="scopeLabel"
        :scope-key="scopeKey"
        @reading-inspected="emit('reading-inspected')"
        explanation="Skew in percentage points equals (25-delta put IV − 25-delta call IV) × 100. Positive values mean puts carry more implied volatility; negative values mean calls carry more. Each history row uses the expiry nearest to the selected calendar-day target on that observation date."
      >
        <template #calculation>
          <dl class="gex-stack gex-small" style="margin-top: 12px">
            <div><dt class="gex-muted">Selected target</dt><dd>{{ activeBucket.days }} calendar days</dd></div>
            <div><dt class="gex-muted">Selected expiry</dt><dd>{{ row?.exp ?? 'Unavailable' }}</dd></div>
            <div><dt class="gex-muted">As of</dt><dd>{{ row?.data_date ?? 'Unavailable' }}</dd></div>
            <div v-if="hasQuoteDiagnostics"><dt class="gex-muted">Quality result</dt><dd>{{ qualityText }}</dd></div>
            <div><dt class="gex-muted">History readings</dt><dd>{{ historyChartRows.length }} daily readings</dd></div>
          </dl>
        </template>
      </UiHistory>

      <details aria-label="Complete skew history readings" data-testid="skew-history-complete">
        <summary>Complete skew history · {{ historyPayload.length }} readings</summary>
        <p class="gex-small gex-muted">Explore the daily readings for your selected timeframe.</p>
        <UiDataTable
          caption="Skew history fields"
          :rows="historyPayload"
          :columns="historyColumns"
          row-key="data_date"
        />
      </details>
      </div>
    </template>
    </div>
  </UiPanel>
</template>
