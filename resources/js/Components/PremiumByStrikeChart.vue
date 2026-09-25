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
  callPremium,
  chartIntradayRows,
  formatCurrency,
  normalizedIntradayRows,
  putPremium,
  returnedFieldColumns,
} from './IntradayStrikes/intradayStrikeUtils.js'
import {
  buildStrikePoints,
  chartSnapshotName,
  countAvailable,
  downloadChartSnapshot,
  focusActivityBand,
  strongestByMagnitude,
  sumAvailable,
} from './EodStrikes/strikeChartUtils.js'
import UiDataTable from './UI/UiDataTable.vue'
import UiMetric from './UI/UiMetric.vue'
import { numeric } from './UI/numbers.js'

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip, Legend, zoomPlugin)

export default {
  name: 'PremiumByStrikeChart',
  components: { Bar, IntradayStrikeChartFrame, UiDataTable, UiMetric },
  emits: ['reading-inspected'],
  props: {
    strikeData: { type: Array, default: () => [] },
    snapshotName: { type: String, default: 'flow-premium' },
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
      MAX_BARS: 80,
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
      return focusActivityBand(this.sortedData, [callPremium, putPremium], {
        relativeThreshold: 0.05,
        absoluteThreshold: 10_000,
        padding: this.PADDING_STRIKES,
      })
    },
    displayPoints() {
      return buildStrikePoints(
        this.focusActivity ? this.focusedData : this.sortedData,
        { call: callPremium, put: putPremium },
        { bucket: this.autoBucket, maxBars: this.MAX_BARS },
      )
    },
    hasChartData() {
      return this.displayPoints.some(point => numeric(point.call) != null || numeric(point.put) != null)
    },
    totalCall() {
      return sumAvailable(this.sortedData, callPremium)
    },
    totalPut() {
      return sumAvailable(this.sortedData, putPremium)
    },
    callAvailableCount() {
      return countAvailable(this.sortedData, callPremium)
    },
    putAvailableCount() {
      return countAvailable(this.sortedData, putPremium)
    },
    coverageComplete() {
      return this.sortedData.length > 0
        && this.callAvailableCount === this.sortedData.length
        && this.putAvailableCount === this.sortedData.length
    },
    totalPremium() {
      return this.coverageComplete ? this.totalCall + this.totalPut : null
    },
    totalContext() {
      if (!this.sortedData.length) return 'No numeric strikes were returned'
      if (!this.coverageComplete) {
        return `Call readings ${this.callAvailableCount} / Put readings ${this.putAvailableCount}`
      }
      return 'Combined call and put premium estimate'
    },
    largestStrike() {
      return this.sortedData.reduce((largest, row) => {
        const call = callPremium(row)
        const put = putPremium(row)
        if (call == null || put == null) return largest
        const value = call + put
        return !largest || value > largest.value
          ? { strike: row.__strike, value, call, put }
          : largest
      }, null)
    },
    selectedPoint() {
      return this.displayPoints.find(point => point.label === this.selectedLabel) ?? null
    },
    selectedOptions() {
      return this.displayPoints
        .filter(point => numeric(point.call) != null || numeric(point.put) != null)
        .map(point => ({
          value: point.label,
          label: (point.sourceCount > 1 ? 'Strikes ' : 'Strike ') + point.label,
        }))
    },
    downloadName() {
      if (this.snapshotName !== 'flow-premium') return chartSnapshotName(this.snapshotName)
      return chartSnapshotName(this.snapshotName, this.symbol, this.snapshotAsOf)
    },
    chartData() {
      const borderColor = this.displayPoints.map(point => point.label === this.selectedLabel ? '#d9e6ff' : 'transparent')
      const borderWidth = this.displayPoints.map(point => point.label === this.selectedLabel ? 2 : 0)
      return {
        labels: this.displayPoints.map(point => point.label),
        datasets: [
          {
            label: 'Call premium estimate',
            data: this.displayPoints.map(point => point.call),
            stack: 'premium',
            backgroundColor: 'rgba(115,214,177,0.9)',
            borderColor,
            borderWidth,
            borderRadius: 4,
            barPercentage: 0.84,
            categoryPercentage: 0.9,
          },
          {
            label: 'Put premium estimate',
            data: this.displayPoints.map(point => point.put),
            stack: 'premium',
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
      const positiveMaximum = this.displayPoints.reduce((maximum, point) => {
        const positive = Math.max(0, numeric(point.call) ?? 0) + Math.max(0, numeric(point.put) ?? 0)
        return Math.max(maximum, positive)
      }, 0)
      const negativeMinimum = this.displayPoints.reduce((minimum, point) => {
        const negative = Math.min(0, numeric(point.call) ?? 0) + Math.min(0, numeric(point.put) ?? 0)
        return Math.min(minimum, negative)
      }, 0)
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
                return point?.sourceCount > 1 ? `Strike range ${point.label}` : `Strike ${point?.label ?? items[0]?.label}`
              },
              label: context => `${context.dataset.label}: ${formatCurrency(context.parsed.y)}`,
              afterBody: items => {
                const point = this.displayPoints[items[0]?.dataIndex]
                return point?.sourceCount > 1 ? `${point.sourceCount} raw strikes summed` : ''
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
            title: { display: true, text: this.autoBucket ? 'Strike range' : 'Strike', color: '#aeb7c2', font: { size: 11 } },
            grid: { display: false },
            ticks: { autoSkip: true, maxTicksLimit: 16, color: '#aeb7c2', maxRotation: 0 },
          },
          y: {
            stacked: true,
            min: negativeMinimum < 0 ? negativeMinimum * 1.1 : 0,
            max: positiveMaximum ? positiveMaximum * 1.1 : undefined,
            title: { display: true, text: 'Session premium notional estimate ($)', color: '#aeb7c2', font: { size: 11 } },
            grid: {
              color: context => Number(context.tick?.value) === 0 ? 'rgba(174,183,194,0.82)' : 'rgba(49,62,76,0.62)',
              lineWidth: context => Number(context.tick?.value) === 0 ? 2 : 1,
            },
            ticks: { color: '#aeb7c2', callback: value => formatCurrency(value, { compact: true }) },
          },
        },
      }
    },
    tableColumns() {
      return returnedFieldColumns(this.rawRows, [
        'strike',
        'call_prem',
        'premium_call',
        'put_prem',
        'premium_put',
        'call_vol',
        'call_vol_delta',
        'put_vol',
        'put_vol_delta',
      ], {
        call_prem: formatCurrency,
        premium_call: formatCurrency,
        put_prem: formatCurrency,
        premium_put: formatCurrency,
      })
    },
  },
  watch: {
    displayPoints: {
      immediate: true,
      deep: true,
      handler(points) {
        if (points.some(point => point.label === this.selectedLabel && (numeric(point.call) != null || numeric(point.put) != null))) return
        this.selectedLabel = strongestByMagnitude(points, [point => point.call, point => point.put])?.label ?? ''
      },
    },
  },
  methods: {
    formatCurrency,
    resetZoom() {
      this.$refs.chart?.chart?.resetZoom?.()
    },
    snapshot() {
      downloadChartSnapshot(this.$refs.chart?.chart, this.downloadName)
    },
    selectFromChart(_event, elements) {
      const index = elements?.[0]?.index
      const point = this.displayPoints[index]
      if (!Number.isInteger(index) || !point || (numeric(point.call) == null && numeric(point.put) == null)) return
      this.selectedLabel = point.label
      this.$emit('reading-inspected')
    },
    selectFromControl(label) {
      const point = this.displayPoints.find(item => item.label === label)
      if (!point || (numeric(point.call) == null && numeric(point.put) == null)) return
      this.selectedLabel = label
      this.$emit('reading-inspected')
    },
  },
}
</script>

<template>
  <IntradayStrikeChartFrame
    id="intraday-premium"
    v-model:focus-activity="focusActivity"
    v-model:auto-bucket="autoBucket"
    :selected-value="selectedLabel"
    @update:selected-value="selectFromControl"
    title="Session premium estimate by strike"
    subtitle="Cumulative call and put premium notional estimated for the current intraday session"
    help-title="How to read session premium estimate by strike"
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
    empty-title="Premium estimate unavailable"
    empty-message="No usable call or put premium values were returned. All returned strike fields remain below."
    details-label="Complete returned premium inputs"
    @reset-zoom="resetZoom"
    @download="snapshot"
  >
    <template #help>
      <div class="gex-stack">
        <p><strong>Session premium estimate</strong> is the cumulative option premium notional attributed to calls and puts at each strike in the current intraday data. Values are US dollars.</p>
        <p>Green identifies calls and red identifies puts. The stacked height is their combined estimate. This is not profit and loss, trade direction, or a claim that every contract was bought.</p>
        <p>Premium estimates help compare activity across strikes. They are price-based estimates, not confirmed cash flows.</p>
        <p><strong>Focus on activity</strong> narrows the visible band without deleting data. <strong>Group dense strikes</strong> sums nearby call and put dollar values. Every original row and field remains in the collapsed table.</p>
        <p>Each bar combines the selected expirations at that strike. Inspect a bar to compare its call and put premium.</p>
      </div>
    </template>

    <template #metrics>
      <div class="gex-grid intraday-strike-metrics">
        <UiMetric
          label="Total premium estimate"
          :value="formatCurrency(totalPremium, { compact: true })"
          tone="data"
          prominence="primary"
          :context="totalContext"
        />
        <UiMetric
          label="Call premium estimate"
          :value="formatCurrency(totalCall, { compact: true })"
          tone="positive"
          :context="`${callAvailableCount} of ${sortedData.length} numeric strikes`"
        />
        <UiMetric
          label="Put premium estimate"
          :value="formatCurrency(totalPut, { compact: true })"
          tone="negative"
          :context="largestStrike ? `Largest combined estimate at strike ${largestStrike.strike}` : `${putAvailableCount} of ${sortedData.length} numeric strikes`"
        />
      </div>
    </template>

    <template #selection>
      <template v-if="selectedPoint">
        <strong>{{ selectedPoint.sourceCount > 1 ? 'Strike range' : 'Strike' }} {{ selectedPoint.label }}</strong>
        <span>
          / Call {{ formatCurrency(selectedPoint.call, { compact: true }) }}
          / Put {{ formatCurrency(selectedPoint.put, { compact: true }) }}
          <template v-if="selectedPoint.sourceCount > 1"> / {{ selectedPoint.sourceCount }} strikes</template>
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
          :aria-label="`Session premium estimate by strike in US dollars; ${displayPoints.length} displayed points. Use Inspect strike for values.`"
        />
      </div>
    </template>

    <template #details>
      <UiDataTable
        caption="Every field and row returned for the intraday strike data set"
        :rows="rawRows"
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
