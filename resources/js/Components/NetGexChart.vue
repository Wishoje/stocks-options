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

const netValue = row => numeric(row?.net_gex ?? row?.netGex)
const callValue = row => numeric(row?.call_gex ?? row?.callGex)
const putValue = row => numeric(row?.put_gex ?? row?.putGex)
const positiveNetValue = row => {
  const value = netValue(row)
  return value != null && value > 0 ? value : null
}
const negativeNetValue = row => {
  const value = netValue(row)
  return value != null && value < 0 ? value : null
}

export default {
  name: 'NetGexChart',
  components: { Bar, EodStrikeChartFrame, UiDataTable, UiMetric },
  emits: ['reading-inspected'],
  props: {
    strikeData: { type: Array, default: () => [] },
    snapshotName: { type: String, default: 'net-gex' },
    heightClass: { type: String, default: 'h-80 md:h-96 xl:h-[26rem]' },
    eod: { type: Boolean, default: false },
    symbol: String,
    timeframe: String,
    snapshotDate: String,
  },
  data() {
    return {
      autoBucket: false,
      focusActivity: true,
      splitView: false,
      selectedLabel: '',
      MAX_BARS: 100,
      PADDING_STRIKES: 8,
    }
  },
  computed: {
    rawRows() {
      return normalizedStrikeRows(this.strikeData)
    },
    sortedData() {
      if (!this.eod) {
        return [...(this.strikeData || [])]
          .filter(row => row && row.strike != null)
          .sort((left, right) => Number(left.strike) - Number(right.strike))
      }
      return chartStrikeRows(this.strikeData)
    },
    focusedData() {
      if (!this.eod) {
        const rows = this.sortedData
        if (!rows.length) return []
        const values = rows.map(row => Number(row.net_gex ?? row.netGex ?? 0))
        const callValues = rows.map(row => Math.abs(Number(row.call_gex ?? row.callGex ?? 0)))
        const putValues = rows.map(row => Math.abs(Number(row.put_gex ?? row.putGex ?? 0)))
        const maximum = Math.max(...values.map(value => Math.abs(value)))
        const maximumCall = Math.max(...callValues)
        const maximumPut = Math.max(...putValues)
        if (
          (!isFinite(maximum) || maximum === 0)
          && (!isFinite(maximumCall) || maximumCall === 0)
          && (!isFinite(maximumPut) || maximumPut === 0)
        ) return rows

        const maximumPositive = Math.max(...values.map(value => value > 0 ? value : 0))
        const maximumNegative = Math.max(...values.map(value => value < 0 ? Math.abs(value) : 0))
        const positiveThreshold = maximumPositive > 0 ? Math.max(maximumPositive * 0.02, 1_000) : Infinity
        const negativeThreshold = maximumNegative > 0 ? Math.max(maximumNegative * 0.02, 1_000) : Infinity
        const callThreshold = maximumCall > 0 ? Math.max(maximumCall * 0.02, 1_000) : Infinity
        const putThreshold = maximumPut > 0 ? Math.max(maximumPut * 0.02, 1_000) : Infinity
        let firstIndex = -1
        let lastIndex = -1

        rows.forEach((row, index) => {
          const value = Number(row.net_gex ?? row.netGex ?? 0)
          const call = Math.abs(Number(row.call_gex ?? row.callGex ?? 0))
          const put = Math.abs(Number(row.put_gex ?? row.putGex ?? 0))
          const active = (value > 0 && value >= positiveThreshold)
            || (value < 0 && Math.abs(value) >= negativeThreshold)
            || call >= callThreshold
            || put >= putThreshold
          if (!active) return
          if (firstIndex < 0) firstIndex = index
          lastIndex = index
        })

        if (firstIndex < 0 || lastIndex < 0) return rows
        return rows.slice(
          Math.max(0, firstIndex - this.PADDING_STRIKES),
          Math.min(rows.length, lastIndex + this.PADDING_STRIKES + 1),
        )
      }
      return focusActivityBand(this.sortedData, [positiveNetValue, negativeNetValue, callValue, putValue], {
        relativeThreshold: 0.02,
        absoluteThreshold: 1000,
        padding: this.PADDING_STRIKES,
      })
    },
    displayPoints() {
      const rows = this.focusActivity ? this.focusedData : this.sortedData
      if (!this.eod) {
        if (!rows.length) return []
        if (!this.autoBucket || rows.length <= this.MAX_BARS) {
          return rows.map(row => ({
            label: String(row.strike),
            value: Number(row.net_gex ?? row.netGex ?? 0),
            call: Number(row.call_gex ?? row.callGex ?? 0),
            put: Number(row.put_gex ?? row.putGex ?? 0),
          }))
        }

        const minimum = Number(rows[0].strike)
        const maximum = Number(rows.at(-1).strike)
        const bucketCount = Math.min(this.MAX_BARS, rows.length)
        const step = this.niceStep((maximum - minimum) / bucketCount || 1)
        const buckets = new Array(bucketCount).fill(null).map((_, index) => ({
          start: minimum + index * step,
          end: minimum + (index + 1) * step,
          value: 0,
          call: 0,
          put: 0,
        }))
        rows.forEach(row => {
          const index = Math.min(bucketCount - 1, Math.max(0, Math.floor((Number(row.strike) - minimum) / step)))
          buckets[index].value += Number(row.net_gex ?? row.netGex ?? 0)
          buckets[index].call += Number(row.call_gex ?? row.callGex ?? 0)
          buckets[index].put += Number(row.put_gex ?? row.putGex ?? 0)
        })
        return buckets
          .filter(bucket => bucket.value !== 0 || bucket.call !== 0 || bucket.put !== 0)
          .map(bucket => ({
            label: step >= 5
              ? `${Math.round(bucket.start)}-${Math.round(bucket.end)}`
              : `${bucket.start.toFixed(2)}-${bucket.end.toFixed(2)}`,
            value: bucket.value,
            call: bucket.call,
            put: bucket.put,
          }))
      }
      return buildStrikePoints(rows, {
        value: netValue,
        call: callValue,
        put: putValue,
      }, {
        bucket: this.autoBucket,
        maxBars: this.MAX_BARS,
      })
    },
    hasChartData() {
      if (this.splitView) {
        return this.displayPoints.some(point => point.call != null || point.put != null)
      }
      return this.displayPoints.some(point => point.value != null)
    },
    totalNet() {
      if (!this.sortedData.length || this.netAvailableCount !== this.sortedData.length) return null
      return sumAvailable(this.sortedData, netValue)
    },
    netAvailableCount() {
      return countAvailable(this.sortedData, netValue)
    },
    totalNetContext() {
      if (!this.sortedData.length) return 'No numeric strikes were returned'
      if (this.netAvailableCount !== this.sortedData.length) {
        return `${this.netAvailableCount} of ${this.sortedData.length} numeric strikes include net GEX`
      }
      return `All ${this.sortedData.length} numeric strikes included`
    },
    topPositive() {
      return this.sortedData.reduce((top, row) => {
        const value = netValue(row)
        return value != null && value > 0 && (!top || value > top.value)
          ? { strike: row.__strike, value }
          : top
      }, null)
    },
    topNegative() {
      return this.sortedData.reduce((top, row) => {
        const value = netValue(row)
        return value != null && value < 0 && (!top || value < top.value)
          ? { strike: row.__strike, value }
          : top
      }, null)
    },
    selectedPoint() {
      return this.displayPoints.find(point => point.label === this.selectedLabel) ?? null
    },
    selectedOptions() {
      if (!this.eod || !this.hasChartData) return []
      return this.displayPoints.map(point => ({
        value: point.label,
        label: (point.sourceCount > 1 ? 'Strikes ' : 'Strike ') + point.label,
      }))
    },
    downloadName() {
      if (this.snapshotName !== 'net-gex') return chartSnapshotName(this.snapshotName)
      return chartSnapshotName(this.snapshotName, this.symbol, this.timeframe, this.snapshotDate)
    },
    chartData() {
      if (!this.eod) {
        if (this.splitView) {
          return {
            labels: this.displayPoints.map(point => point.label),
            datasets: [
              {
                label: 'Call GEX',
                data: this.displayPoints.map(point => point.call),
                backgroundColor: 'rgba(52,211,153,0.9)',
                borderRadius: 3,
                barPercentage: 0.85,
                categoryPercentage: 0.9,
              },
              {
                label: 'Put GEX',
                data: this.displayPoints.map(point => -point.put),
                backgroundColor: 'rgba(248,113,113,0.85)',
                borderRadius: 3,
                barPercentage: 0.85,
                categoryPercentage: 0.9,
              },
            ],
          }
        }
        const values = this.displayPoints.map(point => point.value)
        return {
          labels: this.displayPoints.map(point => point.label),
          datasets: [{
            label: 'Net GEX',
            data: values,
            backgroundColor: values.map(value => value >= 0
              ? 'rgba(52,211,153,0.9)'
              : 'rgba(248,113,113,0.85)'),
            borderRadius: 3,
            barPercentage: 0.9,
            categoryPercentage: 0.9,
          }],
        }
      }
      const borderColor = this.displayPoints.map(point => point.label === this.selectedLabel ? '#9bb4ff' : 'transparent')
      const borderWidth = this.displayPoints.map(point => point.label === this.selectedLabel ? 2 : 0)
      if (this.splitView) {
        return {
          labels: this.displayPoints.map(point => point.label),
          datasets: [
            {
              label: 'Call GEX',
              data: this.displayPoints.map(point => point.call),
              backgroundColor: 'rgba(115,214,177,0.9)',
              borderColor,
              borderWidth,
              borderRadius: 4,
              barPercentage: 0.84,
              categoryPercentage: 0.9,
            },
            {
              label: 'Put GEX',
              data: this.displayPoints.map(point => point.put == null ? null : -Math.abs(point.put)),
              backgroundColor: 'rgba(240,149,137,0.9)',
              borderColor,
              borderWidth,
              borderRadius: 4,
              barPercentage: 0.84,
              categoryPercentage: 0.9,
            },
          ],
        }
      }
      return {
        labels: this.displayPoints.map(point => point.label),
        datasets: [{
          label: 'Net GEX',
          data: this.displayPoints.map(point => point.value),
          backgroundColor: this.displayPoints.map(point => {
            if (point.value == null) return 'transparent'
            return point.value >= 0 ? 'rgba(115,214,177,0.9)' : 'rgba(240,149,137,0.9)'
          }),
          borderColor,
          borderWidth,
          borderRadius: 4,
          barPercentage: 0.9,
          categoryPercentage: 0.9,
        }],
      }
    },
    chartOptions() {
      if (!this.eod) {
        let yMinimum
        let yMaximum
        if (this.splitView) {
          const maximumCall = this.displayPoints.reduce((maximum, point) => Math.max(maximum, point.call), 0)
          const maximumPut = this.displayPoints.reduce((maximum, point) => Math.max(maximum, point.put), 0)
          const maximum = Math.max(maximumCall, maximumPut)
          const padding = maximum ? maximum * 0.1 : 0
          yMinimum = maximum ? -(maximumPut + padding) : undefined
          yMaximum = maximum ? maximumCall + padding : undefined
        } else {
          const maximum = this.displayPoints.reduce((current, point) => Math.max(current, Math.abs(point.value)), 0)
          const padding = maximum ? maximum * 0.1 : 0
          yMinimum = maximum ? -(maximum + padding) : undefined
          yMaximum = maximum ? maximum + padding : undefined
        }
        return {
          responsive: true,
          maintainAspectRatio: false,
          animation: false,
          plugins: {
            legend: { display: this.splitView, labels: { color: '#e5e7eb' } },
            tooltip: {
              backgroundColor: 'rgba(15,23,42,0.95)',
              borderColor: 'rgba(148,163,184,0.6)',
              borderWidth: 1,
              padding: 10,
              callbacks: {
                title: items => `Strike ${items[0].label}`,
                label: context => {
                  const label = context.dataset.label || 'Net GEX'
                  const plotted = context.parsed.y
                  const display = this.splitView && label === 'Put GEX' ? Math.abs(plotted) : plotted
                  return `${label}: ${display > 0 ? '+' : ''}${this.formatNumber(display)}`
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
              title: { display: true, text: 'Strike (bucketed / focused)', color: '#9ca3af', font: { size: 11 } },
              grid: { display: false },
              ticks: { autoSkip: true, maxTicksLimit: 18, color: '#6b7280' },
            },
            y: {
              min: yMinimum,
              max: yMaximum,
              title: { display: true, text: this.splitView ? 'GEX (Call up / Put down)' : 'Net GEX', color: '#9ca3af', font: { size: 11 } },
              grid: { color: 'rgba(31,41,55,0.6)' },
              ticks: { color: '#6b7280', callback: value => this.formatNumber(value) },
            },
          },
        }
      }
      const plottedValues = this.chartData.datasets.flatMap(dataset => dataset.data).filter(value => numeric(value) != null)
      const minimum = plottedValues.length ? Math.min(0, ...plottedValues) : 0
      const maximum = plottedValues.length ? Math.max(0, ...plottedValues) : 0
      const span = Math.max(Math.abs(minimum), Math.abs(maximum))
      const padding = span ? span * 0.1 : 0
      return {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        normalized: true,
        interaction: { mode: 'index', intersect: false },
        onClick: this.selectFromChart,
        plugins: {
          legend: {
            display: this.splitView,
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
                const label = context.dataset.label || 'Net GEX'
                const plotted = numeric(context.parsed.y)
                if (plotted == null) return label + ': unavailable'
                const value = this.splitView && label === 'Put GEX' ? Math.abs(plotted) : plotted
                return label + ': ' + compact(value)
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
            min: span ? minimum - padding : undefined,
            max: span ? maximum + padding : undefined,
            title: { display: true, text: this.splitView ? 'GEX · calls above / puts below' : 'Net GEX', color: '#aeb7c2', font: { size: 11 } },
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
        net_display: netValue(row),
        call_display: callValue(row),
        put_display: putValue(row),
      }))
    },
    tableColumns() {
      return [
        { key: 'strike_display', label: 'Strike', numeric: true, sortable: true },
        { key: 'net_display', label: 'Net GEX', numeric: true, sortable: true, format: compact },
        { key: 'call_display', label: 'Call GEX', numeric: true, sortable: true, format: compact },
        { key: 'put_display', label: 'Put GEX', numeric: true, sortable: true, format: compact },
      ]
    },
  },
  watch: {
    displayPoints: {
      immediate: true,
      deep: true,
      handler(points) {
        if (!this.eod || points.some(point => point.label === this.selectedLabel)) return
        this.selectedLabel = strongestByMagnitude(points, [point => point.value, point => point.call, point => point.put])?.label ?? ''
      },
    },
  },
  methods: {
    compact,
    resetZoom() {
      this.$refs.chart?.chart?.resetZoom?.()
    },
    snapshot() {
      const chart = this.$refs.chart?.chart
      if (!this.eod) {
        if (!chart?.canvas) return
        const source = chart.canvas
        const output = document.createElement('canvas')
        output.width = source.width
        output.height = source.height
        const context = output.getContext('2d')
        context.fillStyle = 'rgb(12,17,27)'
        context.fillRect(0, 0, output.width, output.height)
        context.drawImage(source, 0, 0)
        context.save()
        context.font = `${Math.max(32, Math.round(output.width * 0.08))}px "Inter","Segoe UI",system-ui,sans-serif`
        context.fillStyle = 'rgba(255,255,255,0.14)'
        context.textAlign = 'center'
        context.textBaseline = 'top'
        context.fillText('GexOptions.com', output.width / 2, Math.max(12, output.height * 0.04))
        context.restore()
        const link = document.createElement('a')
        link.download = `${this.snapshotName || 'net-gex'}-${new Date().toISOString().slice(0, 10)}.png`
        output.toBlob(blob => {
          if (!blob) return
          const url = URL.createObjectURL(blob)
          link.href = url
          document.body.appendChild(link)
          link.click()
          link.remove()
          setTimeout(() => URL.revokeObjectURL(url), 1000)
        }, 'image/png')
        return
      }
      downloadChartSnapshot(chart, this.downloadName)
    },
    selectFromChart(_event, elements) {
      const index = elements?.[0]?.index
      const point = this.displayPoints[index]
      if (!Number.isInteger(index) || !point) return
      this.selectedLabel = point.label
      if ([point.value, point.call, point.put].some(value => numeric(value) != null)) this.$emit('reading-inspected')
    },
    selectFromControl(label) {
      const point = this.displayPoints.find(item => item.label === label)
      if (!point) return
      this.selectedLabel = label
      if ([point.value, point.call, point.put].some(value => numeric(value) != null)) this.$emit('reading-inspected')
    },
    niceStep(step) {
      const power = Math.pow(10, Math.floor(Math.log10(step)))
      for (const unit of [1, 2, 5, 10]) {
        const candidate = unit * power
        if (step <= candidate) return candidate
      }
      return 10 * power
    },
    formatNumber(value) {
      const number = Number(value)
      if (Math.abs(number) >= 1_000_000_000) return (number / 1_000_000_000).toFixed(1) + 'B'
      if (Math.abs(number) >= 1_000_000) return (number / 1_000_000).toFixed(1) + 'M'
      if (Math.abs(number) >= 1_000) return (number / 1_000).toFixed(1) + 'k'
      return number.toString()
    },
  },
}
</script>

<template>
  <EodStrikeChartFrame
    v-if="eod"
    id="eod-net-gex"
    v-model:focus-activity="focusActivity"
    v-model:auto-bucket="autoBucket"
    :selected-value="selectedLabel"
    @update:selected-value="selectFromControl"
    title="Net GEX by strike"
    subtitle="Dealer gamma exposure across selected expirations; dated for the selected expiry scope"
    help-title="How to read net GEX by strike"
    :symbol="symbol"
    :timeframe="timeframe"
    :snapshot-date="snapshotDate"
    :raw-count="rawRows.length"
    :valid-count="sortedData.length"
    :display-count="displayPoints.length"
    :has-chart-data="hasChartData"
    :selected-options="selectedOptions"
    empty-title="No net GEX readings"
    empty-message="No usable net, call, or put GEX values were returned for these strikes."
    details-label="All net GEX strike readings"
    @reset-zoom="resetZoom"
    @download="snapshot"
  >
    <template #help>
      <div class="gex-stack">
        <p><strong>Net GEX</strong> is call gamma exposure minus put gamma exposure at each strike. Positive values often align with hedging that dampens moves; negative values can align with hedging that amplifies moves.</p>
        <p><strong>Split call/put</strong> shows call magnitude above zero and put magnitude below zero so the two sides remain visually distinct. The raw put value is preserved in the selected detail and table.</p>
        <p><strong>Focus on activity</strong> narrows the visible strike band without deleting rows. <strong>Group dense strikes</strong> sums nearby values for rendering; every source strike stays available in the collapsed table.</p>
        <p>Compare the selected expiry range and date when reviewing levels.</p>
        <p>Large concentrations are reaction areas rather than price targets. Confirm them with current price action, expiration timing, and liquidity.</p>
      </div>
    </template>

    <template #metrics>
      <div class="gex-grid strike-metrics">
        <UiMetric
          label="Total net GEX"
          :value="compact(totalNet)"
          :tone="totalNet == null || totalNet === 0 ? 'data' : totalNet > 0 ? 'positive' : 'negative'"
          prominence="primary"
          :context="totalNetContext"
        />
        <UiMetric
          label="Largest positive"
          :value="compact(topPositive?.value)"
          tone="positive"
          :context="topPositive ? 'Strike ' + topPositive.strike : 'No positive net GEX reading'"
        />
        <UiMetric
          label="Largest negative"
          :value="compact(topNegative?.value)"
          tone="negative"
          :context="topNegative ? 'Strike ' + topNegative.strike : 'No negative net GEX reading'"
        />
      </div>
    </template>

    <template #controls>
      <label class="gex-check">
        <input v-model="splitView" type="checkbox" />
        <span>Split call / put</span>
      </label>
    </template>

    <template #selection>
      <template v-if="selectedPoint">
        <strong>{{ selectedPoint.sourceCount > 1 ? 'Strike range' : 'Strike' }} {{ selectedPoint.label }}</strong>
        <span>
          · Net {{ compact(selectedPoint.value) }}
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
          :aria-label="'Net GEX by strike; ' + displayPoints.length + ' displayed points. Use Inspect strike for individual values.'"
        />
      </div>
    </template>

    <template #details>
      <UiDataTable
        caption="Net GEX by raw strike"
        :rows="tableRows"
        :columns="tableColumns"
        row-key="__row_key"
      />
    </template>
  </EodStrikeChartFrame>

  <div v-else class="space-y-2">
    <div class="flex items-center justify-between text-xs text-gray-400">
      <div class="flex flex-col">
        <span>
          Bars: {{ displayPoints.length }}
          <span v-if="strikeData.length > displayPoints.length">
            (from {{ strikeData.length }})
          </span>
        </span>
        <span v-if="focusActivity">
          Focusing on main activity band
        </span>
      </div>

      <div class="flex items-center gap-4">
        <label class="inline-flex items-center gap-1 cursor-pointer select-none">
          <input
            v-model="splitView"
            type="checkbox"
            class="rounded border-gray-600 bg-gray-900 text-cyan-500 focus:ring-cyan-500"
          />
          <span>Split Call/Put</span>
        </label>

        <label class="inline-flex items-center gap-1 cursor-pointer select-none">
          <input
            v-model="focusActivity"
            type="checkbox"
            class="rounded border-gray-600 bg-gray-900 text-cyan-500 focus:ring-cyan-500"
          />
          <span>Focus on activity</span>
        </label>

        <label class="inline-flex items-center gap-1 cursor-pointer select-none">
          <input
            v-model="autoBucket"
            type="checkbox"
            class="rounded border-gray-600 bg-gray-900 text-cyan-500 focus:ring-cyan-500"
          />
          <span>Auto bucket</span>
        </label>

        <div class="flex items-center gap-2">
          <button
            type="button"
            class="px-2 py-0.5 rounded border border-gray-600 hover:bg-gray-800"
            @click="resetZoom"
          >
            Reset zoom
          </button>
          <button
            type="button"
            class="px-2 py-0.5 rounded border border-gray-600 hover:bg-gray-800"
            title="Download chart with GexOptions.com watermark"
            @click="snapshot"
          >
            Download PNG
          </button>
        </div>
      </div>
    </div>

    <div :class="['w-full', heightClass]">
      <Bar ref="chart" :data="chartData" :options="chartOptions" />
    </div>
  </div>
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
