<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import axios from 'axios'
import UiBadge from './UI/UiBadge.vue'
import UiDataTable from './UI/UiDataTable.vue'
import UiHelpDialog from './UI/UiHelpDialog.vue'
import UiMetric from './UI/UiMetric.vue'
import UiPanel from './UI/UiPanel.vue'
import UiSelect from './UI/UiSelect.vue'
import UiStatus from './UI/UiStatus.vue'
import { numeric } from './UI/numbers.js'

const props = defineProps({
  symbol: { type: String, default: 'SPY' },
  days: { type: Number, default: 3 },
  active: { type: Boolean, default: true },
})

const emit = defineEmits(['reading-inspected'])

const data = ref(null)
const error = ref('')
const loading = ref(false)
const pending = ref(false)
const selectedExpiry = ref('')

let activeLoad = null
let componentUnmounted = false
let retryTimer = null
let autoRetryCount = 0
const MAX_AUTO_RETRIES = 3

const entries = computed(() => Array.isArray(data.value?.entries) ? data.value.entries : [])
const headlinePin = computed(() => numeric(data.value?.headline_pin))
const selectedEntry = computed(() => entries.value.find(entry => entry?.exp_date === selectedExpiry.value) ?? null)
const selectedClusters = computed(() => Array.isArray(selectedEntry.value?.clusters) ? selectedEntry.value.clusters : [])
const selectedClusterRows = computed(() => selectedClusters.value.map((cluster, index) => ({
  ...cluster,
  cluster_key: `${selectedExpiry.value}-${cluster?.strike ?? 'missing'}-${index}`,
})))

function formatScore(value) {
  const score = numeric(value)
  return score == null ? null : score.toFixed(1)
}

const expiryOptions = computed(() => entries.value.map(entry => ({
  value: entry.exp_date,
  label: `${entry.exp_date} · score ${formatScore(entry.pin_score) ?? 'unavailable'}`,
})))

const expiryRows = computed(() => entries.value.map(entry => ({
  ...entry,
  cluster_count: Array.isArray(entry?.clusters) ? entry.clusters.length : 0,
})))
const expiryColumns = [
  { key: 'exp_date', label: 'Expiry', sortable: true },
  { key: 'pin_score', label: 'Pin score (0–100)', numeric: true, sortable: true, format: formatScore },
  { key: 'max_pain', label: 'Max pain', numeric: true, sortable: true },
  { key: 'cluster_count', label: 'Clusters', numeric: true, sortable: true },
  { key: 'source_chain_date', label: 'Source chain date', sortable: true },
]
const clusterColumns = [
  { key: 'strike', label: 'Strike', numeric: true, sortable: true },
  { key: 'density', label: 'Density', numeric: true, sortable: true },
  { key: 'distance', label: 'Distance', numeric: true, sortable: true },
  { key: 'score', label: 'Cluster score (0–100)', numeric: true, sortable: true, format: formatScore },
]
const allClusterColumns = [
  { key: 'exp_date', label: 'Expiry', sortable: true },
  ...clusterColumns,
]
const allClusterRows = computed(() => entries.value.flatMap(entry => {
  const clusters = Array.isArray(entry?.clusters) ? entry.clusters : []
  return clusters.map((cluster, index) => ({
    ...cluster,
    exp_date: entry.exp_date,
    cluster_key: `${entry.exp_date}-${cluster?.strike ?? 'missing'}-${index}`,
  }))
}))

function chooseExpiry() {
  if (entries.value.some(entry => entry?.exp_date === selectedExpiry.value)) return
  selectedExpiry.value = entries.value[0]?.exp_date ?? ''
}

function inspectExpiry(value) {
  const entry = entries.value.find(item => item?.exp_date === value)
  selectedExpiry.value = value
  if (!entry) return
  const clusters = Array.isArray(entry.clusters) ? entry.clusters : []
  const hasReading = numeric(entry.pin_score) != null
    || numeric(entry.max_pain) != null
    || clusters.some(cluster => ['strike', 'density', 'distance', 'score'].some(field => numeric(cluster?.[field]) != null))
  if (hasReading) emit('reading-inspected')
}

function formatError(value) {
  if (!value) return 'Expiry pressure is unavailable.'
  if (typeof value === 'string') return value
  return value?.message || value?.error || 'Expiry pressure is unavailable.'
}

function stopLoad() {
  if (retryTimer) {
    clearTimeout(retryTimer)
    retryTimer = null
  }
  activeLoad?.controller.abort()
  activeLoad = null
  loading.value = false
}

function isCurrentLoad(request) {
  return !componentUnmounted
    && activeLoad === request
    && props.symbol === request.symbol
    && Number(props.days) === request.days
    && props.active
    && !request.controller.signal.aborted
}

function isCurrentRetryScope(request) {
  return !componentUnmounted
    && props.active
    && props.symbol === request.symbol
    && Number(props.days) === request.days
}

function resetData() {
  pending.value = false
  data.value = null
  error.value = ''
  selectedExpiry.value = ''
  autoRetryCount = 0
}

async function load(options = {}) {
  if (componentUnmounted || !props.active) return
  const automatic = options?.automatic === true
  if (!automatic) autoRetryCount = 0
  stopLoad()
  const request = {
    symbol: props.symbol,
    days: Number(props.days),
    controller: new AbortController(),
  }
  activeLoad = request
  loading.value = true
  error.value = ''

  try {
    const { data: response, status } = await axios.get('/api/expiry-pressure', {
      params: { symbol: request.symbol, days: request.days },
      signal: request.controller.signal,
    })
    if (!isCurrentLoad(request)) return
    pending.value = status === 202
    data.value = response && typeof response === 'object' ? response : {}
    chooseExpiry()
    if (!data.value?.data_date && autoRetryCount < MAX_AUTO_RETRIES) {
      autoRetryCount += 1
      retryTimer = setTimeout(() => {
        if (!isCurrentRetryScope(request)) return
        retryTimer = null
        load({ automatic: true }).catch(() => {})
      }, 4000)
    }
  } catch (requestError) {
    if (isCurrentLoad(request)) {
      error.value = formatError(requestError?.response?.data || requestError?.message)
    }
  } finally {
    if (isCurrentLoad(request)) {
      loading.value = false
      if (pending.value && !retryTimer) { pending.value = false; error.value = 'Expiry pressure is still being prepared. Retry in a moment; other panels remain available.' }
      activeLoad = null
    }
  }
}

onMounted(() => {
  if (props.active) load()
})

watch(() => [props.symbol, props.days], () => {
  stopLoad()
  resetData()
  if (props.active) return load()
})

watch(() => props.active, (active) => {
  if (!active) {
    stopLoad()
    return
  }
  if (!data.value?.data_date) load()
})

onUnmounted(() => {
  componentUnmounted = true
  stopLoad()
})
</script>

<template>
  <UiPanel
    title="Expiry pressure"
    :subtitle="`Pin risk across the next ${days} trading days`"
    tone="warning"
    data-testid="expiry-pressure"
  >
    <template #actions>
      <div class="gex-row">
        <UiBadge v-if="data?.data_date" tone="data">Snapshot {{ data.data_date }}</UiBadge>
        <UiHelpDialog id="expiry-pressure-guide" title="How to read expiry pressure">
          <p><strong>Pin score:</strong> a 0–100 score combining open-interest density near spot and proximity. Higher scores indicate stronger pinning conditions. The score is not a probability.</p>
          <p><strong>Clusters:</strong> open-interest concentrations near spot that can behave like magnets into expiry. Density, distance, and the calculated cluster score remain available in the cluster detail table.</p>
          <p><strong>Max pain:</strong> the classical payoff-minimizing price. Treat it as a reference rather than a target.</p>
          <p>Scores of 70 or higher indicate stronger pin risk; 40–69 is mixed; below 40 is weaker and should be read with trend and volatility context.</p>
        </UiHelpDialog>
      </div>
    </template>

    <UiStatus
      v-if="(loading && !data?.data_date) || pending"
      :key="symbol"
      :state="pending ? 'preparing' : 'loading'"
      title="Loading expiry pressure"
      :message="`Reading ${symbol} across ${days} trading days.`"
    />
    <UiStatus
      v-else-if="error"
      state="error"
      title="Expiry pressure unavailable"
      :message="error"
      retry
      @retry="load"
    />
    <UiStatus v-else-if="!data?.data_date && !entries.length" state="sparse" title="No expiry pressure snapshot yet" message="Choose another symbol or retry when expiry-pressure readings are available." retry @retry="load" />
    <template v-else>
      <div class="gex-grid">
        <UiMetric
          label="Headline pin score"
          :value="formatScore(headlinePin)"
          unit="of 100"
          :tone="headlinePin == null ? 'warning' : headlinePin >= 70 ? 'warning' : 'data'"
          prominence="primary"
          context="0–100 score combining open-interest density and distance from spot; not a probability"
        />
        <UiMetric
          label="Expiry readings"
          :value="entries.length"
          unit="returned"
          :context="`${allClusterRows.length} total cluster readings`"
          tone="data"
        />
        <UiMetric
          label="Dataset scope"
          :value="`${days} trading days`"
          :context="data?.data_date ? `Starting from snapshot ${data.data_date}` : 'Snapshot unavailable'"
          tone="data"
        />
      </div>

      <div v-if="entries.length" class="gex-stack" style="margin-top: 20px">
        <UiSelect
          :model-value="selectedExpiry"
          label="Inspect expiry"
          :options="expiryOptions"
          data-testid="pressure-expiry-select"
          @update:model-value="inspectExpiry"
        />

        <section v-if="selectedEntry" class="gex-panel gex-metric" aria-label="Selected expiry pressure" aria-live="polite" data-testid="selected-pressure-detail">
          <div class="gex-row" style="justify-content: space-between">
            <div>
              <div class="gex-metric-label">Selected expiry</div>
              <div class="gex-metric-value gex-number" style="font-size: 20px">{{ selectedEntry.exp_date }}</div>
            </div>
            <UiBadge :tone="numeric(selectedEntry.pin_score) == null ? 'warning' : numeric(selectedEntry.pin_score) >= 70 ? 'warning' : 'data'">
              Score {{ formatScore(selectedEntry.pin_score) ?? 'unavailable' }} / 100
            </UiBadge>
          </div>
          <dl class="gex-grid gex-small">
            <div><dt class="gex-muted">Max pain</dt><dd class="gex-number">{{ selectedEntry.max_pain ?? 'Unavailable' }}</dd></div>
            <div><dt class="gex-muted">Source chain date</dt><dd class="gex-number">{{ selectedEntry.source_chain_date ?? 'Unavailable' }}</dd></div>
            <div><dt class="gex-muted">Cluster rows</dt><dd class="gex-number">{{ selectedClusters.length }} of {{ selectedClusters.length }} displayed</dd></div>
          </dl>
        </section>

        <UiDataTable
          caption="Selected expiry cluster readings"
          :rows="selectedClusterRows"
          :columns="clusterColumns"
          row-key="cluster_key"
          data-testid="selected-pressure-clusters"
        />

        <UiDataTable
          caption="All expiry pressure readings"
          :rows="expiryRows"
          :columns="expiryColumns"
          row-key="exp_date"
          data-testid="pressure-expiry-table"
        />

        <details>
          <summary>All {{ allClusterRows.length }} cluster readings across {{ entries.length }} expiries</summary>
          <UiDataTable
            caption="All expiry cluster readings"
            :rows="allClusterRows"
            :columns="allClusterColumns"
            row-key="cluster_key"
            data-testid="all-pressure-clusters"
          />
        </details>
      </div>
      <UiStatus
        v-else
        state="sparse"
        title="No expiry pressure readings"
        :message="data?.data_date ? `The ${data.data_date} snapshot returned no expiries for this window.` : 'No completed pressure snapshot is available.'"
        :retry="!data?.data_date"
        @retry="load"
      />

    </template>
  </UiPanel>
</template>
