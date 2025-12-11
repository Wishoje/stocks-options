<template>
  <div class="space-y-2">
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

        <button
          type="button"
          class="px-2 py-0.5 rounded border border-gray-600 hover:bg-gray-800"
          @click="resetZoom"
        >
          Reset zoom
        </button>
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

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip, Legend, zoomPlugin)

export default {
  name: 'StrikeDeltaChart',
  components: { Bar },
  props: {
    strikeData: {
      type: Array,
      default: () => [],
    },
    heightClass: {
      type: String,
      default: 'h-80 md:h-96 xl:h-[26rem]',
    },
  },
  data() {
    return {
      autoBucket: false,
      focusActivity: true,
      MAX_BARS: 100,
      PADDING_STRIKES: 8,
    }
  },
  computed: {
    // Sorted by strike
    sortedData() {
      return [...(this.strikeData || [])]
        .filter(r => r && r.strike != null)
        .sort((a, b) => Number(a.strike) - Number(b.strike))
    },

    // Focus on band where ΔOI is meaningful
    focusedData() {
      const rows = this.sortedData
      if (!rows.length) return []

      const vals = rows.map(r => {
        const c = Math.abs(Number(r.call_oi_delta ?? 0))
        const p = Math.abs(Number(r.put_oi_delta ?? 0))
        return Math.max(c, p)
      })

      const maxAbs = Math.max(...vals)
      if (!isFinite(maxAbs) || maxAbs === 0) return rows

      // Keep strikes where max(|ΔOI|) >= 2% of max, but at least 50
      const threshold = Math.max(maxAbs * 0.02, 50)

      let firstIdx = -1
      let lastIdx = -1

      vals.forEach((v, idx) => {
        if (v >= threshold) {
          if (firstIdx === -1) firstIdx = idx
          lastIdx = idx
        }
      })

      if (firstIdx === -1 || lastIdx === -1) return rows

      firstIdx = Math.max(0, firstIdx - this.PADDING_STRIKES)
      lastIdx = Math.min(rows.length - 1, lastIdx + this.PADDING_STRIKES)

      return rows.slice(firstIdx, lastIdx + 1)
    },

    // Final points we plot (after focus + optional bucketing)
    displayPoints() {
      const baseRows = this.focusActivity ? this.focusedData : this.sortedData
      const rows = baseRows
      if (!rows.length) return []

      if (!this.autoBucket || rows.length <= this.MAX_BARS) {
        return rows.map(r => ({
          label: String(r.strike),
          call: Number(r.call_oi_delta ?? 0),
          put: Number(r.put_oi_delta ?? 0),
        }))
      }

      // Bucket mode
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
        buckets[idx].call += Number(r.call_oi_delta ?? 0)
        buckets[idx].put += Number(r.put_oi_delta ?? 0)
      }

      return buckets
        // keep buckets that have *some* activity
        .filter(b => b.call !== 0 || b.put !== 0)
        .map(b => ({
          label:
            niceStep >= 5
              ? `${Math.round(b.start)}–${Math.round(b.end)}`
              : `${b.start.toFixed(2)}–${b.end.toFixed(2)}`,
          call: b.call,
          put: b.put,
        }))
    },

    chartData() {
      const labels = this.displayPoints.map(p => p.label)
      const callData = this.displayPoints.map(p => p.call)
      const putData = this.displayPoints.map(p => p.put)

      return {
        labels,
        datasets: [
          {
            label: 'Call ΔOI',
            data: callData,
            backgroundColor: 'rgba(52,211,153,0.9)', // green-ish like volume calls
            borderRadius: 3,
            barPercentage: 0.9,
            categoryPercentage: 0.9,
          },
          {
            label: 'Put ΔOI',
            data: putData,
            backgroundColor: 'rgba(248,113,113,0.85)', // red-ish (bearish)
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
              text: 'ΔOI',
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
    },
  },
  methods: {
    resetZoom() {
      const chart = this.$refs.chart && this.$refs.chart.chart
      if (chart && chart.resetZoom) chart.resetZoom()
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
  },
}
</script>
