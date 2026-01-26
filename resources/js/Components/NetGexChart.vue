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

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip, Legend, zoomPlugin)

export default {
  name: 'NetGexChart',
  components: { Bar },
  props: {
    strikeData: {
      type: Array,
      default: () => [],
    },
    snapshotName: {
      type: String,
      default: 'net-gex',
    },
     heightClass: {
      type: String,
      default: 'h-80 md:h-96 xl:h-[26rem]', // taller than h-72
    },
  },
  data() {
    return {
      autoBucket: false,      // OFF by default now
      focusActivity: true,    // ON by default
      MAX_BARS: 100,          // fewer bars → chunkier
      PADDING_STRIKES: 8,     // extra strikes to include around activity band
    }
  },
  computed: {
    // Sorted full dataset
    sortedData() {
      return [...(this.strikeData || [])]
        .filter(r => r && r.strike != null)
        .sort((a, b) => Number(a.strike) - Number(b.strike))
    },

    // Strike range where GEX is actually doing something
    focusedData() {
      const rows = this.sortedData
      if (!rows.length) return []

      // get absolute Net GEX values
      const vals = rows.map(r => Math.abs(Number(r.net_gex ?? r.netGex ?? 0)))
      const maxAbs = Math.max(...vals)

      // if everything is ~0, just show all
      if (!isFinite(maxAbs) || maxAbs === 0) return rows

      // threshold: keep strikes where |GEX| >= 2% of max, but at least 1k
      const threshold = Math.max(maxAbs * 0.02, 1_000)

      let firstIdx = -1
      let lastIdx = -1

      vals.forEach((v, idx) => {
        if (v >= threshold) {
          if (firstIdx === -1) firstIdx = idx
          lastIdx = idx
        }
      })

      // if we somehow didn't find any, show all
      if (firstIdx === -1 || lastIdx === -1) return rows

      // add padding strikes on both sides
      firstIdx = Math.max(0, firstIdx - this.PADDING_STRIKES)
      lastIdx = Math.min(rows.length - 1, lastIdx + this.PADDING_STRIKES)

      return rows.slice(firstIdx, lastIdx + 1)
    },

    // What we actually plot (after focusing + optional bucketing)
    displayPoints() {
      const baseRows = this.focusActivity ? this.focusedData : this.sortedData
      const rows = baseRows
      if (!rows.length) return []

      // no bucket → 1:1 mapping
      if (!this.autoBucket || rows.length <= this.MAX_BARS) {
        return rows.map(r => ({
          label: String(r.strike),
          value: Number(r.net_gex ?? r.netGex ?? 0),
        }))
      }

      // bucketed mode
      const min = Number(rows[0].strike)
      const max = Number(rows[rows.length - 1].strike)

      const bucketCount = Math.min(this.MAX_BARS, rows.length)
      const rawSize = (max - min) / bucketCount || 1
      const niceStep = this.niceStep(rawSize)

      const buckets = new Array(bucketCount).fill(null).map((_, i) => ({
        start: min + i * niceStep,
        end: min + (i + 1) * niceStep,
        sum: 0,
      }))

      for (const r of rows) {
        const s = Number(r.strike)
        const idx = Math.min(
          bucketCount - 1,
          Math.max(0, Math.floor((s - min) / niceStep)),
        )
        buckets[idx].sum += Number(r.net_gex ?? r.netGex ?? 0)
      }

      return buckets
        .filter(b => b.sum !== 0)
        .map(b => ({
          label:
            niceStep >= 5
              ? `${Math.round(b.start)}–${Math.round(b.end)}`
              : `${b.start.toFixed(2)}–${b.end.toFixed(2)}`,
          value: b.sum,
        }))
    },

    chartData() {
      const labels = this.displayPoints.map(p => p.label)
      const data = this.displayPoints.map(p => p.value)

      const colors = data.map(v =>
        v >= 0
          ? 'rgba(52,211,153,0.9)'    // green instead of cyan
          : 'rgba(248,113,113,0.85)', // red-ish
      )

      return {
        labels,
        datasets: [
          {
            label: 'Net GEX',
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
      const maxAbs = this.displayPoints.reduce(
        (m, p) => Math.max(m, Math.abs(p.value)),
        0,
      )
      const pad = maxAbs ? maxAbs * 0.1 : 0

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
              title: items => {
                const item = items[0]
                return `Strike ${item.label}`
              },
              label: ctx => {
                const v = ctx.parsed.y
                const sign = v > 0 ? '+' : ''
                return `Net GEX: ${sign}${v.toLocaleString()}`
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
              text: 'Net GEX',
              color: '#9ca3af',
              font: { size: 11 },
            },
            grid: {
              color: 'rgba(31,41,55,0.6)',
            },
            ticks: {
              color: '#6b7280',
              callback: value => {
                const v = Number(value)
                if (Math.abs(v) >= 1_000_000) return (v / 1_000_000).toFixed(1) + 'M'
                if (Math.abs(v) >= 1_000) return (v / 1_000).toFixed(1) + 'k'
                return v.toString()
              },
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
    snapshot() {
      const chart = this.$refs.chart && this.$refs.chart.chart
      if (!chart || !chart.canvas) return
      const src = chart.canvas
      const w = src.width
      const h = src.height
      const off = document.createElement('canvas')
      off.width = w; off.height = h
      const ctx = off.getContext('2d')

      ctx.fillStyle = 'rgb(12,17,27)'
      ctx.fillRect(0, 0, w, h)
      ctx.drawImage(src, 0, 0)

      // big, low-opacity watermark pinned at the top (no rotation)
      const text = 'GexOptions.com'
      ctx.save()
      ctx.font = `${Math.max(32, Math.round(w * 0.08))}px "Inter","Segoe UI",system-ui,sans-serif`
      ctx.fillStyle = 'rgba(255,255,255,0.14)'
      ctx.textAlign = 'center'
      ctx.textBaseline = 'top'
      ctx.fillText(text, w / 2, Math.max(12, h * 0.04))
      ctx.restore()

      const link = document.createElement('a')
      const ts = new Date().toISOString().slice(0, 10)
      link.download = `${this.snapshotName || 'net-gex'}-${ts}.png`
      link.href = off.toDataURL('image/png')
      link.click()
    },
    // pick a "nice" bucket width given a raw step
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
