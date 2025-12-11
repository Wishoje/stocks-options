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
  name: 'PcrByStrikeChart',
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

      // "Activity" = distance from PCR = 1
      const deltas = rows.map(r => {
        const pcrProvided = (r.pcr === 0 || r.pcr) ? Number(r.pcr) : null
        const c = Number(r.call_vol_delta ?? r.call_volume_delta ?? 0)
        const p = Number(r.put_vol_delta  ?? r.put_volume_delta  ?? 0)

        const ratio = pcrProvided !== null
          ? pcrProvided
          : (c > 0 ? p / c : null)

        if (ratio === null || !Number.isFinite(ratio)) return 0
        return Math.abs(ratio - 1)
      })

      const maxAbs = Math.max(...deltas)
      if (!isFinite(maxAbs) || maxAbs === 0) return rows

      // keep strikes where |PCR - 1| is at least 25% of max deviation or >= 0.2
      const threshold = Math.max(maxAbs * 0.25, 0.2)

      let firstIdx = -1
      let lastIdx = -1

      deltas.forEach((v, idx) => {
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

      if (!this.autoBucket || rows.length <= this.MAX_BARS) {
        const pts = []
        for (const r of rows) {
          const pcrProvided = (r.pcr === 0 || r.pcr) ? Number(r.pcr) : null
          const c = Number(r.call_vol_delta ?? r.call_volume_delta ?? 0)
          const p = Number(r.put_vol_delta  ?? r.put_volume_delta  ?? 0)

          const ratio = pcrProvided !== null
            ? pcrProvided
            : (c > 0 ? p / c : null)

          if (ratio === null || !Number.isFinite(ratio)) continue

          pts.push({
            label: String(r.strike),
            value: ratio,
          })
        }
        return pts
      }

      // Bucket mode: volume-weighted PCR
      const min = Number(rows[0].strike)
      const max = Number(rows[rows.length - 1].strike)

      const bucketCount = Math.min(this.MAX_BARS, rows.length)
      const rawSize = (max - min) / bucketCount || 1
      const niceStep = this.niceStep(rawSize)

      const buckets = new Array(bucketCount).fill(null).map((_, i) => ({
        start: min + i * niceStep,
        end: min + (i + 1) * niceStep,
        putVol: 0,
        callVol: 0,
      }))

      for (const r of rows) {
        const idx = Math.min(
          bucketCount - 1,
          Math.max(0, Math.floor((Number(r.strike) - min) / niceStep)),
        )
        buckets[idx].callVol += Number(r.call_vol_delta ?? r.call_volume_delta ?? 0)
        buckets[idx].putVol  += Number(r.put_vol_delta  ?? r.put_volume_delta  ?? 0)
      }

      return buckets
        .filter(b => b.callVol > 0 || b.putVol > 0)
        .map(b => {
          const ratio = b.callVol > 0 ? (b.putVol / b.callVol) : null
          if (ratio === null || !Number.isFinite(ratio)) return null
          return {
            label:
              niceStep >= 5
                ? `${Math.round(b.start)}–${Math.round(b.end)}`
                : `${b.start.toFixed(2)}–${b.end.toFixed(2)}`,
            value: ratio,
          }
        })
        .filter(Boolean)
    },

    chartData() {
      const labels = this.displayPoints.map(p => p.label)
      const data = this.displayPoints.map(p => p.value)

      const colors = data.map(v =>
        v >= 1
          ? 'rgba(248,113,113,0.85)' // PCR > 1 → put-heavy → red
          : 'rgba(52,211,153,0.9)'   // PCR < 1 → call-led → green
      )

      return {
        labels,
        datasets: [
          {
            label: 'PCR (Live)',
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
      if (!this.displayPoints.length) {
        return {
          responsive: true,
          maintainAspectRatio: false,
          animation: false,
          scales: {
            x: { display: true },
            y: { display: true },
          },
        }
      }

      const minVal = this.displayPoints.reduce(
        (m, p) => Math.min(m, p.value),
        this.displayPoints[0].value,
      )
      const maxVal = this.displayPoints.reduce(
        (m, p) => Math.max(m, p.value),
        this.displayPoints[0].value,
      )
      const pad = (maxVal - minVal) * 0.1

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
              label: ctx => `PCR: ${ctx.parsed.y.toFixed(2)}`,
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
            min: Math.max(0, minVal - pad),
            max: maxVal + pad,
            title: {
              display: true,
              text: 'PCR (puts ÷ calls)',
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
