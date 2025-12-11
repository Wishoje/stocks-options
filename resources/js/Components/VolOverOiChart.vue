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
  name: 'VolOverOiChart',
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
    sortedData() {
      return [...(this.strikeData || [])]
        .filter(r => r && r.strike != null)
        .sort((a, b) => Number(a.strike) - Number(b.strike))
    },

    focusedData() {
      const rows = this.sortedData
      if (!rows.length) return []

      const vals = rows.map(r => {
        const vol = Number(r.call_vol_delta ?? r.call_volume_delta ?? 0) +
                    Number(r.put_vol_delta  ?? r.put_volume_delta  ?? 0)

        const oiSum = Number(r.oi_call_eod ?? 0) + Number(r.oi_put_eod ?? 0)
        const volOverOi = (r.vol_oi === 0 || r.vol_oi)
          ? Number(r.vol_oi)
          : (oiSum > 0 ? vol / oiSum : null)

        return volOverOi != null && Number.isFinite(volOverOi) ? Math.abs(volOverOi) : 0
      })

      const maxAbs = Math.max(...vals)
      if (!isFinite(maxAbs) || maxAbs === 0) return rows

      // keep strikes where Vol/OI is at least 5% of max (or >= 0.1)
      const threshold = Math.max(maxAbs * 0.05, 0.1)

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

    displayPoints() {
      const baseRows = this.focusActivity ? this.focusedData : this.sortedData
      const rows = baseRows
      if (!rows.length) return []

      // No bucketing → one bar per strike
      if (!this.autoBucket || rows.length <= this.MAX_BARS) {
        const points = []
        for (const r of rows) {
          const vol = Number(r.call_vol_delta ?? r.call_volume_delta ?? 0) +
                      Number(r.put_vol_delta  ?? r.put_volume_delta  ?? 0)
          const oiSum = Number(r.oi_call_eod ?? 0) + Number(r.oi_put_eod ?? 0)
          const volOverOi = (r.vol_oi === 0 || r.vol_oi)
            ? Number(r.vol_oi)
            : (oiSum > 0 ? vol / oiSum : null)

          if (volOverOi !== null && Number.isFinite(volOverOi)) {
            points.push({
              label: String(r.strike),
              value: volOverOi,
            })
          }
        }
        return points
      }

      // Bucket mode: average Vol/OI per bucket
      const min = Number(rows[0].strike)
      const max = Number(rows[rows.length - 1].strike)

      const bucketCount = Math.min(this.MAX_BARS, rows.length)
      const rawSize = (max - min) / bucketCount || 1
      const niceStep = this.niceStep(rawSize)

      const buckets = new Array(bucketCount).fill(null).map((_, i) => ({
        start: min + i * niceStep,
        end: min + (i + 1) * niceStep,
        sum: 0,
        count: 0,
      }))

      for (const r of rows) {
        const strike = Number(r.strike)

        const vol = Number(r.call_vol_delta ?? r.call_volume_delta ?? 0) +
                    Number(r.put_vol_delta  ?? r.put_volume_delta  ?? 0)
        const oiSum = Number(r.oi_call_eod ?? 0) + Number(r.oi_put_eod ?? 0)
        const volOverOi = (r.vol_oi === 0 || r.vol_oi)
          ? Number(r.vol_oi)
          : (oiSum > 0 ? vol / oiSum : null)

        if (volOverOi === null || !Number.isFinite(volOverOi)) continue

        const idx = Math.min(
          bucketCount - 1,
          Math.max(0, Math.floor((strike - min) / niceStep)),
        )
        buckets[idx].sum += volOverOi
        buckets[idx].count++
      }

      return buckets
        .filter(b => b.count > 0)
        .map(b => ({
          label:
            niceStep >= 5
              ? `${Math.round(b.start)}–${Math.round(b.end)}`
              : `${b.start.toFixed(2)}–${b.end.toFixed(2)}`,
          value: b.sum / b.count,
        }))
    },

    chartData() {
      const labels = this.displayPoints.map(p => p.label)
      const data = this.displayPoints.map(p => p.value)

      const colors = data.map(() => 'rgba(56,189,248,0.85)') // cyan-ish

      return {
        labels,
        datasets: [
          {
            label: 'Vol/OI (Live)',
            data,
            backgroundColor: colors,
            borderRadius: 3,
            barPercentage: 0.9,
            categoryPercentage: 0.9,
          },
        ],
      }
    },

    chartOptions() {
      const maxVal = this.displayPoints.reduce(
        (m, p) => Math.max(m, p.value),
        0,
      )
      const pad = maxVal ? maxVal * 0.1 : 0

      return {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: 'rgba(15,23,42,0.95)',
            borderColor: 'rgba(148,163,184,0.6)',
            borderWidth: 1,
            padding: 10,
            callbacks: {
              title: items => `Strike ${items[0].label}`,
              label: ctx => `Vol/OI: ${ctx.parsed.y.toFixed(2)}`,
            },
          },
          zoom: {
            pan: { enabled: true, mode: 'x' },
            zoom: { wheel: { enabled: true }, pinch: { enabled: true }, mode: 'x' },
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
            min: 0,
            max: maxVal ? maxVal + pad : undefined,
            title: {
              display: true,
              text: 'Vol/OI',
              color: '#9ca3af',
              font: { size: 11 },
            },
            grid: { color: 'rgba(31,41,55,0.6)' },
            ticks: {
              color: '#6b7280',
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
  },
}
</script>
