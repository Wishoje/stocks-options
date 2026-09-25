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
import IntradayStrikeChartFrame from './IntradayStrikes/IntradayStrikeChartFrame.vue'
import {
  aggregateRatio,
  buildRatioStrikePoints,
  chartIntradayRows,
  formatRatio,
  normalizedIntradayRows,
  pcrValue,
  readableLinearCeiling,
  ratioDetails,
  ratioSourceLabel,
  returnedFieldColumns,
} from './IntradayStrikes/intradayStrikeUtils.js'
import {
  chartSnapshotName,
  downloadChartSnapshot,
  focusActivityBand,
} from './EodStrikes/strikeChartUtils.js'
import UiDataTable from './UI/UiDataTable.vue'
import UiMetric from './UI/UiMetric.vue'
import { compact, numeric } from './UI/numbers.js'

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip, Legend, zoomPlugin)

const pcrDeviation = row => {
  const value = pcrValue(row)
  return value == null ? null : value - 1
}

export default {
  name: 'PcrByStrikeChart',
  components: { Bar, IntradayStrikeChartFrame, UiDataTable, UiMetric },
  emits: ['reading-inspected'],
  props: {
    strikeData: { type: Array, default: () => [] },
    snapshotName: { type: String, default: 'flow-pcr' },
    heightClass: { type: String, default: 'h-80 md:h-96 xl:h-[26rem]' },
    symbol: String,
    sourceLabel: String,
    snapshotAsOf: String,
    sourceTimestampStatus: String,
    marketOpen: { type: Boolean, default: null },
  },
  data() {
    return {
      autoBucket: true,
      focusActivity: true,
      selectedLabel: '',
      MAX_BARS: 100,
      PADDING_STRIKES: 8,
    }
  },
  computed: {
    rawRows() {
      return normalizedIntradayRows(this.strikeData)
    },
    sortedData() {
      return chartIntradayRows(this.strikeData)
    },
    focusedData() {
      return focusActivityBand(this.sortedData, [pcrDeviation], {
        relativeThreshold: 0.25,
        absoluteThreshold: 0.2,
        padding: this.PADDING_STRIKES,
      })
    },
    displayPoints() {
      return buildRatioStrikePoints(
        this.focusActivity ? this.focusedData : this.sortedData,
        'pcr',
        { bucket: this.autoBucket, maxBars: this.MAX_BARS },
      )
    },
    hasChartData() {
      return this.displayPoints.some(point => numeric(point.value) != null)
    },
    aggregate() {
      return aggregateRatio(this.sortedData, 'pcr')
    },
    ratioAvailableCount() {
      return this.sortedData.filter(row => pcrValue(row) != null).length
    },
    aggregateContext() {
      if (!this.sortedData.length) return 'No numeric strikes were returned'
      if (!this.aggregate.coverageComplete) {
        return `${this.aggregate.completeCount} of ${this.sortedData.length} strikes include both call and put session volume`
      }
      if (this.aggregate.denominator === 0) return 'Total call volume is zero, so put/call ratio is unavailable'
      return 'Total put session volume divided by total call session volume'
    },
    highestRatio() {
      return this.sortedData.reduce((top, row) => {
        const value = pcrValue(row)
        return value != null && (!top || value > top.value)
          ? { strike: row.__strike, value }
          : top
      }, null)
    },
    lowestRatio() {
      return this.sortedData.reduce((bottom, row) => {
        const value = pcrValue(row)
        return value != null && (!bottom || value < bottom.value)
          ? { strike: row.__strike, value }
          : bottom
      }, null)
    },
    selectedPoint() {
      return this.displayPoints.find(point => point.label === this.selectedLabel) ?? null
    },
    selectedOptions() {
      return this.displayPoints
        .filter(point => numeric(point.value) != null)
        .map(point => ({
          value: point.label,
          label: (point.sourceCount > 1 ? 'Strikes ' : 'Strike ') + point.label,
        }))
    },
    downloadName() {
      if (this.snapshotName !== 'flow-pcr') return chartSnapshotName(this.snapshotName)
      return chartSnapshotName(this.snapshotName, this.symbol, this.snapshotAsOf)
    },
    chartCeiling() {
      return readableLinearCeiling(this.displayPoints.map(point => point.value), { minimum: 3 })
    },
    clippedCount() {
      return this.displayPoints.filter(point => numeric(point.value) != null && point.value > this.chartCeiling).length
    },
    chartData() {
      return {
        labels: this.displayPoints.map(point => point.label),
        datasets: [{
          label: 'Put / call volume ratio',
          data: this.displayPoints.map(point => point.value),
          backgroundColor: this.displayPoints.map(point => {
            const value = numeric(point.value)
            if (value == null || value === 1) return 'rgba(90,184,255,0.82)'
            return value > 1 ? 'rgba(240,149,137,0.9)' : 'rgba(115,214,177,0.9)'
          }),
          borderColor: this.displayPoints.map(point => point.label === this.selectedLabel ? '#d9e6ff' : 'transparent'),
          borderWidth: this.displayPoints.map(point => point.label === this.selectedLabel ? 2 : 0),
          borderRadius: 4,
          barPercentage: 0.84,
          categoryPercentage: 0.9,
        }],
      }
    },
    chartOptions() {
      return {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        normalized: true,
        interaction: { mode: 'index', intersect: false },
        onClick: this.selectFromChart,
        plugins: {
          legend: { display: false },
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
                return point?.sourceCount > 1 ? `Strike range ${point.label}` : `Strike ${point?.label ?? items[0]?.label}`
              },
              label: context => `Put / call ratio: ${formatRatio(context.parsed.y)}`,
              afterBody: items => {
                const point = this.displayPoints[items[0]?.dataIndex]
                if (!point) return ''
                const grouped = point.sourceCount > 1 ? `${point.sourceCount} raw strikes grouped` : ratioSourceLabel(point.ratioSource)
                if (point.numerator == null || point.denominator == null) return grouped
                return [`Put volume: ${compact(point.numerator)}`, `Call volume: ${compact(point.denominator)}`, grouped]
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
            min: 0,
            max: this.chartCeiling,
            title: { display: true, text: 'Put / call volume ratio (x)', color: '#aeb7c2', font: { size: 11 } },
            grid: {
              color: context => Number(context.tick?.value) === 1 ? 'rgba(174,183,194,0.86)' : 'rgba(49,62,76,0.62)',
              lineWidth: context => Number(context.tick?.value) === 1 ? 2 : 1,
            },
            ticks: { color: '#aeb7c2', callback: value => `${Number(value).toFixed(2)}x` },
          },
        },
      }
    },
    tableRows() {
      return this.rawRows.map(row => {
        const details = ratioDetails(row, 'pcr')
        return {
          ...row,
          __display_ratio: details.value,
          __ratio_source: ratioSourceLabel(details.source),
          __put_volume: details.numerator,
          __call_denominator: details.denominator,
        }
      })
    },
    tableColumns() {
      const returned = returnedFieldColumns(this.rawRows, [
        'strike',
        'call_vol',
        'call_vol_delta',
        'call_volume_delta',
        'put_vol',
        'put_vol_delta',
        'put_volume_delta',
        'pcr',
      ], {
        pcr: value => formatRatio(value, 4),
      })
      return [
        ...returned,
        { key: '__display_ratio', label: 'Displayed ratio', numeric: true, sortable: true, format: value => formatRatio(value, 4) },
        { key: '__ratio_source', label: 'Displayed ratio source', sortable: true },
        { key: '__put_volume', label: 'Ratio put volume', numeric: true, sortable: true },
        { key: '__call_denominator', label: 'Ratio call denominator', numeric: true, sortable: true },
      ]
    },
  },
  watch: {
    displayPoints: {
      immediate: true,
      deep: true,
      handler(points) {
        if (points.some(point => point.label === this.selectedLabel && numeric(point.value) != null)) return
        const available = points.filter(point => numeric(point.value) != null)
        this.selectedLabel = available.reduce((strongest, point) => {
          const deviation = Math.abs(point.value - 1)
          return !strongest || deviation > strongest.deviation ? { label: point.label, deviation } : strongest
        }, null)?.label ?? ''
      },
    },
  },
  methods: {
    compact,
    formatRatio,
    ratioSourceLabel,
    resetZoom() {
      this.$refs.chart?.chart?.resetZoom?.()
    },
    snapshot() {
      downloadChartSnapshot(this.$refs.chart?.chart, this.downloadName)
    },
    selectFromChart(_event, elements) {
      const index = elements?.[0]?.index
      const point = this.displayPoints[index]
      if (!Number.isInteger(index) || !point || numeric(point.value) == null) return
      this.selectedLabel = point.label
      this.$emit('reading-inspected')
    },
    selectFromControl(label) {
      const point = this.displayPoints.find(item => item.label === label)
      if (!point || numeric(point.value) == null) return
      this.selectedLabel = label
      this.$emit('reading-inspected')
    },
  },
}
</script>

<template>
  <IntradayStrikeChartFrame
    id="intraday-pcr"
    v-model:focus-activity="focusActivity"
    v-model:auto-bucket="autoBucket"
    :selected-value="selectedLabel"
    @update:selected-value="selectFromControl"
    title="Put / call volume ratio by strike"
    subtitle="Cumulative session put volume divided by cumulative session call volume"
    help-title="How to read put / call volume ratio by strike"
    :symbol="symbol"
    :source-label="sourceLabel"
    :snapshot-as-of="snapshotAsOf"
    :source-timestamp-status="sourceTimestampStatus"
    :market-open="marketOpen"
    :raw-count="rawRows.length"
    :valid-count="sortedData.length"
    :display-count="displayPoints.length"
    :has-chart-data="hasChartData"
    :selected-options="selectedOptions"
    empty-title="Put / call ratio unavailable"
    empty-message="No strike has a usable returned ratio or a positive call-volume denominator. Put-only strikes remain visible in the complete data table."
    details-label="Complete returned put/call inputs"
    @reset-zoom="resetZoom"
    @download="snapshot"
  >
    <template #help>
      <div class="gex-stack">
        <p><strong>Put / call volume ratio</strong> divides cumulative session put contracts by cumulative session call contracts at each strike.</p>
        <p>The value is a ratio, not a percentage. A reading above 1.00x is put-heavy; below 1.00x is call-led. The stronger horizontal line marks 1.00x.</p>
        <p>A real 0.00x means calls traded and puts did not. If call volume is zero, the denominator is zero and the ratio remains unavailable, including for a put-only strike.</p>
        <p><strong>Focus on activity</strong> narrows around the largest deviations from 1.00x. <strong>Group dense strikes</strong> divides summed put volume by summed call volume; it does not average the individual ratios.</p>
        <p>The endpoint aggregates its included expirations by strike. Every row and returned field remains in the collapsed table, even when a ratio cannot be plotted. The returned net_gex_delta field currently uses a zero baseline and is not interpreted as a time change here.</p>
      </div>
    </template>

    <template #metrics>
      <div class="gex-grid intraday-strike-metrics">
        <UiMetric
          label="Aggregate put/call"
          :value="formatRatio(aggregate.value)"
          :tone="aggregate.value == null || aggregate.value === 1 ? 'data' : aggregate.value > 1 ? 'negative' : 'positive'"
          prominence="primary"
          :context="aggregateContext"
        />
        <UiMetric
          label="Highest put/call"
          :value="formatRatio(highestRatio?.value)"
          :tone="highestRatio?.value == null || highestRatio.value === 1 ? 'data' : highestRatio.value > 1 ? 'negative' : 'positive'"
          :context="highestRatio ? `Strike ${highestRatio.strike}` : 'No usable strike ratio'"
        />
        <UiMetric
          label="Lowest put/call"
          :value="formatRatio(lowestRatio?.value)"
          :tone="lowestRatio?.value == null || lowestRatio.value === 1 ? 'data' : lowestRatio.value > 1 ? 'negative' : 'positive'"
          :context="lowestRatio ? `Strike ${lowestRatio.strike} / ${ratioAvailableCount} of ${sortedData.length} ratios available` : 'No usable strike ratio'"
        />
      </div>
    </template>

    <template v-if="clippedCount" #notice>
      <strong>Readable scale:</strong>
      {{ clippedCount }} small-denominator ratio{{ clippedCount === 1 ? '' : 's' }} extend above the {{ formatRatio(chartCeiling) }} chart ceiling. Inspect a strike or open the complete table for the exact ratio and call-volume denominator.
    </template>

    <template #selection>
      <template v-if="selectedPoint">
        <strong>{{ selectedPoint.sourceCount > 1 ? 'Strike range' : 'Strike' }} {{ selectedPoint.label }}</strong>
        <span>
          / {{ formatRatio(selectedPoint.value) }}
          / {{ ratioSourceLabel(selectedPoint.ratioSource) }}
          <template v-if="selectedPoint.numerator != null"> / Put {{ compact(selectedPoint.numerator) }}</template>
          <template v-if="selectedPoint.denominator != null"> / Call {{ compact(selectedPoint.denominator) }}</template>
        </span>
      </template>
      <span v-else>No usable strike selected</span>
    </template>

    <template #chart>
      <div :class="['w-full', heightClass]">
        <Bar
          ref="chart"
          :data="chartData"
          :options="chartOptions"
          role="img"
          :aria-label="`Put to call volume ratio by strike; ${displayPoints.length} displayed points. Use Inspect strike for values.`"
        />
      </div>
    </template>

    <template #details>
      <UiDataTable
        caption="Every field and row returned for the intraday strike data set, plus the displayed ratio audit"
        :rows="tableRows"
        :columns="tableColumns"
        row-key="__row_key"
      />
    </template>
  </IntradayStrikeChartFrame>
</template>

<style scoped>
.intraday-strike-metrics {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

@media (max-width: 680px) {
  .intraday-strike-metrics {
    grid-template-columns: 1fr;
  }
}
</style>
