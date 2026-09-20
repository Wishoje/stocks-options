<script setup>
import { computed, ref, watch } from 'vue'
import { Bar } from 'vue-chartjs'
import {
  BarElement,
  CategoryScale,
  Chart as ChartJS,
  Legend,
  LinearScale,
  Tooltip,
} from 'chart.js'
import zoomPlugin from 'chartjs-plugin-zoom'
import UiButton from '../UI/UiButton.vue'
import UiSelect from '../UI/UiSelect.vue'
import UiStatus from '../UI/UiStatus.vue'
import { downloadChartSnapshot } from '../EodStrikes/strikeChartUtils.js'
import {
  buildFlowPoints,
  chartFlowRows,
  compactUnsigned,
  focusFlowBand,
  flowSnapshotName,
  ratioLabel,
  strongestFlowPoint,
} from './intradayFlowUtils.js'

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip, Legend, zoomPlugin)

const props = defineProps({
  rows: { type: Array, default: () => [] },
  symbol: { type: String, default: '' },
  tradeDate: { type: String, default: '' },
  sourceAsOf: { type: String, default: '' },
  snapshotName: { type: String, default: '' },
})
const emit = defineEmits(['reading-inspected'])

const chart = ref(null)
const focusActivity = ref(true)
const groupDenseStrikes = ref(true)
const selectedLabel = ref('')
const MAX_BARS = 90

const sortedRows = computed(() => chartFlowRows(props.rows))
const focusedRows = computed(() => focusActivity.value ? focusFlowBand(sortedRows.value) : sortedRows.value)
const displayPoints = computed(() => buildFlowPoints(focusedRows.value, {
  bucket: groupDenseStrikes.value,
  maxBars: MAX_BARS,
}))
const hasReading = point => [point?.callVolume, point?.putVolume].some(value => (
  value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value))
))
const hasChartData = computed(() => displayPoints.value.some(hasReading))
const selectedPoint = computed(() => displayPoints.value.find(point => point.label === selectedLabel.value) ?? null)
const selectedOptions = computed(() => hasChartData.value
  ? displayPoints.value.filter(hasReading).map(point => ({
      value: point.label,
      label: `${point.sourceCount > 1 ? 'Strikes' : 'Strike'} ${point.label}`,
    }))
  : [])

watch(displayPoints, points => {
  if (points.some(point => point.label === selectedLabel.value)) return
  selectedLabel.value = strongestFlowPoint(points)?.label ?? ''
}, { immediate: true, deep: true })

function reducedMotion() {
  return typeof window !== 'undefined'
    && typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

function exact(value) {
  return value == null ? 'Unavailable' : String(value)
}

const chartData = computed(() => {
  const selectedBorders = displayPoints.value.map(point => point.label === selectedLabel.value ? '#9bb4ff' : 'transparent')
  const selectedWidths = displayPoints.value.map(point => point.label === selectedLabel.value ? 2 : 0)
  return {
    labels: displayPoints.value.map(point => point.label),
    datasets: [
      {
        label: 'Call volume',
        data: displayPoints.value.map(point => point.callVolume),
        backgroundColor: '#73d6b1',
        borderColor: selectedBorders,
        borderWidth: selectedWidths,
        borderRadius: 3,
        stack: 'session-volume',
      },
      {
        label: 'Put volume',
        data: displayPoints.value.map(point => point.putVolume),
        backgroundColor: '#f09589',
        borderColor: selectedBorders,
        borderWidth: selectedWidths,
        borderRadius: 3,
        stack: 'session-volume',
      },
    ],
  }
})

const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  normalized: true,
  animation: reducedMotion() ? false : { duration: 180 },
  interaction: { mode: 'index', intersect: false },
  onClick: selectFromChart,
  plugins: {
    legend: {
      display: true,
      labels: { color: '#f2f4f7', usePointStyle: true, pointStyle: 'rectRounded' },
    },
    tooltip: {
      backgroundColor: 'rgba(23,30,38,0.98)',
      borderColor: '#313e4c',
      borderWidth: 1,
      padding: 10,
      callbacks: {
        title: items => `${displayPoints.value[items[0]?.dataIndex]?.sourceCount > 1 ? 'Strike range' : 'Strike'} ${items[0]?.label ?? ''}`,
        label: context => `${context.dataset.label}: ${exact(context.raw)} contracts`,
        afterBody: items => {
          const point = displayPoints.value[items[0]?.dataIndex]
          return point?.sourceCount > 1 ? `${point.sourceCount} raw strike rows grouped for display` : ''
        },
      },
    },
    zoom: {
      pan: { enabled: true, mode: 'x' },
      zoom: { wheel: { enabled: true }, pinch: { enabled: true }, mode: 'x' },
    },
  },
  scales: {
    x: {
      stacked: true,
      title: {
        display: true,
        text: groupDenseStrikes.value ? 'Strike range' : 'Strike',
        color: '#aeb7c2',
        font: { size: 11 },
      },
      grid: { display: false },
      ticks: { color: '#aeb7c2', autoSkip: true, maxTicksLimit: 18 },
    },
    y: {
      stacked: true,
      beginAtZero: true,
      title: {
        display: true,
        text: 'Session volume (contracts)',
        color: '#aeb7c2',
        font: { size: 11 },
      },
      grid: {
        color: context => Number(context.tick?.value) === 0 ? 'rgba(174,183,194,0.82)' : 'rgba(49,62,76,0.62)',
        lineWidth: context => Number(context.tick?.value) === 0 ? 2 : 1,
      },
      ticks: { color: '#aeb7c2', callback: value => compactUnsigned(value) },
    },
  },
}))

function selectFromChart(_event, elements) {
  const index = elements?.[0]?.index
  const point = displayPoints.value[index]
  if (index == null || !hasReading(point)) return
  selectedLabel.value = point.label
  emit('reading-inspected')
}

function selectFromControl(label) {
  const point = displayPoints.value.find(item => item.label === label)
  if (!hasReading(point)) return
  selectedLabel.value = label
  emit('reading-inspected')
}

function resetZoom() {
  chart.value?.chart?.resetZoom?.()
}

function download() {
  const name = props.snapshotName || flowSnapshotName(props.symbol, props.tradeDate, props.sourceAsOf)
  return downloadChartSnapshot(chart.value?.chart, name)
}

defineExpose({
  chartData,
  chartOptions,
  displayPoints,
  focusedRows,
  groupDenseStrikes,
  hasChartData,
  selectedLabel,
  selectedPoint,
  sortedRows,
  selectFromChart,
  resetZoom,
  download,
})
</script>

<template>
  <section class="intraday-flow-chart" aria-label="Intraday session volume by strike">
    <div class="intraday-flow-chart__heading">
      <div>
        <h3>Session volume by strike</h3>
        <p>
          {{ displayPoints.length }} displayed · {{ sortedRows.length }} numeric strikes · {{ rows.length }} raw readings
        </p>
      </div>
      <div class="intraday-flow-chart__actions">
        <UiButton :disabled="!hasChartData" @click="resetZoom">Reset zoom</UiButton>
        <UiButton :disabled="!hasChartData" @click="download">Download PNG</UiButton>
      </div>
    </div>

    <div class="intraday-flow-chart__controls">
      <label class="gex-check">
        <input v-model="focusActivity" type="checkbox">
        <span>Focus on activity</span>
      </label>
      <label class="gex-check">
        <input v-model="groupDenseStrikes" type="checkbox">
        <span>Group dense strikes</span>
      </label>
      <span>Display controls leave every source row available below.</span>
    </div>

    <div v-if="selectedOptions.length" class="intraday-flow-chart__selection">
      <UiSelect
        :model-value="selectedLabel"
        label="Inspect strike"
        :options="selectedOptions"
        @update:model-value="selectFromControl"
      />
      <div v-if="selectedPoint" class="intraday-flow-chart__selected" aria-live="polite">
        <strong>{{ selectedPoint.sourceCount > 1 ? 'Strike range' : 'Strike' }} {{ selectedPoint.label }}</strong>
        <span :title="exact(selectedPoint.callVolume)">Calls {{ compactUnsigned(selectedPoint.callVolume) }}</span>
        <span :title="exact(selectedPoint.putVolume)">Puts {{ compactUnsigned(selectedPoint.putVolume) }}</span>
        <span>PCR {{ ratioLabel(selectedPoint.pcr) }}</span>
        <small v-if="selectedPoint.sourceCount > 1">{{ selectedPoint.sourceCount }} source strikes</small>
      </div>
    </div>

    <UiStatus
      v-if="!hasChartData"
      state="sparse"
      title="Session volume by strike is unavailable"
      message="No numeric call or put volume readings were returned. Zero remains a valid reading when the source provides it."
    />
    <div v-else class="intraday-flow-chart__canvas">
      <Bar
        ref="chart"
        :data="chartData"
        :options="chartOptions"
        role="img"
        :aria-label="`Cumulative call and put session volume by strike; ${displayPoints.length} displayed points. Use Inspect strike for values.`"
      />
    </div>
    <p v-if="hasChartData" class="intraday-flow-chart__hint">
      Click or tap a bar to inspect it. Keyboard users can use the Inspect strike control. Pan horizontally, use the mouse wheel, or pinch to zoom.
    </p>
  </section>
</template>

<style scoped>
.intraday-flow-chart {
  min-width: 0;
  margin-top: 18px;
  border: 1px solid var(--gex-border);
  border-radius: 12px;
  padding: 16px;
  background: color-mix(in srgb, var(--gex-raised) 42%, transparent);
}

.intraday-flow-chart__heading,
.intraday-flow-chart__actions,
.intraday-flow-chart__controls,
.intraday-flow-chart__selected {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
}

.intraday-flow-chart__heading {
  align-items: flex-start;
  justify-content: space-between;
}

.intraday-flow-chart__heading p,
.intraday-flow-chart__controls,
.intraday-flow-chart__hint {
  color: var(--gex-muted);
  font-size: 12px;
}

.intraday-flow-chart__heading p {
  margin-top: 3px;
}

.intraday-flow-chart__controls {
  margin-top: 12px;
}

.intraday-flow-chart__controls > span {
  flex: 1 1 220px;
}

.intraday-flow-chart__selection {
  display: grid;
  grid-template-columns: minmax(160px, 230px) minmax(0, 1fr);
  align-items: end;
  gap: 14px;
  margin-top: 12px;
  border-top: 1px solid var(--gex-border);
  padding-top: 12px;
}

.intraday-flow-chart__selected {
  min-height: 38px;
  color: var(--gex-muted);
  font-size: 12px;
}

.intraday-flow-chart__selected strong {
  color: var(--gex-text);
}

.intraday-flow-chart__selected span:not(:last-child)::after {
  content: "·";
  margin-left: 10px;
  color: var(--gex-border);
}

.intraday-flow-chart__selected small {
  color: var(--gex-data);
}

.intraday-flow-chart__canvas {
  height: clamp(280px, 42vw, 430px);
  margin-top: 14px;
}

.intraday-flow-chart__hint {
  margin-top: 10px;
}

@media (max-width: 600px) {
  .intraday-flow-chart {
    padding: 12px;
  }

  .intraday-flow-chart__actions,
  .intraday-flow-chart__controls {
    width: 100%;
  }

  .intraday-flow-chart__actions > *,
  .intraday-flow-chart__controls > label {
    flex: 1 1 auto;
  }

  .intraday-flow-chart__selection {
    grid-template-columns: 1fr;
  }
}

@media (prefers-reduced-motion: reduce) {
  .intraday-flow-chart * {
    scroll-behavior: auto !important;
  }
}
</style>
