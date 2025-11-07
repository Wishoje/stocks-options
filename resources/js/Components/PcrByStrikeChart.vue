<template><Bar :data="chartData" :options="chartOptions" /></template>
<script>
import { Bar } from 'vue-chartjs'
import { Chart, BarElement, CategoryScale, LinearScale, Tooltip, Legend } from 'chart.js'
Chart.register(BarElement, CategoryScale, LinearScale, Tooltip, Legend)

export default {
  name: 'PcrByStrikeChart',
  components: { Bar },
  props: { strikeData: { type: Array, default: () => [] } },
  computed: {
    chartData() {
      const labels = []
      const vals = []
      for (const r of this.strikeData) {
        // Prefer provided PCR if present; else compute from deltas (robust to both field names)
        const pcrProvided = (r.pcr === 0 || !!r.pcr) ? Number(r.pcr) : null
        const c = Number(r.call_vol_delta ?? r.call_volume_delta ?? 0)
        const p = Number(r.put_vol_delta  ?? r.put_volume_delta  ?? 0)
        const v = (pcrProvided !== null) ? pcrProvided : (c > 0 ? p / c : null)
        if (v !== null && Number.isFinite(v)) {
          labels.push(r.strike)
          vals.push(v)
        }
      }
      return { labels, datasets: [{ label: 'PCR (Live)', data: vals }] }
    },
    chartOptions() {
      return {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          x: { title: { display: true, text: 'Strike' } },
          y: { title: { display: true, text: 'PCR' } }
        }
      }
    }
  }
}
</script>
