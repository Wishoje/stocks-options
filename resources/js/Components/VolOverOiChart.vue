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
        const oi = Number(r.oi_call_eod || 0) + Number(r.oi_put_eod || 0)
        const vol = Number(r.call_vol_delta || 0) + Number(r.put_vol_delta || 0)
        if (oi > 0) { labels.push(r.strike); vals.push(vol / oi) }
      }
      return { labels, datasets: [{ label: 'Vol/OI (Live)', data: vals }] }
    },
    chartOptions() { return { responsive:true, maintainAspectRatio:false, plugins:{ legend:{ display:true } },
      scales:{ x:{ title:{ display:true, text:'Strike' } }, y:{ title:{ display:true, text:'Vol/OI' } } } } }
  }
}
</script>
