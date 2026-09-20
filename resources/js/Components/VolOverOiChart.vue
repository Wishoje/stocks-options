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
  readableLinearCeiling,
  ratioDetails,
  ratioSourceLabel,
  returnedFieldColumns,
  volOiValue,
} from './IntradayStrikes/intradayStrikeUtils.js'
import {
  chartSnapshotName,
  downloadChartSnapshot,
  focusActivityBand,
  strongestByMagnitude,
} from './EodStrikes/strikeChartUtils.js'
import UiDataTable from './UI/UiDataTable.vue'
import UiMetric from './UI/UiMetric.vue'
import { compact, numeric } from './UI/numbers.js'

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip, Legend, zoomPlugin)

export default {
  name: 'VolOverOiChart',
  components: { Bar, IntradayStrikeChartFrame, UiDataTable, UiMetric },
  emits: ['reading-inspected'],
  props: {
    strikeData: { type: Array, default: () => [] },
    snapshotName: { type: String, default: 'flow-vol-oi' },
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
      return focusActivityBand(this.sortedData, [volOiValue], {
        relativeThreshold: 0.05,
        absoluteThreshold: 0.1,
        padding: this.PADDING_STRIKES,
      })
    },
    displayPoints() {
      return buildRatioStrikePoints(
        this.focusActivity ? this.focusedData : this.sortedData,
        'vol_oi',
        { bucket: this.autoBucket, maxBars: this.MAX_BARS },
      )
    },
    hasChartData() {
      return this.displayPoints.some(point => numeric(point.value) != null)
    },
    aggregate() {
      return aggregateRatio(this.sortedData, 'vol_oi')
    },
    ratioAvailableCount() {
      return this.sortedData.filter(row => volOiValue(row) != null).length
    },
    aggregateContext() {
      if (!this.sortedData.length) return 'No numeric strikes were returned'
      if (!this.aggregate.coverageComplete) {
        return `${this.aggregate.completeCount} of ${this.sortedData.length} strikes include complete volume and EOD OI components`
      }
      if (this.aggregate.denominator === 0) return 'Total EOD open interest is zero, so the ratio is unavailable'
      return 'Total cumulative session volume divided by total EOD open interest'
    },
    topReading() {
      return this.sortedData.reduce((top, row) => {
        const value = volOiValue(row)
        return value != null && (!top || value > top.value)
          ? { strike: row.__strike, value }
          : top
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
      if (this.snapshotName !== 'flow-vol-oi') return chartSnapshotName(this.snapshotName)
      return chartSnapshotName(this.snapshotName, this.symbol, this.snapshotAsOf)
    },
    chartCeiling() {
      return readableLinearCeiling(this.displayPoints.map(point => point.value), { minimum: 1 })
    },
    clippedCount() {
      return this.displayPoints.filter(point => numeric(point.value) != null && point.value > this.chartCeiling).length
    },
    chartData() {
      return {
        labels: this.displayPoints.map(point => point.label),
        datasets: [{
          label: 'Volume / OI ratio',
          data: this.displayPoints.map(point => point.value),
          backgroundColor: 'rgba(90,184,255,0.88)',
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
              label: context => `Volume / OI: ${formatRatio(context.parsed.y)}`,
              afterBody: items => {
                const point = this.displayPoints[items[0]?.dataIndex]
                if (!point) return ''
                const grouped = point.sourceCount > 1 ? `${point.sourceCount} raw strikes grouped` : ratioSourceLabel(point.ratioSource)
                if (point.numerator == null || point.denominator == null) return grouped
                return [`Session volume: ${compact(point.numerator)}`, `EOD open interest: ${compact(point.denominator)}`, grouped]
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
            title: { display: true, text: 'Volume / open interest ratio (x)', color: '#aeb7c2', font: { size: 11 } },
            grid: { color: 'rgba(49,62,76,0.62)' },
            ticks: { color: '#aeb7c2', callback: value => `${Number(value).toFixed(2)}x` },
          },
        },
      }
    },
    tableRows() {
      return this.rawRows.map(row => {
        const details = ratioDetails(row, 'vol_oi')
        return {
          ...row,
          __display_ratio: details.value,
          __ratio_source: ratioSourceLabel(details.source),
          __ratio_numerator: details.numerator,
          __ratio_denominator: details.denominator,
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
        'oi_call_eod',
        'oi_put_eod',
        'vol_oi',
      ], {
        vol_oi: value => formatRatio(value, 4),
      })
      return [
        ...returned,
        { key: '__display_ratio', label: 'Displayed ratio', numeric: true, sortable: true, format: value => formatRatio(value, 4) },
        { key: '__ratio_source', label: 'Displayed ratio source', sortable: true },
        { key: '__ratio_numerator', label: 'Ratio session volume', numeric: true, sortable: true },
        { key: '__ratio_denominator', label: 'Ratio EOD OI', numeric: true, sortable: true },
      ]
    },
  },
  watch: {
    displayPoints: {
      immediate: true,
      deep: true,
      handler(points) {
        if (points.some(point => point.label === this.selectedLabel && numeric(point.value) != null)) return
        this.selectedLabel = strongestByMagnitude(points, [point => point.value])?.label ?? ''
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
    id="intraday-vol-oi"
    v-model:focus-activity="focusActivity"
    v-model:auto-bucket="autoBucket"
    :selected-value="selectedLabel"
    @update:selected-value="selectFromControl"
    title="Volume / open interest by strike"
    subtitle="Intraday contract volume relative to the latest EOD open-interest denominator"
    help-title="How to read volume / open interest by strike"
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
    empty-title="Volume / OI unavailable"
    empty-message="No strike has a usable returned ratio or a complete positive EOD open-interest denominator. All returned fields remain below."
    details-label="Complete returned Vol/OI inputs"
    @reset-zoom="resetZoom"
    @download="snapshot"
  >
    <template #help>
      <div class="gex-stack">
        <p><strong>Volume / OI</strong> divides cumulative current-session call plus put volume by the latest EOD call plus put open interest at the same strike.</p>
        <p>The value is a ratio, not a percentage. For example, 0.50x means current volume equals half of the EOD open-interest denominator.</p>
        <p>A returned API ratio is shown as supplied. If that field is absent, the panel derives it only when every volume and EOD OI component is present and the denominator is above zero. A real zero remains zero; a zero denominator remains unavailable.</p>
        <p><strong>Focus on activity</strong> changes the visible strike band. <strong>Group dense strikes</strong> recomputes the ratio from summed volume and OI components; it does not average strike ratios.</p>
        <p>The endpoint aggregates its included expirations by strike. The collapsed table retains every row and every returned field, including repriced GEX fields that are not plotted here. The returned net_gex_delta field currently uses a zero baseline and is not presented as a time change.</p>
      </div>
    </template>

    <template #metrics>
      <div class="gex-grid intraday-strike-metrics">
        <UiMetric
          label="Aggregate Vol/OI"
          :value="formatRatio(aggregate.value)"
          tone="data"
          prominence="primary"
          :context="aggregateContext"
        />
        <UiMetric
          label="Highest strike ratio"
          :value="formatRatio(topReading?.value)"
          tone="positive"
          :context="topReading ? `Strike ${topReading.strike}` : 'No usable strike ratio'"
        />
        <UiMetric
          label="Ratio coverage"
          :value="`${ratioAvailableCount}/${sortedData.length}`"
          tone="neutral"
          :context="`${aggregate.zeroDenominatorCount} complete strikes have zero EOD OI`"
        />
      </div>
    </template>

    <template v-if="clippedCount" #notice>
      <strong>Readable scale:</strong>
      {{ clippedCount }} extreme ratio{{ clippedCount === 1 ? '' : 's' }} extend above the {{ formatRatio(chartCeiling) }} chart ceiling. Inspect a strike or open the complete table for the exact returned ratio and denominator.
    </template>

    <template #selection>
      <template v-if="selectedPoint">
        <strong>{{ selectedPoint.sourceCount > 1 ? 'Strike range' : 'Strike' }} {{ selectedPoint.label }}</strong>
        <span>
          / {{ formatRatio(selectedPoint.value) }}
          / {{ ratioSourceLabel(selectedPoint.ratioSource) }}
          <template v-if="selectedPoint.numerator != null"> / Volume {{ compact(selectedPoint.numerator) }}</template>
          <template v-if="selectedPoint.denominator != null"> / EOD OI {{ compact(selectedPoint.denominator) }}</template>
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
          :aria-label="`Volume to open interest ratio by strike; ${displayPoints.length} displayed points. Use Inspect strike for values.`"
        />
      </div>
    </template>

    <template #details>
      <UiDataTable
        caption="Every field and row returned for the intraday strike snapshot, plus the displayed ratio audit"
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
