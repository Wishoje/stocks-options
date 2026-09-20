<template>
  <EodStrikeChartFrame
    v-if="eod"
    id="eod-volume-change"
    v-model:focus-activity="focusActivity"
    v-model:auto-bucket="autoBucket"
    :selected-value="selectedLabel"
    @update:selected-value="selectFromControl"
    title="Contract volume change by strike"
    :subtitle="basisSubtitle"
    help-title="How to read contract volume change by strike"
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
    details-label="Current-strike-set contract volume fields"
    @reset-zoom="resetZoom"
    @download="snapshot"
  >
    <template #help>
      <div class="gex-stack">
        <p><strong>Contract volume change</strong> compares traded contracts at each strike with the named source snapshot. Positive values show more volume; negative values show less.</p>
        <p>Green identifies calls and red identifies puts. Bar direction shows whether volume increased or decreased; color identifies the option side.</p>
        <p><strong>Focus on activity</strong> narrows the visible band without deleting rows. <strong>Group dense strikes</strong> sums nearby changes while complete daily, weekly, and percentage fields remain in the collapsed table.</p>
        <p>Volume resets each session and can be noisy. Use changes as participation context alongside open interest, GEX, and price action.</p>
        <p><strong>Scope:</strong> the API compares strikes in the current snapshot. Strikes that existed only in the earlier snapshot are not returned, so removals at those prior-only strikes are outside this view.</p>
        <p>When no dated comparison exists, returned daily and weekly fields stay available in the collapsed table but are not plotted as a valid change.</p>
      </div>
    </template>

    <template #metrics>
      <div class="gex-grid strike-metrics">
        <UiMetric
          label="Net volume change"
          :value="compact(totalChange)"
          :tone="totalChange == null || totalChange === 0 ? 'data' : totalChange > 0 ? 'positive' : 'negative'"
          prominence="primary"
          :context="totalChangeContext"
        />
        <UiMetric
          label="Call volume change"
          :value="compact(totalCall)"
          tone="positive"
          :context="`${callAvailableCount} of ${sortedData.length} current numeric strikes`"
        />
        <UiMetric
          label="Put volume change"
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
          :aria-label="'Contract volume change by strike; ' + displayPoints.length + ' displayed points. Use Inspect strike for individual values.'"
        />
      </div>
    </template>

    <template #details>
      <UiDataTable
        caption="Contract volume comparison fields for the current raw strike set"
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
            type="checkbox"
            v-model="focusActivity"
            class="rounded border-gray-600 bg-gray-900 text-cyan-500 focus:ring-cyan-500"
          />
          <span>Focus on activity</span>
        </label>

        <label class="inline-flex items-center gap-1 cursor-pointer select-none">
          <input
            type="checkbox"
            v-model="autoBucket"
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
            @click="snapshot"
            title="Download chart with GexOptions.com watermark"
          >
            Snapshot
          </button>
        </div>
      </div>
    </div>

    <div :class="['w-full', heightClass]">
      <Bar ref="chart" :data="chartData" :options="chartOptions" />
    </div>
  </div>
</template>

<script>
import { Bar } from 'vue-chartjs'
import {
  Chart as ChartJS,
  BarElement,
  CategoryScale,
  LinearScale,
  Tooltip,
  Legend,
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

export default {
  name: 'VolumeDeltaChart',
  components: { Bar, EodStrikeChartFrame, UiDataTable, UiMetric },
  emits: ['reading-inspected'],
  props: {
    strikeData: {
      type: Array,
      default: () => [],
    },
    snapshotName: {
      type: String,
      default: 'delta-vol',
    },
    heightClass: {
      type: String,
      default: 'h-80 md:h-96 xl:h-[26rem]',
    },
    eod: {
      type: Boolean,
      default: false,
    },
    symbol: String,
    timeframe: String,
    snapshotDate: String,
    comparisonBasis: {
      type: String,
      default: 'daily',
    },
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
      if (this.comparisonBasis === 'daily') return row => numeric(row?.call_vol_delta)
      if (this.comparisonBasis === 'weekly') return row => numeric(row?.call_vol_wow)
      return () => null
    },
    putAccessor() {
      if (this.comparisonBasis === 'daily') return row => numeric(row?.put_vol_delta)
      if (this.comparisonBasis === 'weekly') return row => numeric(row?.put_vol_wow)
      return () => null
    },
    basisSubtitle() {
      if (this.comparisonBasis === 'daily') return 'Daily contract volume change for the current strike set'
      if (this.comparisonBasis === 'weekly') return 'Weekly contract volume fallback for the current strike set'
      return 'No dated contract volume comparison is available for the current strike set'
    },
    emptyTitle() {
      return this.comparisonBasis === 'daily' || this.comparisonBasis === 'weekly'
        ? 'No contract volume changes'
        : 'Contract volume comparison unavailable'
    },
    emptyMessage() {
      return this.comparisonBasis === 'daily' || this.comparisonBasis === 'weekly'
        ? 'No usable call or put contract volume changes were returned for these current strikes.'
        : 'No dated earlier snapshot was returned, so change values are not plotted. Returned raw fields remain available below.'
    },
    rawRows() {
      return normalizedStrikeRows(this.strikeData)
    },
    sortedData() {
      if (this.eod) return chartStrikeRows(this.strikeData)
      return [...(this.strikeData || [])]
        .filter(r => r && r.strike != null)
        .sort((a, b) => Number(a.strike) - Number(b.strike))
    },

    focusedData() {
      const rows = this.sortedData
      if (!rows.length) return []

      if (this.eod) {
        return focusActivityBand(rows, [this.callAccessor, this.putAccessor], {
          relativeThreshold: 0.02,
          absoluteThreshold: 500,
          padding: this.PADDING_STRIKES,
        })
      }

      const callVals = rows.map(r => Math.abs(Number(r.call_vol_delta ?? 0)))
      const putVals  = rows.map(r => Math.abs(Number(r.put_vol_delta ?? 0)))
      const maxCall = Math.max(...callVals)
      const maxPut  = Math.max(...putVals)
      if ((!isFinite(maxCall) || maxCall === 0) && (!isFinite(maxPut) || maxPut === 0)) {
        return rows
      }

      // Side-aware thresholds prevent dominant calls from clipping all puts (or vice versa).
      const callThreshold = maxCall > 0 ? Math.max(maxCall * 0.02, 500) : Infinity
      const putThreshold  = maxPut  > 0 ? Math.max(maxPut  * 0.02, 500) : Infinity

      let firstIdx = -1
      let lastIdx = -1

      rows.forEach((r, idx) => {
        const c = Math.abs(Number(r.call_vol_delta ?? 0))
        const p = Math.abs(Number(r.put_vol_delta ?? 0))
        const keep = c >= callThreshold || p >= putThreshold
        if (keep) {
          if (firstIdx === -1) firstIdx = idx
          lastIdx = idx
        }
      })

      if (firstIdx === -1 || lastIdx === -1) return rows

      firstIdx = Math.max(0, firstIdx - this.PADDING_STRIKES)
      lastIdx = Math.min(rows.length - 1, lastIdx + this.PADDING_STRIKES)

      return rows.slice(firstIdx, lastIdx + 1)
    },

    displayPoints() {
      const baseRows = this.focusActivity ? this.focusedData : this.sortedData
      const rows = baseRows
      if (!rows.length) return []

      if (this.eod) {
        return buildStrikePoints(rows, {
          call: this.callAccessor,
          put: this.putAccessor,
        }, {
          bucket: this.autoBucket,
          maxBars: this.MAX_BARS,
        })
      }

      if (!this.autoBucket || rows.length <= this.MAX_BARS) {
        return rows.map(r => ({
          label: String(r.strike),
          call: Number(r.call_vol_delta ?? 0),
          put: Number(r.put_vol_delta ?? 0),
        }))
      }

      const min = Number(rows[0].strike)
      const max = Number(rows[rows.length - 1].strike)

      const bucketCount = Math.min(this.MAX_BARS, rows.length)
      const rawSize = (max - min) / bucketCount || 1
      const niceStep = this.niceStep(rawSize)

      const buckets = new Array(bucketCount).fill(null).map((_, i) => ({
        start: min + i * niceStep,
        end: min + (i + 1) * niceStep,
        call: 0,
        put: 0,
      }))

      for (const r of rows) {
        const strike = Number(r.strike)
        const idx = Math.min(
          bucketCount - 1,
          Math.max(0, Math.floor((strike - min) / niceStep)),
        )
        buckets[idx].call += Number(r.call_vol_delta ?? 0)
        buckets[idx].put += Number(r.put_vol_delta ?? 0)
      }

      return buckets
        .filter(b => b.call !== 0 || b.put !== 0)
        .map(b => ({
          label:
            niceStep >= 5
              ? `${Math.round(b.start)}-${Math.round(b.end)}`
              : `${b.start.toFixed(2)}-${b.end.toFixed(2)}`,
          call: b.call,
          put: b.put,
        }))
    },

    chartData() {
      const labels = this.displayPoints.map(p => p.label)
      const callData = this.displayPoints.map(p => p.call)
      const putData = this.displayPoints.map(p => p.put)

      if (!this.eod) {
        return {
          labels,
          datasets: [
            {
              label: 'Call Delta Vol',
              data: callData,
              backgroundColor: 'rgba(52,211,153,0.9)',
              borderRadius: 3,
              barPercentage: 0.9,
              categoryPercentage: 0.9,
            },
            {
              label: 'Put Delta Vol',
              data: putData,
              backgroundColor: 'rgba(248,113,113,0.9)',
              borderRadius: 3,
              barPercentage: 0.9,
              categoryPercentage: 0.9,
            },
          ],
        }
      }

      const borderColor = this.displayPoints.map(point => point.label === this.selectedLabel ? '#9bb4ff' : 'transparent')
      const borderWidth = this.displayPoints.map(point => point.label === this.selectedLabel ? 2 : 0)
      return {
        labels,
        datasets: [
          {
            label: 'Call Delta Vol',
            data: callData,
            backgroundColor: 'rgba(115,214,177,0.9)',
            borderColor,
            borderWidth,
            borderRadius: 3,
            barPercentage: 0.9,
            categoryPercentage: 0.9,
          },
          {
            label: 'Put Delta Vol',
            data: putData,
            backgroundColor: 'rgba(240,149,137,0.9)',
            borderColor,
            borderWidth,
            borderRadius: 3,
            barPercentage: 0.9,
            categoryPercentage: 0.9,
          },
        ],
      }
    },

    chartOptions() {
      const maxAbs = this.displayPoints.reduce(
        (m, p) => Math.max(m, Math.abs(p.call), Math.abs(p.put)),
        0,
      )
      const pad = maxAbs ? maxAbs * 0.1 : 0

      if (!this.eod) {
        return {
          responsive: true,
          maintainAspectRatio: false,
          animation: false,
          plugins: {
            legend: {
              display: true,
              labels: {
                color: '#e5e7eb',
              },
            },
            tooltip: {
              backgroundColor: 'rgba(15,23,42,0.95)',
              borderColor: 'rgba(148,163,184,0.6)',
              borderWidth: 1,
              padding: 10,
              callbacks: {
                title: items => {
                  const item = items[0]
                  return `Strike ${item.label}`
                },
                label: ctx => {
                  const label = ctx.dataset.label || ''
                  const v = ctx.parsed.y
                  const sign = v > 0 ? '+' : ''
                  return `${label}: ${sign}${this.formatNumber(v)}`
                },
              },
            },
            zoom: {
              pan: {
                enabled: true,
                mode: 'x',
              },
              zoom: {
                wheel: { enabled: true },
                pinch: { enabled: true },
                mode: 'x',
              },
            },
          },
          scales: {
            x: {
              title: {
                display: true,
                text: 'Strike (bucketed / focused)',
                color: '#9ca3af',
                font: { size: 11 },
              },
              grid: { display: false },
              ticks: {
                autoSkip: true,
                maxTicksLimit: 18,
                color: '#6b7280',
              },
            },
            y: {
              min: maxAbs ? -(maxAbs + pad) : undefined,
              max: maxAbs ? maxAbs + pad : undefined,
              title: {
                display: true,
                text: 'Delta Volume (contracts)',
                color: '#9ca3af',
                font: { size: 11 },
              },
              grid: {
                color: 'rgba(31,41,55,0.6)',
              },
              ticks: {
                color: '#6b7280',
                callback: value => this.formatNumber(value),
              },
            },
          },
        }
      }

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
            labels: {
              color: '#f2f4f7',
              usePointStyle: true,
              pointStyle: 'rectRounded',
            },
          },
          tooltip: {
            backgroundColor: 'rgba(23,30,38,0.98)',
            borderColor: '#313e4c',
            borderWidth: 1,
            padding: 10,
            callbacks: {
              title: items => {
                const item = items[0]
                return `Strike ${item.label}`
              },
              label: ctx => {
                const label = ctx.dataset.label || ''
                const v = ctx.parsed.y
                const sign = v > 0 ? '+' : ''
                return `${label}: ${sign}${this.formatNumber(v)}`
              },
              afterBody: items => {
                const point = this.displayPoints[items[0]?.dataIndex]
                return point?.sourceCount > 1 ? `${point.sourceCount} raw strikes grouped` : ''
              },
            },
          },
          zoom: {
            pan: {
              enabled: true,
              mode: 'x',
            },
            zoom: {
              wheel: { enabled: true },
              pinch: { enabled: true },
              mode: 'x',
            },
          },
        },
        scales: {
          x: {
            title: {
              display: true,
              text: this.autoBucket ? 'Strike range' : 'Strike',
              color: '#9ca3af',
              font: { size: 11 },
            },
            grid: { display: false },
            ticks: {
              autoSkip: true,
              maxTicksLimit: 18,
              color: '#6b7280',
            },
          },
          y: {
            min: maxAbs ? -(maxAbs + pad) : undefined,
            max: maxAbs ? maxAbs + pad : undefined,
            title: {
              display: true,
              text: 'Delta Volume (contracts)',
              color: '#9ca3af',
              font: { size: 11 },
            },
            grid: {
              color: context => Number(context.tick?.value) === 0 ? 'rgba(174,183,194,0.82)' : 'rgba(49,62,76,0.62)',
              lineWidth: context => Number(context.tick?.value) === 0 ? 2 : 1,
            },
            ticks: {
              color: '#6b7280',
              callback: value => this.formatNumber(value),
            },
          },
        },
      }
    },
    hasChartData() {
      return this.displayPoints.some(point => point.call != null || point.put != null)
    },
    totalCall() {
      return this.eod ? sumAvailable(this.sortedData, this.callAccessor) : null
    },
    totalPut() {
      return this.eod ? sumAvailable(this.sortedData, this.putAccessor) : null
    },
    callAvailableCount() {
      return this.eod ? countAvailable(this.sortedData, this.callAccessor) : 0
    },
    putAvailableCount() {
      return this.eod ? countAvailable(this.sortedData, this.putAccessor) : 0
    },
    comparisonCoverageComplete() {
      return this.eod
        && this.sortedData.length > 0
        && this.callAvailableCount === this.sortedData.length
        && this.putAvailableCount === this.sortedData.length
    },
    totalChange() {
      if (!this.comparisonCoverageComplete) return null
      return this.totalCall + this.totalPut
    },
    totalChangeContext() {
      if (this.comparisonBasis !== 'daily' && this.comparisonBasis !== 'weekly') return 'No dated comparison source'
      if (!this.comparisonCoverageComplete) {
        return `Incomplete coverage · Call ${this.callAvailableCount}/${this.sortedData.length} · Put ${this.putAvailableCount}/${this.sortedData.length}`
      }
      return this.comparisonBasis === 'daily' ? 'Call plus put daily change' : 'Call plus put weekly change'
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
    eodDownloadName() {
      if (this.snapshotName !== 'delta-vol') return chartSnapshotName(this.snapshotName)
      return chartSnapshotName(
        this.snapshotName,
        this.symbol,
        this.timeframe,
        this.snapshotDate,
        this.comparisonBasis,
        this.comparisonDate,
      )
    },
    tableRows() {
      if (!this.eod) return []
      return this.rawRows.map(row => ({
        ...row,
        strike_display: row.__strike,
        used_call: this.callAccessor(row),
        used_put: this.putAccessor(row),
        call_daily: numeric(row?.call_vol_delta),
        put_daily: numeric(row?.put_vol_delta),
        call_weekly: numeric(row?.call_vol_wow),
        put_weekly: numeric(row?.put_vol_wow),
      }))
    },
    tableColumns() {
      return [
        { key: 'strike_display', label: 'Strike', numeric: true, sortable: true },
        { key: 'used_call', label: 'Displayed call change', numeric: true, sortable: true, format: compact },
        { key: 'used_put', label: 'Displayed put change', numeric: true, sortable: true, format: compact },
        { key: 'call_daily', label: 'Returned daily call field', numeric: true, sortable: true, format: compact },
        { key: 'put_daily', label: 'Returned daily put field', numeric: true, sortable: true, format: compact },
        { key: 'call_vol_delta_pct', label: 'Returned daily call % field', numeric: true, sortable: true, format: this.formatPercentage },
        { key: 'put_vol_delta_pct', label: 'Returned daily put % field', numeric: true, sortable: true, format: this.formatPercentage },
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
        if (!this.eod || points.some(point => point.label === this.selectedLabel)) return
        this.selectedLabel = strongestByMagnitude(points, [point => point.call, point => point.put])?.label ?? ''
      },
    },
  },
  methods: {
    compact,
    resetZoom() {
      const chart = this.$refs.chart && this.$refs.chart.chart
      if (chart && chart.resetZoom) chart.resetZoom()
    },
    snapshot() {
      const chart = this.$refs.chart && this.$refs.chart.chart
      if (!chart || !chart.canvas) return
      if (this.eod) {
        downloadChartSnapshot(chart, this.eodDownloadName)
        return
      }
      const src = chart.canvas
      const w = src.width; const h = src.height
      const off = document.createElement('canvas')
      off.width = w; off.height = h
      const ctx = off.getContext('2d')
      ctx.fillStyle = 'rgb(12,17,27)'
      ctx.fillRect(0,0,w,h)
      ctx.drawImage(src,0,0)
      const text = 'GexOptions.com'
      ctx.save()
      ctx.font = `${Math.max(32, Math.round(w*0.08))}px "Inter","Segoe UI",system-ui,sans-serif`
      ctx.fillStyle = 'rgba(255,255,255,0.14)'
      ctx.textAlign = 'center'
      ctx.textBaseline = 'top'
      ctx.fillText(text, w/2, Math.max(12, h*0.04))
      ctx.restore()
      const link = document.createElement('a')
      const ts = new Date().toISOString().slice(0,10)
      link.download = `${this.snapshotName || 'delta-vol'}-${ts}.png`
      link.href = off.toDataURL('image/png')
      link.click()
    },
    niceStep(step) {
      const pow10 = Math.pow(10, Math.floor(Math.log10(step)))
      const units = [1, 2, 5, 10]
      for (const u of units) {
        const s = u * pow10
        if (step <= s) return s
      }
      return 10 * pow10
    },
    formatNumber(value) {
      const v = Number(value)
      if (!isFinite(v)) return String(value)
      if (Math.abs(v) >= 1_000_000) return (v / 1_000_000).toFixed(1) + 'M'
      if (Math.abs(v) >= 1_000) return (v / 1_000).toFixed(1) + 'k'
      return v.toString()
    },
    formatPercentage(value) {
      const number = numeric(value)
      return number == null ? 'Unavailable' : `${number > 0 ? '+' : ''}${number.toFixed(1)}%`
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
