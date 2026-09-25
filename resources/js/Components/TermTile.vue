<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import UiBadge from './UI/UiBadge.vue'
import UiDataTable from './UI/UiDataTable.vue'
import UiHelpDialog from './UI/UiHelpDialog.vue'
import UiMetric from './UI/UiMetric.vue'
import UiPanel from './UI/UiPanel.vue'
import UiStatus from './UI/UiStatus.vue'
import { dateLabel, numeric } from './UI/numbers.js'

const props = defineProps({
  items: { type: Array, default: () => [] },
  date: String,
})

const emit = defineEmits(['reading-inspected'])

const selectedIndex = ref(0)
const chartHost = ref(null)
const chartWidth = ref(600)
let resizeObserver

function percentage(value, { signed = false } = {}) {
  const number = numeric(value)
  if (number == null) return null
  const percent = number * 100
  const magnitude = Math.abs(percent).toFixed(1)
  if (Number(magnitude) === 0) return '0.0'
  const sign = signed && percent > 0 ? '+' : percent < 0 ? '−' : ''
  return `${sign}${magnitude}`
}

function axis(value) {
  const number = numeric(value)
  if (number == null) return 'Unavailable'
  if (number === 0) return '0'
  return `${number < 0 ? '−' : ''}${Math.abs(number).toFixed(Math.abs(number) >= 10 ? 0 : 1)}`
}

const termItems = computed(() => props.items.map((item, index) => ({
  ...item,
  row_key: `${item?.exp ?? item?.tenor ?? 'expiry'}-${index}`,
  expiry: item?.exp ?? null,
  iv_value: numeric(item?.iv),
  iv_percent: numeric(item?.iv) == null ? null : numeric(item.iv) * 100,
  source_chain_date: item?.source_chain_date ?? null,
  source_index: index,
})))

const availablePoints = computed(() => termItems.value.filter(item => item.iv_percent != null))
const frontPoint = computed(() => availablePoints.value[0] ?? null)
const backPoint = computed(() => availablePoints.value.at(-1) ?? null)
const curveSpread = computed(() => {
  if (!frontPoint.value || !backPoint.value || frontPoint.value === backPoint.value) return null
  return backPoint.value.iv_value - frontPoint.value.iv_value
})
const curveLabel = computed(() => {
  const spread = numeric(curveSpread.value)
  if (spread == null) return 'Unavailable'
  if (Number(Math.abs(spread * 100).toFixed(1)) === 0) return 'Flat'
  return spread > 0 ? 'Contango' : 'Backwardation'
})
const curveTone = computed(() => {
  if (curveLabel.value === 'Unavailable') return 'warning'
  if (curveLabel.value === 'Flat') return 'data'
  return curveLabel.value === 'Contango' ? 'positive' : 'warning'
})
const curveContext = computed(() => {
  if (!frontPoint.value || !backPoint.value || frontPoint.value === backPoint.value) {
    return 'At least two usable expiries are needed to read the curve.'
  }
  const direction = curveLabel.value === 'Contango' ? 'rises' : curveLabel.value === 'Backwardation' ? 'falls' : 'is effectively unchanged'
  return `IV ${direction} from ${frontPoint.value.expiry ?? 'the front'} to ${backPoint.value.expiry ?? 'the back expiry'}.`
})

const selectedItem = computed(() => termItems.value[selectedIndex.value] ?? null)
const selectedPercent = computed(() => selectedItem.value?.iv_percent ?? null)
const selectedAria = computed(() => {
  if (!selectedItem.value) return 'No term structure readings'
  const expiry = selectedItem.value.expiry ?? `Reading ${selectedIndex.value + 1}`
  return `${expiry}, ${selectedPercent.value == null ? 'IV unavailable' : `${selectedPercent.value.toFixed(1)} percent implied volatility`}`
})

function inspectExpiry(event) {
  const nextIndex = Number(event?.target?.value)
  if (Number.isInteger(nextIndex) && termItems.value[nextIndex]?.iv_percent != null) {
    emit('reading-inspected')
  }
}

watch(() => props.items, (items, previousItems) => {
  const previousExpiry = previousItems?.[selectedIndex.value]?.exp ?? null
  const retainedIndex = previousExpiry == null
    ? -1
    : termItems.value.findIndex(item => item.expiry === previousExpiry)
  const firstAvailableIndex = termItems.value.findIndex(item => item.iv_percent != null)
  selectedIndex.value = retainedIndex >= 0 ? retainedIndex : Math.max(0, firstAvailableIndex)
}, { deep: true, immediate: true })

const chartHeight = 250
const plot = { left: 54, right: 24, top: 26, bottom: 44 }
const plotWidth = computed(() => chartWidth.value - plot.left - plot.right)
const plotHeight = chartHeight - plot.top - plot.bottom

const chartRange = computed(() => {
  const values = availablePoints.value.map(item => item.iv_percent)
  if (!values.length) return { low: 0, high: 1 }
  const minimum = Math.min(...values)
  const maximum = Math.max(...values)
  const spread = maximum - minimum
  const padding = Math.max(spread * 0.16, Math.abs(maximum) * 0.04, 0.25)
  const low = minimum >= 0 ? Math.max(0, minimum - padding) : minimum - padding
  const high = Math.max(maximum + padding, low + 0.5)
  return { low, high }
})
const x = index => termItems.value.length <= 1
  ? plot.left + plotWidth.value / 2
  : plot.left + (index / (termItems.value.length - 1)) * plotWidth.value
const y = value => plot.top + ((chartRange.value.high - value) / (chartRange.value.high - chartRange.value.low)) * plotHeight
const curvePath = computed(() => {
  let connected = false
  return termItems.value.map((item, index) => {
    if (item.iv_percent == null) {
      connected = false
      return ''
    }
    const command = connected ? 'L' : 'M'
    connected = true
    return `${command}${x(index)},${y(item.iv_percent)}`
  }).join(' ')
})
const ticks = computed(() => Array.from({ length: 4 }, (_, index) => (
  chartRange.value.low + ((chartRange.value.high - chartRange.value.low) * index) / 3
)))
const firstExpiryLabel = computed(() => termItems.value[0]?.expiry ? dateLabel(termItems.value[0].expiry) : 'Front')
const lastExpiryLabel = computed(() => termItems.value.at(-1)?.expiry ? dateLabel(termItems.value.at(-1).expiry) : 'Back')

watch(chartHost, element => {
  resizeObserver?.disconnect()
  resizeObserver = null
  if (typeof ResizeObserver === 'undefined' || !element) return
  resizeObserver = new ResizeObserver(entries => {
    const nextWidth = Math.round(entries[0]?.contentRect?.width ?? 0)
    if (nextWidth > 0) chartWidth.value = Math.max(320, nextWidth)
  })
  resizeObserver.observe(element)
}, { flush: 'post' })

onBeforeUnmount(() => resizeObserver?.disconnect())

const tableColumns = [
  { key: 'expiry', label: 'Expiry', sortable: true },
  { key: 'iv_percent', label: 'ATM IV', sortable: true, numeric: true, format: value => `${Number(value).toFixed(1)}%` },
  { key: 'source_chain_date', label: 'As of', sortable: true },
]
</script>

<template>
  <UiPanel
    title="Term structure"
    subtitle="ATM implied volatility across expiries, from front to back"
    tone="data"
    data-testid="volatility-term"
  >
    <template #actions>
      <div class="gex-row">
        <UiBadge v-if="date" tone="data">Data as of {{ date }}</UiBadge>
        <UiBadge tone="neutral">{{ termItems.length }} expiries</UiBadge>
        <UiHelpDialog
          id="volatility-term-guide"
          title="How to read term structure"
          trigger-label="Reading guide"
        >
          <div class="gex-stack">
            <p><strong>Term structure</strong> compares ATM implied volatility across expiration dates. The front of the curve is the nearest usable expiry; the back is the furthest returned expiry.</p>
            <p><strong>Contango</strong> means back-expiry IV is above front-expiry IV. <strong>Backwardation</strong> means front IV is higher, which can accompany event risk or market stress.</p>
            <p>Use the expiry control to compare implied volatility across dates.</p>
            <p>Term shape describes relative option pricing. Pair it with VRP, positioning, liquidity, and price action before choosing a trade.</p>
          </div>
        </UiHelpDialog>
      </div>
    </template>

    <div class="gex-grid term-summary">
      <UiMetric
        label="Curve regime"
        :value="curveLabel"
        :tone="curveTone"
        prominence="primary"
        :context="curveContext"
      />
      <UiMetric
        label="Front IV"
        :value="percentage(frontPoint?.iv_value)"
        unit="%"
        tone="data"
        :context="frontPoint?.expiry ?? 'Nearest usable expiry unavailable'"
      />
      <UiMetric
        label="Back minus front"
        :value="percentage(curveSpread, { signed: true })"
        unit="pp"
        :tone="curveTone"
        :context="backPoint?.expiry ? `Through ${backPoint.expiry}` : 'Back expiry unavailable'"
      />
    </div>

    <section v-if="termItems.length" ref="chartHost" class="term-chart-shell" aria-label="Term structure chart">
      <div class="gex-chart-heading">
        <div>
          <h3>Expiry curve</h3>
          <p>{{ availablePoints.length }} of {{ termItems.length }} usable IV readings</p>
        </div>
        <output class="gex-chart-value gex-number" aria-live="polite">
          <span>{{ selectedItem?.expiry ?? `Reading ${selectedIndex + 1}` }}</span>
          <strong>{{ selectedPercent == null ? 'IV unavailable' : `${selectedPercent.toFixed(1)}% IV` }}</strong>
          <small>{{ selectedItem?.source_chain_date ? `As of ${selectedItem.source_chain_date}` : '' }}</small>
        </output>
      </div>

      <div class="gex-legend" aria-label="Term structure chart legend">
        <span><i class="gex-line-swatch" aria-hidden="true" />ATM IV</span>
        <span><i class="term-selected-swatch" aria-hidden="true" />Selected expiry</span>
        <span><i class="gex-gap-swatch" aria-hidden="true" />No reading</span>
      </div>

      <svg
        class="term-chart"
        :viewBox="`0 0 ${chartWidth} ${chartHeight}`"
        role="img"
        :aria-label="`Term structure with ${termItems.length} expiries and ${availablePoints.length} usable IV readings. Use the expiry control below for individual values.`"
      >
        <template v-if="availablePoints.length">
          <template v-for="tick in ticks" :key="tick">
            <line class="term-grid" :x1="plot.left" :x2="chartWidth - plot.right" :y1="y(tick)" :y2="y(tick)" />
            <text :x="plot.left - 9" :y="y(tick) + 4" text-anchor="end">{{ axis(tick) }}</text>
          </template>
          <text :x="plot.left" y="17">IV %</text>
          <path class="term-line" :d="curvePath" />
          <circle
            v-for="point in availablePoints"
            :key="point.row_key"
            class="term-point"
            :cx="x(point.source_index)"
            :cy="y(point.iv_percent)"
            r="3"
          />
          <circle
            v-if="frontPoint"
            class="term-front-halo"
            :cx="x(frontPoint.source_index)"
            :cy="y(frontPoint.iv_percent)"
            r="9"
          />
        </template>
        <g v-else class="term-empty">
          <text :x="chartWidth / 2" y="112" text-anchor="middle">No usable IV readings</text>
          <text :x="chartWidth / 2" y="132" text-anchor="middle">Returned expiries remain available below</text>
        </g>
        <template v-if="selectedPercent != null">
          <line class="term-guide" :x1="x(selectedIndex)" :x2="x(selectedIndex)" :y1="plot.top" :y2="chartHeight - plot.bottom" />
          <circle class="term-selected-halo" :cx="x(selectedIndex)" :cy="y(selectedPercent)" r="10" />
          <circle class="term-selected-point" :cx="x(selectedIndex)" :cy="y(selectedPercent)" r="4" />
        </template>
        <text :x="plot.left" :y="chartHeight - 15">{{ firstExpiryLabel }}</text>
        <text :x="chartWidth - plot.right" :y="chartHeight - 15" text-anchor="end">{{ lastExpiryLabel }}</text>
      </svg>

      <label class="term-scrubber">
        <span>Inspect expiry <small>Keyboard: arrow keys</small></span>
        <input
          v-model.number="selectedIndex"
          type="range"
          min="0"
          :max="Math.max(0, termItems.length - 1)"
          :disabled="termItems.length <= 1"
          aria-label="Term structure expiry"
          :aria-valuetext="selectedAria"
          @input="inspectExpiry"
        />
      </label>

      <details data-testid="term-readings-disclosure">
        <summary>All {{ termItems.length }} expiry readings</summary>
        <UiDataTable
          caption="Term structure by expiry"
          :rows="termItems"
          :columns="tableColumns"
          row-key="row_key"
        />
      </details>
    </section>

    <UiStatus
      v-else
      state="sparse"
      title="No term structure data"
      message="No expiration readings are available for this data set."
    />
  </UiPanel>
</template>

<style scoped>
.term-summary {
  grid-template-columns: repeat(3, minmax(0, 1fr));
  margin-bottom: 20px;
}

.term-chart-shell {
  min-width: 0;
  border: 1px solid var(--gex-border);
  border-radius: 12px;
  padding: 16px;
  background: color-mix(in srgb, var(--gex-raised) 42%, transparent);
}

.gex-chart-value small {
  color: var(--gex-muted);
  font-size: 10px;
}

.term-selected-swatch {
  display: inline-block;
  width: 9px;
  height: 9px;
  margin-right: 6px;
  border: 2px solid var(--gex-action);
  border-radius: 50%;
  vertical-align: middle;
}

.term-chart {
  display: block;
  width: 100%;
  height: auto;
  margin-top: 12px;
  border: 1px solid var(--gex-border);
  border-radius: 10px;
  background: linear-gradient(180deg, color-mix(in srgb, var(--gex-data-soft) 28%, transparent), transparent 62%);
}

.term-chart text {
  fill: var(--gex-muted);
  font-size: 11px;
}

.term-grid {
  stroke: var(--gex-border);
  opacity: .72;
}

.term-line {
  fill: none;
  stroke: var(--gex-data);
  stroke-width: 3;
  stroke-linecap: round;
  stroke-linejoin: round;
}

.term-point,
.term-selected-point {
  fill: var(--gex-data);
  stroke: var(--gex-surface);
  stroke-width: 1.5;
}

.term-front-halo {
  fill: var(--gex-positive);
  opacity: .18;
}

.term-guide {
  stroke: var(--gex-action);
  stroke-width: 1.5;
  stroke-dasharray: 3 4;
}

.term-selected-halo {
  fill: var(--gex-action);
  opacity: .22;
}

.term-selected-point {
  fill: var(--gex-action);
}

.term-empty text:first-child {
  fill: var(--gex-text);
  font-size: 13px;
  font-weight: 650;
}

.term-empty text:last-child {
  font-size: 11px;
}

.term-scrubber {
  display: block;
  padding: 0 24px 0 54px;
  margin-top: 10px;
  color: var(--gex-muted);
  font-size: 12px;
}

.term-scrubber > span {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}

.term-scrubber small {
  font-weight: 400;
}

.term-scrubber input {
  display: block;
  width: 100%;
  margin: 8px 0 4px;
}

@media (max-width: 600px) {
  .term-chart-shell {
    padding: 12px;
  }

  .term-scrubber {
    padding-inline: 22px;
  }
}

@container (max-width: 430px) {
  .term-summary {
    grid-template-columns: 1fr;
  }
}
</style>
