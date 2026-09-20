<script setup>
import { computed } from 'vue'
import UiBadge from './UI/UiBadge.vue'
import UiHelpDialog from './UI/UiHelpDialog.vue'
import UiMetric from './UI/UiMetric.vue'
import UiPanel from './UI/UiPanel.vue'
import { compact, numeric } from './UI/numbers.js'

const props = defineProps({
  levels: { type: Object, default: null },
  scopeLabel: { type: String, default: '' },
})

const numberFor = key => numeric(props.levels?.[key])
const callOi = computed(() => numberFor('call_open_interest_total'))
const putOi = computed(() => numberFor('put_open_interest_total'))
const callVolume = computed(() => numberFor('call_volume_total'))
const putVolume = computed(() => numberFor('put_volume_total'))

function completeTotal(left, right) {
  return left == null || right == null ? null : left + right
}

const totalOi = computed(() => completeTotal(callOi.value, putOi.value))
const totalVolume = computed(() => completeTotal(callVolume.value, putVolume.value))
const comparisonContext = computed(() => {
  if (!props.levels?.date_prev) return 'Prior source snapshot unavailable'
  const gap = numeric(props.levels?.date_prev_gap_trading_days)
  const age = gap == null
    ? ''
    : ` (${gap} trading session${gap === 1 ? '' : 's'} back)`
  const fallback = props.levels?.date_prev_is_stale
    ? ' The prior-session snapshot was incomplete, so this is the nearest usable comparison.'
    : ''
  return `Compared with ${props.levels.date_prev}${age}.${fallback}`
})

function compactMagnitude(value) {
  return numeric(value) == null ? null : compact(value).replace(/^\+/, '')
}

function signedCompact(value) {
  return numeric(value) == null ? null : compact(value)
}

function percent(value) {
  const number = numeric(value)
  return number == null ? null : number.toFixed(1)
}

function decimal(value) {
  const number = numeric(value)
  return number == null ? null : number.toFixed(2)
}

function level(value) {
  const number = numeric(value)
  return number == null
    ? null
    : number.toLocaleString('en-US', { maximumFractionDigits: 2 })
}

function signedTone(value) {
  const number = numeric(value)
  if (number == null || number === 0) return 'neutral'
  return number > 0 ? 'positive' : 'negative'
}
</script>

<template>
  <UiPanel
    title="Options snapshot"
    subtitle="The main positioning and participation readings for the selected expiry scope"
    tone="data"
    data-testid="overview-metrics"
  >
    <template #actions>
      <div class="gex-row">
        <UiBadge v-if="levels?.data_date" tone="data">Snapshot {{ levels.data_date }}</UiBadge>
        <UiBadge v-if="scopeLabel" tone="neutral">{{ scopeLabel }} scope</UiBadge>
        <UiBadge v-if="levels?.date_prev" tone="neutral">
          Prior {{ levels.date_prev }}
          <template v-if="levels?.date_prev_gap_trading_days != null">
            · {{ levels.date_prev_gap_trading_days }} session<span v-if="Number(levels.date_prev_gap_trading_days) !== 1">s</span> back
          </template>
        </UiBadge>
        <UiBadge v-if="levels?.date_prev_is_stale" tone="warning">Fallback comparison</UiBadge>
        <UiHelpDialog
          id="overview-metrics-guide"
          title="How to read the options snapshot"
          trigger-label="Reading guide"
        >
          <div class="gex-stack">
            <p><strong>HVL</strong> is the first strike where net GEX crosses from negative to non-negative in the selected strike set. Treat it as context around a hedging transition, not a price target.</p>
            <p><strong>Volume PCR</strong> is put volume divided by call volume. A value above 1 means more put volume; below 1 means more call volume.</p>
            <p><strong>Open interest</strong> describes outstanding contracts. <strong>Volume</strong> describes contracts traded in the snapshot. Their changes compare with the prior source date shown above.</p>
            <p>Call and put shares always refer to the selected dashboard symbol and expiry scope. Missing source values remain unavailable instead of being converted to zero.</p>
          </div>
        </UiHelpDialog>
      </div>
    </template>

    <div class="gex-grid gex-overview-primary-metrics">
      <UiMetric
        prominence="primary"
        label="HVL"
        :value="level(levels?.hvl)"
        tone="data"
        context="Net GEX transition strike"
      />
      <UiMetric
        prominence="primary"
        label="Volume put/call ratio"
        :value="decimal(levels?.pcr_volume)"
        tone="data"
        context="Put volume divided by call volume"
      />
      <UiMetric
        label="Total open interest"
        :value="compactMagnitude(totalOi)"
        unit="contracts"
        context="Call and put open interest"
      />
      <UiMetric
        label="Total volume"
        :value="compactMagnitude(totalVolume)"
        unit="contracts"
        context="Call and put volume"
      />
    </div>

    <div class="gex-grid gex-secondary-metrics gex-overview-secondary-metrics">
      <UiMetric
        label="Call OI share"
        :value="percent(levels?.call_interest_percentage)"
        unit="%"
        tone="positive"
        :context="compactMagnitude(callOi) ? `${compactMagnitude(callOi)} call contracts` : 'Call open interest unavailable'"
      />
      <UiMetric
        label="Put OI share"
        :value="percent(levels?.put_interest_percentage)"
        unit="%"
        tone="negative"
        :context="compactMagnitude(putOi) ? `${compactMagnitude(putOi)} put contracts` : 'Put open interest unavailable'"
      />
      <UiMetric
        label="Open-interest change"
        :value="signedCompact(levels?.total_oi_delta)"
        unit="contracts"
        :tone="signedTone(levels?.total_oi_delta)"
        :context="comparisonContext"
      />
      <UiMetric
        label="Volume change"
        :value="signedCompact(levels?.total_volume_delta)"
        unit="contracts"
        :tone="signedTone(levels?.total_volume_delta)"
        :context="comparisonContext"
      />
    </div>
  </UiPanel>
</template>
