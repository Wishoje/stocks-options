<script>
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
import EodStrikeChartFrame from './EodStrikes/EodStrikeChartFrame.vue'
import {
  buildStrikePoints,
  chartSnapshotName,
  chartStrikeRows,
  countAvailable,
  downloadChartSnapshot,
  focusActivityBand,
  normalizedStrikeRows,
  strongestByMagnitude,
  sumAvailable,
} from './EodStrikes/strikeChartUtils.js'
import UiDataTable from './UI/UiDataTable.vue'
import UiMetric from './UI/UiMetric.vue'
import { compact, numeric } from './UI/numbers.js'

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip, Legend, zoomPlugin)

const dailyCall = row => numeric(row?.call_oi_delta)
const dailyPut = row => numeric(row?.put_oi_delta)
const weeklyCall = row => numeric(row?.call_oi_wow)
const weeklyPut = row => numeric(row?.put_oi_wow)
const percentage = value => {
  const number = numeric(value)
  return number == null ? 'Unavailable' : (number > 0 ? '+' : '') + number.toFixed(1) + '%'
}

export default {
  name: 'StrikeDeltaChart',
  components: { Bar, EodStrikeChartFrame, UiDataTable, UiMetric },
  emits: ['reading-inspected'],
  props: {
    strikeData: { type: Array, default: () => [] },
    snapshotName: { type: String, default: 'delta-oi' },
    heightClass: { type: String, default: 'h-80 md:h-96 xl:h-[26rem]' },
    symbol: String,
    timeframe: String,
    snapshotDate: String,
    comparisonBasis: { type: String, default: 'daily' },
    comparisonDate: String,
    comparisonGapTradingDays: [Number, String],
    comparisonIsStale: Boolean,
  },
  data() {
    return {
      autoBucket: false,
      focusActivity: true,
      selectedLabel: '',
      MAX_BARS: 100,
      PADDING_STRIKES: 8,
    }
  },
  computed: {
    callAccessor() {
      if (this.comparisonBasis === 'daily') return dailyCall
      if (this.comparisonBasis === 'weekly') return weeklyCall
      return () => null
    },
    putAccessor() {
      if (this.comparisonBasis === 'daily') return dailyPut
      if (this.comparisonBasis === 'weekly') return weeklyPut
      return () => null
    },
    basisSubtitle() {
      if (this.comparisonBasis === 'daily') return 'Daily open interest change for the current strike set'
      if (this.comparisonBasis === 'weekly') return 'Weekly open interest fallback for the current strike set'
      return 'No dated open interest comparison is available for the current strike set'
    },
    emptyTitle() {
      return this.comparisonBasis === 'daily' || this.comparisonBasis === 'weekly'
        ? 'No open interest changes'
        : 'Open interest comparison unavailable'
    },
    emptyMessage() {
      return this.comparisonBasis === 'daily' || this.comparisonBasis === 'weekly'
        ? 'No usable call or put open interest changes were returned for these current strikes.'
        : 'No dated earlier data set was returned, so change values are not plotted. Returned raw fields remain available below.'
    },
    rawRows() {
      return normalizedStrikeRows(this.strikeData)
    },
    sortedData() {
      return chartStrikeRows(this.strikeData)
    },
    focusedData() {
      return focusActivityBand(this.sortedData, [this.callAccessor, this.putAccessor], {
        relativeThreshold: 0.02,
        absoluteThreshold: 50,
        padding: this.PADDING_STRIKES,
      })
    },
    displayPoints() {
      const rows = this.focusActivity ? this.focusedData : this.sortedData
      return buildStrikePoints(rows, {
        call: this.callAccessor,
        put: this.putAccessor,
      }, {
        bucket: this.autoBucket,
        maxBars: this.MAX_BARS,
      })
    },
    hasChartData() {
      return this.displayPoints.some(point => point.call != null || point.put != null)
    },
    totalCall() {
      return sumAvailable(this.sortedData, this.callAccessor)
    },
    totalPut() {
      return sumAvailable(this.sortedData, this.putAccessor)
    },
    callAvailableCount() {
      return countAvailable(this.sortedData, this.callAccessor)
    },
    putAvailableCount() {
      return countAvailable(this.sortedData, this.putAccessor)
    },
    comparisonCoverageComplete() {
      return this.sortedData.length > 0
        && this.callAvailableCount === this.sortedData.length
        && this.putAvailableCount === this.sortedData.length
    },
    totalChange() {
      if (!this.comparisonCoverageComplete) return null
      return this.totalCall + this.totalPut
    },
    totalChangeContext() {
      if (this.comparisonBasis !== 'daily' && this.comparisonBasis !== 'weekly') return 'No comparison available'
      if (!this.comparisonCoverageComplete) {
        return `Call readings ${this.callAvailableCount} · Put readings ${this.putAvailableCount}`
      }
      return this.comparisonBasis === 'daily' ? 'Call plus put daily change' : 'Call plus put weekly change'
    },
    selectedPoint() {
      return this.displayPoints.find(point => point.label === this.selectedLabel) ?? null
    },
    selectedOptions() {
      if (!this.hasChartData) return []
      return this.displayPoints.map(point => ({
        value: point.label,
        label: (point.sourceCount > 1 ? 'Strikes ' : 'Strike ') + point.label,
      }))
    },
    downloadName() {
      if (this.snapshotName !== 'delta-oi') return chartSnapshotName(this.snapshotName)
      return chartSnapshotName(
        this.snapshotName,
        this.symbol,
        this.timeframe,
        this.snapshotDate,
        this.comparisonBasis,
        this.comparisonDate,
      )
    },
    chartData() {
      const borderColor = this.displayPoints.map(point => point.label === this.selectedLabel ? '#9bb4ff' : 'transparent')
      const borderWidth = this.displayPoints.map(point => point.label === this.selectedLabel ? 2 : 0)
      return {
        labels: this.displayPoints.map(point => point.label),
        datasets: [
          {
            label: 'Call OI change',
            data: this.displayPoints.map(point => point.call),
            backgroundColor: 'rgba(115,214,177,0.9)',
            borderColor,
            borderWidth,
            borderRadius: 4,
            barPercentage: 0.84,
            categoryPercentage: 0.9,
          },
          {
            label: 'Put OI change',
            data: this.displayPoints.map(point => point.put),
            backgroundColor: 'rgba(240,149,137,0.9)',
            borderColor,
            borderWidth,
            borderRadius: 4,
            barPercentage: 0.84,
            categoryPercentage: 0.9,
          },
        ],
      }
    },
    chartOptions() {
      const values = this.displayPoints.flatMap(point => [point.call, point.put]).filter(value => numeric(value) != null)
      const maxAbsolute = values.reduce((maximum, value) => Math.max(maximum, Math.abs(value)), 0)
      const padding = maxAbsolute ? maxAbsolute * 0.1 : 0
      return {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        normalized: true,
        interaction: { mode: 'index', intersect: false },
        onClick: this.selectFromChart,
        plugins: {
          legend: {
            display: true,
            labels: { color: '#f2f4f7', usePointStyle: true, pointStyle: 'rectRounded' },
          },
          tooltip: {
            backgroundColor: 'rgba(23,30,38,0.98)',
            borderColor: '#313e4c',
            borderWidth: 1,
            padding: 11,
            titleColor: '#f2f4f7',
            bodyColor: '#aeb7c2',
            callbacks: {
              title: items => {
                const point = this.displayPoints[items[0]?.dataIndex]
                return point?.sourceCount > 1 ? 'Strike range ' + point.label : 'Strike ' + (point?.label ?? items[0]?.label)
              },
              label: context => {
                const value = numeric(context.parsed.y)
                return (context.dataset.label || 'OI change') + ': ' + (value == null ? 'Unavailable' : compact(value) + ' contracts')
              },
              afterBody: items => {
                const point = this.displayPoints[items[0]?.dataIndex]
                return point?.sourceCount > 1 ? point.sourceCount + ' raw strikes grouped' : ''
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
            title: { display: true, text: this.autoBucket ? 'Strike range' : 'Strike', color: '#aeb7c2', font: { size: 11 } },
            grid: { display: false },
            ticks: { autoSkip: true, maxTicksLimit: 16, color: '#aeb7c2', maxRotation: 0 },
          },
          y: {
            min: maxAbsolute ? -(maxAbsolute + padding) : undefined,
            max: maxAbsolute ? maxAbsolute + padding : undefined,
            title: { display: true, text: 'Open interest change · contracts', color: '#aeb7c2', font: { size: 11 } },
            grid: {
              color: context => Number(context.tick?.value) === 0 ? 'rgba(174,183,194,0.82)' : 'rgba(49,62,76,0.62)',
              lineWidth: context => Number(context.tick?.value) === 0 ? 2 : 1,
            },
            ticks: { color: '#aeb7c2', callback: value => compact(value) },
          },
        },
      }
    },
    tableRows() {
      return this.rawRows.map(row => ({
        ...row,
        strike_display: row.__strike,
        used_call: this.callAccessor(row),
        used_put: this.putAccessor(row),
        call_daily: dailyCall(row),
        put_daily: dailyPut(row),
        call_weekly: weeklyCall(row),
        put_weekly: weeklyPut(row),
      }))
    },
    tableColumns() {
      return [
        { key: 'strike_display', label: 'Strike', numeric: true, sortable: true },
        { key: 'used_call', label: 'Displayed call change', numeric: true, sortable: true, format: compact },
        { key: 'used_put', label: 'Displayed put change', numeric: true, sortable: true, format: compact },
        { key: 'call_daily', label: 'Returned daily call field', numeric: true, sortable: true, format: compact },
        { key: 'put_daily', label: 'Returned daily put field', numeric: true, sortable: true, format: compact },
        { key: 'call_oi_delta_pct', label: 'Returned daily call % field', numeric: true, sortable: true, format: percentage },
        { key: 'put_oi_delta_pct', label: 'Returned daily put % field', numeric: true, sortable: true, format: percentage },
        { key: 'call_weekly', label: 'Returned weekly call field', numeric: true, sortable: true, format: compact },
        { key: 'put_weekly', label: 'Returned weekly put field', numeric: true, sortable: true, format: compact },
      ]
    },
  },
  watch: {
    displayPoints: {
      immediate: true,
      deep: true,
      handler(points) {
        if (points.some(point => point.label === this.selectedLabel)) return
        this.selectedLabel = strongestByMagnitude(points, [point => point.call, point => point.put])?.label ?? ''
      },
    },
  },
  methods: {
    compact,
    resetZoom() {
      this.$refs.chart?.chart?.resetZoom?.()
    },
    snapshot() {
      downloadChartSnapshot(this.$refs.chart?.chart, this.downloadName)
    },
    selectFromChart(_event, elements) {
      const index = elements?.[0]?.index
      const point = this.displayPoints[index]
      if (!Number.isInteger(index) || !point) return
      this.selectedLabel = point.label
      if (numeric(point.call) != null || numeric(point.put) != null) this.$emit('reading-inspected')
    },
    selectFromControl(label) {
      const point = this.displayPoints.find(item => item.label === label)
      if (!point) return
      this.selectedLabel = label
      if (numeric(point.call) != null || numeric(point.put) != null) this.$emit('reading-inspected')
    },
  },
}
</script>

<template>
  <EodStrikeChartFrame
    id="eod-oi-change"
    v-model:focus-activity="focusActivity"
    v-model:auto-bucket="autoBucket"
    :selected-value="selectedLabel"
    @update:selected-value="selectFromControl"
    title="Open interest change by strike"
    :subtitle="basisSubtitle"
    help-title="How to read open interest change by strike"
    :symbol="symbol"
    :timeframe="timeframe"
    :snapshot-date="snapshotDate"
    :comparison-basis="comparisonBasis"
    :comparison-date="comparisonDate"
    :comparison-gap-trading-days="comparisonGapTradingDays"
    :comparison-is-stale="comparisonIsStale"
    :raw-count="rawRows.length"
    :valid-count="sortedData.length"
    :display-count="displayPoints.length"
    :has-chart-data="hasChartData"
    :selected-options="selectedOptions"
    :empty-title="emptyTitle"
    :empty-message="emptyMessage"
    details-label="Current-strike-set open interest fields"
    @reset-zoom="resetZoom"
    @download="snapshot"
  >
    <template #help>
      <div class="gex-stack">
        <p><strong>Open interest change</strong> compares contracts at each strike with the selected comparison date. Positive values added open interest; negative values removed it.</p>
        <p>Green identifies calls and red identifies puts. Bar direction shows whether contracts increased or decreased; color identifies the option side.</p>
        <p><strong>Focus on activity</strong> narrows the visible band without deleting rows. <strong>Group dense strikes</strong> sums nearby changes while the complete daily, weekly, and percentage fields remain in the collapsed table.</p>
        <p>If a weekly fallback is active, the panel labels it and shows its comparison date. Do not read a weekly change as a one-session move.</p>
        <p><strong>Scope:</strong> the API compares strikes in the current data set. Strikes that existed only in the earlier data set are not returned, so removals at those prior-only strikes are outside this view.</p>
        <p>When no dated comparison exists, returned daily and weekly fields stay available in the collapsed table but are not plotted as a valid change.</p>
      </div>
    </template>

    <template #metrics>
      <div class="gex-grid strike-metrics">
        <UiMetric
          label="Net OI change"
          :value="compact(totalChange)"
          :tone="totalChange == null || totalChange === 0 ? 'data' : totalChange > 0 ? 'positive' : 'negative'"
          prominence="primary"
          :context="totalChangeContext"
        />
        <UiMetric
          label="Call OI change"
          :value="compact(totalCall)"
          tone="positive"
          :context="`${callAvailableCount} of ${sortedData.length} current numeric strikes`"
        />
        <UiMetric
          label="Put OI change"
          :value="compact(totalPut)"
          tone="negative"
          :context="`${putAvailableCount} of ${sortedData.length} current numeric strikes`"
        />
      </div>
    </template>

    <template #selection>
      <template v-if="selectedPoint">
        <strong>{{ selectedPoint.sourceCount > 1 ? 'Strike range' : 'Strike' }} {{ selectedPoint.label }}</strong>
        <span>
          · Call {{ compact(selectedPoint.call) }}
          · Put {{ compact(selectedPoint.put) }}
          <template v-if="selectedPoint.sourceCount > 1"> · {{ selectedPoint.sourceCount }} strikes</template>
        </span>
      </template>
      <span v-else>No strike selected</span>
    </template>

    <template #chart>
      <div :class="['w-full', heightClass]">
        <Bar
          ref="chart"
          :data="chartData"
          :options="chartOptions"
          role="img"
          :aria-label="'Open interest change by strike; ' + displayPoints.length + ' displayed points. Use Inspect strike for individual values.'"
        />
      </div>
    </template>

    <template #details>
      <UiDataTable
        caption="Open interest comparison fields for the current raw strike set"
        :rows="tableRows"
        :columns="tableColumns"
        row-key="__row_key"
      />
    </template>
  </EodStrikeChartFrame>
</template>

<style scoped>
.strike-metrics {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

@container (max-width: 620px) {
  .strike-metrics {
    grid-template-columns: 1fr;
  }
}
</style>
