<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import axios from 'axios'
import DexByExpiryDiverging from './DexByExpiryDiverging.vue'
import UiBadge from './UI/UiBadge.vue'
import UiDataTable from './UI/UiDataTable.vue'
import UiHelpDialog from './UI/UiHelpDialog.vue'
import UiMetric from './UI/UiMetric.vue'
import UiPanel from './UI/UiPanel.vue'
import UiStatus from './UI/UiStatus.vue'
import { compact, numeric } from './UI/numbers.js'

const props = defineProps({
  symbol: { type: String, default: 'SPY' },
  active: { type: Boolean, default: true },
})

const emit = defineEmits(['reading-inspected'])

// Keep the established public refs because existing request lifecycle tests and
// downstream debugging use them.
const dex = ref(null)
const byExpiry = ref([])
const dataDate = ref(null)
const strength = ref(null)
const gammaSign = ref(null)

const dexPayload = ref(null)
const gammaPayload = ref(null)
const calendarToday = ref(null)
const responseSymbol = ref(null)
const windowScope = ref(null)
const regimeSourceMeta = ref(null)
const selectedExpiry = ref('')
const loading = ref(false)
const pending = ref(false)
const error = ref('')

let retryTimer = null
let activeLoad = null
let componentUnmounted = false
let autoRetryCount = 0
const MAX_AUTO_RETRIES = 3

function percent(value, digits = 0) {
  const number = numeric(value)
  return number == null ? null : (number * 100).toFixed(digits)
}

function expiryState(expiry) {
  if (!calendarToday.value || !expiry) return 'Date relationship unavailable'
  if (expiry < calendarToday.value) return 'Expired'
  if (expiry === calendarToday.value) return 'Expires today'
  return 'Future expiry'
}

const dexDisplay = computed(() => numeric(dex.value) == null ? null : compact(dex.value))
const dexTone = computed(() => {
  const value = numeric(dex.value)
  if (value == null || value === 0) return 'data'
  return value > 0 ? 'positive' : 'negative'
})
const dealerDirection = computed(() => {
  const value = numeric(dex.value)
  if (value == null) return 'Direction unavailable'
  if (value === 0) return 'Flat net delta'
  return value > 0 ? 'Long net delta' : 'Short net delta'
})
const gammaLabel = computed(() => {
  const value = numeric(gammaSign.value)
  if (value == null) return 'Gamma unavailable'
  if (value === 0) return 'Neutral gamma'
  return value > 0 ? 'Positive gamma' : 'Negative gamma'
})
const gammaTone = computed(() => {
  const value = numeric(gammaSign.value)
  if (value == null || value === 0) return 'neutral'
  return value > 0 ? 'positive' : 'negative'
})

const regimeHint = computed(() => {
  const strengthValue = numeric(strength.value)
  const gammaValue = numeric(gammaSign.value)
  const dexValue = numeric(dex.value)
  if (strengthValue == null || gammaValue == null || dexValue == null) return 'A complete regime interpretation is unavailable.'
  if (gammaValue === 0 || dexValue === 0) return 'The reported regime is balanced; use the expiry distribution for context.'
  const strong = strengthValue >= 0.7
  if (gammaValue > 0) {
    return dexValue > 0
      ? (strong ? 'Pinning and mean-reversion bias.' : 'Mild pinning; breaks may need a catalyst.')
      : (strong ? 'Pinning, while short delta can chase declines.' : 'Mixed pinning; confirm with price action.')
  }
  return dexValue > 0
    ? (strong ? 'Extension risk on advances; fades can be unstable.' : 'Choppy conditions with expansion risk.')
    : (strong ? 'Trend and extension risk; fading moves can be hazardous.' : 'Mixed book; other signals may dominate.')
})

const selectedRow = computed(() => byExpiry.value.find(row => row?.exp_date === selectedExpiry.value) ?? null)
const availableCount = computed(() => byExpiry.value.filter(row => numeric(row?.dex_total) != null).length)
const tableRows = computed(() => byExpiry.value.map((row, index) => ({
  ...row,
  row_number: index + 1,
  expiry_state: expiryState(row?.exp_date),
})))
const tableColumns = [
  { key: 'exp_date', label: 'Expiry', sortable: true },
  { key: 'expiry_state', label: 'Status', sortable: true },
  { key: 'dex_total', label: 'DEX', numeric: true, sortable: true, format: compact },
  { key: 'source_chain_date', label: 'As of', sortable: true },
]

function ranked(direction) {
  return [...byExpiry.value]
    .filter(row => {
      const value = numeric(row?.dex_total)
      return value != null && (direction > 0 ? value > 0 : value < 0)
    })
    .sort((a, b) => direction > 0
      ? numeric(b.dex_total) - numeric(a.dex_total)
      : Math.abs(numeric(b.dex_total)) - Math.abs(numeric(a.dex_total)))
    .slice(0, 3)
}

const topPositive = computed(() => ranked(1))
const topNegative = computed(() => ranked(-1))

function needsRegimeFallback() {
  return strength.value == null || gammaSign.value == null
}

function applyRegimeFallback(payload) {
  if (!payload || typeof payload !== 'object') return
  if (payload.data_date != null && payload.data_date !== dataDate.value) return
  if (strength.value == null && payload.regime_strength != null) strength.value = payload.regime_strength
  if (gammaSign.value == null && payload.gamma_sign != null) gammaSign.value = payload.gamma_sign
}

function chooseExpiry() {
  if (byExpiry.value.some(row => row?.exp_date === selectedExpiry.value)) return
  const next = byExpiry.value.find(row => calendarToday.value && row?.exp_date >= calendarToday.value)
    ?? byExpiry.value[0]
  selectedExpiry.value = next?.exp_date ?? ''
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
    && props.active
    && !request.controller.signal.aborted
}

function resetData() {
  pending.value = false
  dex.value = null
  byExpiry.value = []
  dataDate.value = null
  strength.value = null
  gammaSign.value = null
  dexPayload.value = null
  gammaPayload.value = null
  calendarToday.value = null
  responseSymbol.value = null
  windowScope.value = null
  regimeSourceMeta.value = null
  selectedExpiry.value = ''
  error.value = ''
  autoRetryCount = 0
}

async function load(options = {}) {
  if (componentUnmounted || !props.active) return
  const automatic = options?.automatic === true
  if (!automatic) autoRetryCount = 0
  stopLoad()
  const request = { symbol: props.symbol, controller: new AbortController() }
  activeLoad = request
  const { symbol, controller } = request
  loading.value = true
  error.value = ''

  try {
    const { data, status } = await axios.get('/api/dex', { params: { symbol }, signal: controller.signal })
    if (!isCurrentLoad(request)) return

    pending.value = status === 202
    dexPayload.value = data && typeof data === 'object' ? data : {}
    responseSymbol.value = data?.symbol ?? symbol
    dex.value = data?.total ?? null
    byExpiry.value = Array.isArray(data?.by_expiry) ? data.by_expiry : []
    dataDate.value = data?.data_date ?? null
    calendarToday.value = data?.today ?? null
    windowScope.value = data?.window ?? null
    regimeSourceMeta.value = data?.regime_source_meta ?? null
    strength.value = data?.regime_strength ?? null
    gammaSign.value = data?.gamma_sign ?? null
    chooseExpiry()

    // DEX is authoritative for this snapshot. The fixed 14d GEX dataset may
    // fill a missing regime field only when its source date is compatible.
    if (!pending.value && needsRegimeFallback() && gammaPayload.value) {
      applyRegimeFallback(gammaPayload.value)
    } else if (!pending.value && needsRegimeFallback()) {
      try {
        const gammaResponse = await axios.get('/api/gex-levels', {
          params: { symbol, timeframe: '14d' },
          signal: controller.signal,
        })
        if (!isCurrentLoad(request)) return
        gammaPayload.value = gammaResponse?.data ?? null
        applyRegimeFallback(gammaPayload.value)
      } catch {
        // DEX carries the same optional regime fields, so a failed context read
        // does not erase data already returned by the positioning endpoint.
      }
    }

    if (isCurrentLoad(request) && !dataDate.value && !retryTimer && autoRetryCount < MAX_AUTO_RETRIES) {
      autoRetryCount += 1
      retryTimer = setTimeout(() => {
        if (!isCurrentLoad(request)) return
        retryTimer = null
        load({ automatic: true }).catch(() => {})
      }, 4000)
    }
  } catch (requestError) {
    if (isCurrentLoad(request)) {
      error.value = requestError?.response?.data?.error
        || requestError?.response?.data?.message
        || requestError?.message
        || 'Dealer positioning is unavailable.'
    }
  } finally {
    if (isCurrentLoad(request)) {
      loading.value = false
      if (pending.value && !retryTimer) { pending.value = false; error.value = 'Dealer positioning is still being prepared. Retry in a moment; other panels remain available.' }
    }
  }
}

onMounted(() => {
  if (props.active) load()
})

watch(() => props.symbol, () => {
  stopLoad()
  resetData()
  if (props.active) return load()
})

watch(() => props.active, (active) => {
  if (!active) {
    stopLoad()
    return
  }
  if (!dataDate.value) load()
})

onUnmounted(() => {
  componentUnmounted = true
  stopLoad()
})
</script>

<template>
  <UiPanel
    title="Dealer positioning"
    subtitle="Net dealer delta by expiry with fixed 2W gamma context"
    tone="data"
    data-testid="dealer-positioning"
  >
    <template #actions>
      <div class="gex-row">
        <UiBadge v-if="dataDate" tone="data">Data as of {{ dataDate }}</UiBadge>
        <UiBadge tone="neutral">Gamma scope 2W</UiBadge>
        <UiHelpDialog
          id="dealer-positioning-guide"
          title="How to read dealer positioning"
          trigger-label="Reading guide"
        >
          <div class="gex-stack">
            <p><strong>DEX</strong> is approximately Σ(Delta × open interest × 100) across the chain. Positive net delta commonly supports selling advances and buying declines; negative net delta can require dealers to chase moves.</p>
            <p><strong>Gamma sign</strong> describes whether hedging flows tend to dampen or amplify price moves. Regime strength is the coherence ratio |Σ GammaNotional| / Σ|GammaNotional|, not a probability.</p>
            <p>The gamma label uses the fixed 14 calendar-day GEX request. Changing the dashboard expiry timeframe does not filter this panel.</p>
            <dl class="gex-grid gex-small">
              <div><dt class="gex-muted">Response symbol</dt><dd>{{ responseSymbol ?? symbol }}</dd></div>
              <div><dt class="gex-muted">DEX data date</dt><dd>{{ dataDate ?? 'Unavailable' }}</dd></div>
              <div><dt class="gex-muted">Calendar today</dt><dd>{{ calendarToday ?? 'Unavailable' }}</dd></div>
              <div><dt class="gex-muted">API window</dt><dd>{{ windowScope?.start ?? 'Unavailable' }} to {{ windowScope?.end ?? 'Unavailable' }}</dd></div>
            </dl>
          </div>
        </UiHelpDialog>
      </div>
    </template>

    <UiStatus
      v-if="(loading && !dataDate) || pending"
      :key="symbol"
      :state="pending ? 'preparing' : 'loading'"
      title="Loading dealer positioning"
      :message="`Reading ${symbol} DEX and fixed 2W gamma context.`"
    />
    <UiStatus
      v-else-if="error"
      state="error"
      title="Dealer positioning unavailable"
      :message="error"
      retry
      @retry="load"
    />
    <UiStatus v-else-if="!dataDate && !byExpiry.length" state="sparse" title="Positioning data is not available yet" message="Choose another symbol or retry when the dealer-positioning data set is available." retry @retry="load" />
    <template v-else>
      <div class="gex-grid">
        <UiMetric
          prominence="primary"
          label="Net dealer delta"
          :value="dexDisplay"
          unit="share equivalents"
          :tone="dexTone"
          :context="dealerDirection"
        />
        <UiMetric
          prominence="primary"
          label="Gamma regime"
          :value="gammaLabel"
          :tone="gammaTone"
          context="Fixed 14 calendar-day GEX context"
        />
        <UiMetric
          label="Regime strength"
          :value="percent(strength, 0)"
          unit="% coherence"
          tone="data"
          :context="regimeHint"
        />
      </div>

      <div v-if="byExpiry.length" class="gex-stack" style="margin-top: 20px">
        <DexByExpiryDiverging
          v-model="selectedExpiry"
          :items="byExpiry"
          :today="calendarToday || ''"
          @reading-inspected="emit('reading-inspected')"
        />

        <section v-if="selectedRow" class="gex-panel gex-metric" aria-label="Selected expiry detail" aria-live="polite" data-testid="selected-dex-detail">
          <div class="gex-row" style="justify-content: space-between">
            <div>
              <div class="gex-metric-label">Selected expiry</div>
              <div class="gex-metric-value gex-number" style="font-size: 20px">{{ selectedRow.exp_date }}</div>
            </div>
            <UiBadge :tone="numeric(selectedRow.dex_total) == null ? 'warning' : numeric(selectedRow.dex_total) < 0 ? 'negative' : numeric(selectedRow.dex_total) > 0 ? 'positive' : 'neutral'">
              {{ expiryState(selectedRow.exp_date) }}
            </UiBadge>
          </div>
          <dl class="gex-grid gex-small">
            <div><dt class="gex-muted">DEX</dt><dd class="gex-number">{{ compact(selectedRow.dex_total) }}</dd></div>
            <div><dt class="gex-muted">As of</dt><dd class="gex-number">{{ selectedRow.source_chain_date ?? 'Unavailable' }}</dd></div>
            <div><dt class="gex-muted">Displayed rows</dt><dd class="gex-number">{{ availableCount }} available of {{ byExpiry.length }}</dd></div>
          </dl>
        </section>

        <div class="gex-grid" aria-label="DEX rankings">
          <section class="gex-panel gex-metric">
            <h3>Top positive DEX</h3>
            <ol v-if="topPositive.length" class="gex-small gex-number">
              <li v-for="row in topPositive" :key="`positive-${row.exp_date}`">{{ row.exp_date }} · {{ compact(row.dex_total) }}</li>
            </ol>
            <p v-else class="gex-small gex-muted">No positive readings.</p>
          </section>
          <section class="gex-panel gex-metric">
            <h3>Top negative DEX</h3>
            <ol v-if="topNegative.length" class="gex-small gex-number">
              <li v-for="row in topNegative" :key="`negative-${row.exp_date}`">{{ row.exp_date }} · {{ compact(row.dex_total) }}</li>
            </ol>
            <p v-else class="gex-small gex-muted">No negative readings.</p>
          </section>
        </div>

        <details data-testid="dex-expiry-disclosure">
          <summary>All {{ tableRows.length }} DEX readings</summary>
          <UiDataTable
            caption="DEX by expiry"
            :rows="tableRows"
            :columns="tableColumns"
            row-key="exp_date"
            data-testid="dex-expiry-table"
          />
        </details>
      </div>
      <UiStatus
        v-else
        state="sparse"
        title="No expiry DEX readings"
        :message="dataDate ? `The ${dataDate} data set returned no expiry rows.` : 'No completed DEX data set is available.'"
      />
    </template>
  </UiPanel>
</template>
