<script setup>
import { computed } from 'vue'
import UiBadge from './UI/UiBadge.vue'
import UiHelpDialog from './UI/UiHelpDialog.vue'
import UiMetric from './UI/UiMetric.vue'
import UiPanel from './UI/UiPanel.vue'
import UiStatus from './UI/UiStatus.vue'
import { numeric } from './UI/numbers.js'

const props = defineProps({
  date: String,
  iv1m: [Number, String],
  rv20: [Number, String],
  vrp: [Number, String],
  z: [Number, String],
  sourceMeta: { type: Object, default: null },
})

const ivValue = computed(() => numeric(props.iv1m))
const rvValue = computed(() => numeric(props.rv20))
const vrpValue = computed(() => numeric(props.vrp))
const zValue = computed(() => numeric(props.z))
const hasAnyData = computed(() => [ivValue.value, rvValue.value, vrpValue.value, zValue.value].some(value => value != null))
const fallbackMessage = computed(() => {
  const reason = props.sourceMeta?.fallback_reason
  if (reason === 'no_term_rows') return 'No term structure rows were available for the one-month implied-volatility estimate.'
  if (reason === 'no_non_null_iv') return 'Term expiries were returned without a usable implied-volatility estimate.'
  return null
})
const emptyMessage = computed(() => fallbackMessage.value
  ?? 'Implied volatility, realized volatility, VRP, and the historical z-score are unavailable for this snapshot.')

function percent(value, { signed = false } = {}) {
  const number = numeric(value)
  if (number == null) return null
  const scaled = number * 100
  const magnitude = Math.abs(scaled).toFixed(1)
  if (Number(magnitude) === 0) return '0.0'
  const sign = signed && scaled > 0 ? '+' : scaled < 0 ? '−' : ''
  return `${sign}${magnitude}`
}

function sigma(value) {
  const number = numeric(value)
  if (number == null) return null
  if (number === 0) return '0.00σ'
  return `${number > 0 ? '+' : '−'}${Math.abs(number).toFixed(2)}σ`
}

const regime = computed(() => {
  if (zValue.value == null) {
    return {
      label: 'Signal unavailable',
      tone: 'warning',
      context: 'A historical z-score is needed to classify relative richness.',
    }
  }
  if (zValue.value >= 1) {
    return {
      label: 'IV rich',
      tone: 'positive',
      context: 'The volatility premium is at least one standard deviation above its historical mean.',
    }
  }
  if (zValue.value <= -1) {
    return {
      label: 'IV cheap',
      tone: 'data',
      context: 'The volatility premium is at least one standard deviation below its historical mean.',
    }
  }
  return {
    label: 'Balanced',
    tone: 'neutral',
    context: 'The volatility premium is within one historical standard deviation of its mean.',
  }
})

const vrpTone = computed(() => {
  if (vrpValue.value == null || vrpValue.value === 0) return 'data'
  return vrpValue.value > 0 ? 'positive' : 'data'
})
const vrpContext = computed(() => {
  if (vrpValue.value == null) return 'IV minus realized volatility is unavailable.'
  if (vrpValue.value === 0) return 'One-month implied and 20-session realized volatility are equal.'
  return vrpValue.value > 0
    ? 'One-month implied volatility is above recent realized volatility.'
    : 'One-month implied volatility is below recent realized volatility.'
})
const gaugeValue = computed(() => zValue.value == null ? null : Math.max(-3, Math.min(3, zValue.value)))
const gaugePosition = computed(() => gaugeValue.value == null ? null : ((gaugeValue.value + 3) / 6) * 100)
const gaugeLabel = computed(() => zValue.value == null
  ? 'Historical VRP z-score unavailable'
  : `Historical VRP z-score ${sigma(zValue.value)}${Math.abs(zValue.value) > 3 ? '; marker limited to the three standard deviation display boundary' : ''}`)
</script>

<template>
  <UiPanel
    title="Variance risk premium"
    subtitle="One-month implied volatility minus the stored RV(20) measure"
    tone="data"
    data-testid="volatility-vrp"
  >
    <template #actions>
      <div class="gex-row">
        <UiBadge v-if="date" tone="data">Snapshot {{ date }}</UiBadge>
        <UiBadge :tone="regime.tone">{{ regime.label }}</UiBadge>
        <UiHelpDialog
          id="volatility-vrp-guide"
          title="How to read variance risk premium"
          trigger-label="Reading guide"
        >
          <div class="gex-stack">
            <p><strong>IV (1M)</strong> is the ATM implied volatility nearest to roughly 21 calendar days. <strong>RV (20)</strong> is the annualized recent realized-volatility measure returned by the API.</p>
            <p><strong>VRP = IV (1M) − RV (20).</strong> A positive value means options imply more volatility than the underlying recently realized. A negative value means implied volatility is lower.</p>
            <p>The <strong>z-score</strong> compares the current VRP with up to 252 prior daily VRP readings. Values at or beyond ±1σ receive the rich or cheap labels.</p>
            <p>VRP measures relative pricing, not direction. Read it with term structure, skew, positioning, liquidity, and price action.</p>
          </div>
        </UiHelpDialog>
      </div>
    </template>

    <template v-if="hasAnyData">
      <div class="gex-grid vrp-summary">
        <UiMetric
          label="Volatility premium"
          :value="percent(vrpValue, { signed: true })"
          unit="pp"
          :tone="vrpTone"
          prominence="primary"
          :context="vrpContext"
        />
        <UiMetric
          label="Implied volatility"
          :value="percent(ivValue)"
          unit="%"
          tone="data"
          context="ATM IV nearest to the one-month target"
        />
        <UiMetric
          label="Realized volatility"
          :value="percent(rvValue)"
          unit="%"
          tone="data"
          context="Annualized recent volatility stored as RV(20)"
        />
      </div>

      <section class="vrp-regime" aria-label="VRP historical regime">
        <div class="vrp-regime__heading">
          <div>
            <span class="gex-metric-label">Historical position</span>
            <strong class="gex-number">{{ sigma(zValue) ?? 'Unavailable' }}</strong>
          </div>
          <UiBadge :tone="regime.tone">{{ regime.label }}</UiBadge>
        </div>
        <div
          v-if="gaugePosition != null"
          class="vrp-gauge"
          role="meter"
          aria-label="VRP historical z-score"
          aria-valuemin="-3"
          aria-valuemax="3"
          :aria-valuenow="gaugeValue"
          :aria-valuetext="gaugeLabel"
        >
          <span class="vrp-gauge__zone vrp-gauge__zone--cheap" aria-hidden="true" />
          <span class="vrp-gauge__zone vrp-gauge__zone--balanced" aria-hidden="true" />
          <span class="vrp-gauge__zone vrp-gauge__zone--rich" aria-hidden="true" />
          <span class="vrp-gauge__zero" aria-hidden="true" />
          <span class="vrp-gauge__threshold vrp-gauge__threshold--low" aria-hidden="true" />
          <span class="vrp-gauge__threshold vrp-gauge__threshold--high" aria-hidden="true" />
          <span class="vrp-gauge__pointer" :style="{ left: `${gaugePosition}%` }" aria-hidden="true" />
        </div>
        <div v-else class="vrp-gauge vrp-gauge--empty" aria-label="Historical VRP z-score unavailable">
          Historical comparison unavailable
        </div>
        <div class="vrp-gauge__labels" aria-hidden="true">
          <span>−3σ</span><span>−1σ</span><span>0</span><span>+1σ</span><span>+3σ</span>
        </div>
        <p>{{ regime.context }}</p>
      </section>

    </template>

    <UiStatus
      v-else
      state="sparse"
      title="No volatility premium data"
      :message="emptyMessage"
    />

    <details v-if="hasAnyData || sourceMeta" class="vrp-calculation" data-testid="vrp-calculation-disclosure">
      <summary>Calculation and source details</summary>
      <div>
        <p>VRP uses stored decimal volatility values and displays them in percentage points. The z-score needs at least 30 earlier non-missing VRP observations and uses up to 252.</p>
        <dl v-if="sourceMeta" class="vrp-source gex-small">
          <div><dt>Anchor date</dt><dd>{{ sourceMeta.anchor_date ?? date ?? 'Unavailable' }}</dd></div>
          <div><dt>Selected expiry</dt><dd>{{ sourceMeta.selected_exp_date ?? 'Unavailable' }}</dd></div>
          <div><dt>Source chain date</dt><dd>{{ sourceMeta.source_chain_date ?? 'Unavailable' }}</dd></div>
          <div><dt>Fallback reason</dt><dd>{{ fallbackMessage ?? 'None' }}</dd></div>
        </dl>
      </div>
    </details>
  </UiPanel>
</template>

<style scoped>
.vrp-summary {
  grid-template-columns: repeat(3, minmax(0, 1fr));
  margin-bottom: 20px;
}

.vrp-regime {
  border: 1px solid var(--gex-border);
  border-radius: 12px;
  padding: 16px;
  background: color-mix(in srgb, var(--gex-raised) 42%, transparent);
}

.vrp-regime__heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
}

.vrp-regime__heading > div {
  display: grid;
  gap: 2px;
}

.vrp-regime__heading strong {
  color: var(--gex-text);
  font-size: 23px;
}

.vrp-regime > p {
  margin-top: 12px;
  color: var(--gex-muted);
  font-size: 12px;
}

.vrp-gauge {
  position: relative;
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  height: 16px;
  margin-top: 16px;
  overflow: visible;
  border: 1px solid var(--gex-border);
  border-radius: 999px;
  background: var(--gex-raised);
}

.vrp-gauge__zone {
  opacity: .5;
}

.vrp-gauge__zone--cheap {
  border-radius: 999px 0 0 999px;
  background: color-mix(in srgb, var(--gex-data) 38%, transparent);
}

.vrp-gauge__zone--balanced {
  background: color-mix(in srgb, var(--gex-muted) 14%, transparent);
}

.vrp-gauge__zone--rich {
  border-radius: 0 999px 999px 0;
  background: color-mix(in srgb, var(--gex-positive) 38%, transparent);
}

.vrp-gauge__zero,
.vrp-gauge__threshold,
.vrp-gauge__pointer {
  position: absolute;
  top: -5px;
  bottom: -5px;
  width: 1px;
  background: var(--gex-muted);
}

.vrp-gauge__zero {
  left: 50%;
  width: 2px;
}

.vrp-gauge__threshold {
  border-left: 1px dashed var(--gex-muted);
  background: transparent;
  opacity: .65;
}

.vrp-gauge__threshold--low {
  left: 33.333%;
}

.vrp-gauge__threshold--high {
  left: 66.667%;
}

.vrp-gauge__pointer {
  top: -7px;
  bottom: -7px;
  width: 3px;
  border-radius: 999px;
  background: var(--gex-action);
  box-shadow: 0 0 0 4px color-mix(in srgb, var(--gex-action) 18%, transparent);
  transform: translateX(-50%);
  transition: left var(--gex-duration) var(--gex-ease);
}

.vrp-gauge--empty {
  display: grid;
  height: auto;
  min-height: 38px;
  place-items: center;
  overflow: hidden;
  border-style: dashed;
  color: var(--gex-muted);
  font-size: 11px;
}

.vrp-gauge__labels {
  position: relative;
  height: 16px;
  margin-top: 8px;
  color: var(--gex-muted);
  font-size: 10px;
}

.vrp-gauge__labels span {
  position: absolute;
  transform: translateX(-50%);
}

.vrp-gauge__labels span:nth-child(1) {
  left: 0;
  transform: none;
}

.vrp-gauge__labels span:nth-child(2) {
  left: 33.333%;
}

.vrp-gauge__labels span:nth-child(3) {
  left: 50%;
}

.vrp-gauge__labels span:nth-child(4) {
  left: 66.667%;
}

.vrp-gauge__labels span:nth-child(5) {
  right: 0;
  transform: none;
}

.vrp-calculation {
  margin-top: 14px;
}

.vrp-calculation > div {
  padding: 12px 14px;
  border-radius: 9px;
  background: var(--gex-raised);
  color: var(--gex-muted);
  font-size: 12px;
}

.vrp-source {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 12px;
  margin: 12px 0 0;
}

.vrp-source div,
.vrp-source dd {
  margin: 0;
}

.vrp-source dt {
  color: var(--gex-muted);
}

.vrp-source dd {
  color: var(--gex-text);
}

@media (prefers-reduced-motion: reduce) {
  .vrp-gauge__pointer {
    transition: none;
  }
}

@container (max-width: 430px) {
  .vrp-summary {
    grid-template-columns: 1fr;
  }
}
</style>
