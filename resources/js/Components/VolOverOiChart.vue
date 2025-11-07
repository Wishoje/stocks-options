<template><Bar :data="chartData" :options="chartOptions" /></template>
<script>
import { Bar } from 'vue-chartjs'
import { Chart, BarElement, CategoryScale, LinearScale, Tooltip, Legend } from 'chart.js'
Chart.register(BarElement, CategoryScale, LinearScale, Tooltip, Legend)

export default {
  name: 'VolOverOiChart',
  components: { Bar },
  props: { strikeData: { type: Array, default: () => [] } },
  computed: {
    chartData() {
      const labels = []
      const vals = []
      for (const r of this.strikeData) {
        // Volume: accept both field variants
        const vol = Number(r.call_vol_delta ?? r.call_volume_delta ?? 0)
                + Number(r.put_vol_delta  ?? r.put_volume_delta  ?? 0)

        // OI: prefer precomputed vol_oi if available; else compute vol / (oi_call_eod + oi_put_eod)
        const oiSum = Number(r.oi_call_eod ?? 0) + Number(r.oi_put_eod ?? 0)
        const volOverOi = (r.vol_oi === 0 || r.vol_oi) ? Number(r.vol_oi)
                        : (oiSum > 0 ? vol / oiSum : null)

        if (volOverOi !== null && Number.isFinite(volOverOi)) {
          labels.push(r.strike)
          vals.push(volOverOi)
        }
      }
      return { labels, datasets: [{ label: 'Vol/OI (Live)', data: vals }] }
    },
    chartOptions() {
      return {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: true } },
        scales: {
          x: { title: { display: true, text: 'Strike' } },
          y: { title: { display: true, text: 'Vol/OI' } }
        }
      }
    }
  }
}
</script>
