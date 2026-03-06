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
            v-model="splitView"
            class="rounded border-gray-600 bg-gray-900 text-cyan-500 focus:ring-cyan-500"
          />
          <span>Split Call/Put</span>
        </label>

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
      splitView: false,       // when true: show call_gex (green) + put_gex (red) separately
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

      const vals = rows.map(r => Number(r.net_gex ?? r.netGex ?? 0))
      const absVals = vals.map(v => Math.abs(v))
      const maxAbs = Math.max(...absVals)

      // If everything is ~0, just show all.
      if (!isFinite(maxAbs) || maxAbs === 0) return rows

      // Side-specific thresholds prevent strong positive side from hiding
      // meaningful negative side bars (or vice versa).
      const maxPos = Math.max(...vals.map(v => (v > 0 ? v : 0)))
      const maxNegAbs = Math.max(...vals.map(v => (v < 0 ? Math.abs(v) : 0)))
      const posThreshold = maxPos > 0 ? Math.max(maxPos * 0.02, 1_000) : Infinity
      const negThreshold = maxNegAbs > 0 ? Math.max(maxNegAbs * 0.02, 1_000) : Infinity

      let firstIdx = -1
      let lastIdx = -1

      vals.forEach((v, idx) => {
        const keep =
          (v > 0 && v >= posThreshold) ||
          (v < 0 && Math.abs(v) >= negThreshold)

        if (keep) {
          if (firstIdx === -1) firstIdx = idx
          lastIdx = idx
        }
      })

      // If we somehow didn't find any, show all.
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
          call:  Number(r.call_gex ?? r.callGex ?? 0),
          put:   Number(r.put_gex  ?? r.putGex  ?? 0),
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
        call: 0,
        put: 0,
      }))

      for (const r of rows) {
        const s = Number(r.strike)
        const idx = Math.min(
          bucketCount - 1,
          Math.max(0, Math.floor((s - min) / niceStep)),
        )
        buckets[idx].sum  += Number(r.net_gex ?? r.netGex ?? 0)
        buckets[idx].call += Number(r.call_gex ?? r.callGex ?? 0)
        buckets[idx].put  += Number(r.put_gex  ?? r.putGex  ?? 0)
      }

      return buckets
        .filter(b => b.sum !== 0 || b.call !== 0 || b.put !== 0)
        .map(b => ({
          label:
            niceStep >= 5
              ? `${Math.round(b.start)}–${Math.round(b.end)}`
              : `${b.start.toFixed(2)}–${b.end.toFixed(2)}`,
          value: b.sum,
          call:  b.call,
          put:   b.put,
        }))
    },

    chartData() {
      const labels = this.displayPoints.map(p => p.label)

      if (this.splitView) {
        // Call GEX as positive green bars, Put GEX as negative red bars
        return {
          labels,
          datasets: [
            {
              label: 'Call GEX',
              data: this.displayPoints.map(p => p.call),
              backgroundColor: 'rgba(52,211,153,0.9)',
              borderRadius: 3,
              barPercentage: 0.85,
              categoryPercentage: 0.9,
            },
            {
              label: 'Put GEX',
              data: this.displayPoints.map(p => -p.put),
              backgroundColor: 'rgba(248,113,113,0.85)',
              borderRadius: 3,
              barPercentage: 0.85,
              categoryPercentage: 0.9,
            },
          ],
        }
      }

      const data = this.displayPoints.map(p => p.value)
      const colors = data.map(v =>
        v >= 0
          ? 'rgba(52,211,153,0.9)'
          : 'rgba(248,113,113,0.85)',
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
      let yMin, yMax

      if (this.splitView) {
        const maxCall = this.displayPoints.reduce((m, p) => Math.max(m, p.call), 0)
        const maxPut  = this.displayPoints.reduce((m, p) => Math.max(m, p.put),  0)
        const maxAbs  = Math.max(maxCall, maxPut)
        const pad     = maxAbs ? maxAbs * 0.1 : 0
        yMin = maxAbs ? -(maxPut  + pad) : undefined
        yMax = maxAbs ? +(maxCall + pad) : undefined
      } else {
        const maxAbs = this.displayPoints.reduce((m, p) => Math.max(m, Math.abs(p.value)), 0)
        const pad    = maxAbs ? maxAbs * 0.1 : 0
        yMin = maxAbs ? -(maxAbs + pad) : undefined
        yMax = maxAbs ? +(maxAbs + pad) : undefined
      }

      const formatVal = value => {
        const v = Number(value)
        if (Math.abs(v) >= 1_000_000_000) return (v / 1_000_000_000).toFixed(1) + 'B'
        if (Math.abs(v) >= 1_000_000)     return (v / 1_000_000).toFixed(1)     + 'M'
        if (Math.abs(v) >= 1_000)         return (v / 1_000).toFixed(1)         + 'k'
        return v.toString()
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
              label: ctx => {
                const label = ctx.dataset.label || 'Net GEX'
                const v = ctx.parsed.y
                // In split mode, put bars are negated for visual display — show raw magnitude
                const display = (this.splitView && label === 'Put GEX') ? Math.abs(v) : v
                const sign = display > 0 ? '+' : ''
                return `${label}: ${sign}${formatVal(display)}`
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
            min: yMin,
            max: yMax,
            title: {
              display: true,
              text: this.splitView ? 'GEX (Call ↑ / Put ↓)' : 'Net GEX',
              color: '#9ca3af',
              font: { size: 11 },
            },
            grid: {
              color: 'rgba(31,41,55,0.6)',
            },
            ticks: {
              color: '#6b7280',
              callback: formatVal,
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

      off.toBlob((blob) => {
        if (!blob) return
        const url = URL.createObjectURL(blob)
        link.href = url
        document.body.appendChild(link)
        link.click()
        link.remove()
        setTimeout(() => URL.revokeObjectURL(url), 1000)
      }, 'image/png')
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
