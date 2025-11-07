<template><Bar :data="chartData" :options="chartOptions" /></template>
<script>
import { Bar } from 'vue-chartjs'
import { Chart, BarElement, CategoryScale, LinearScale, Tooltip, Legend } from 'chart.js'
Chart.register(BarElement, CategoryScale, LinearScale, Tooltip, Legend)

export default {
  name: 'PcrByStrikeChart',
  components: { Bar },
  props: { strikeData: Array },
  computed: {
    chartData() {
      const labels=[], vals=[]
      for (const r of this.strikeData || []) {
        const c = Number(r.call_vol_delta || 0), p = Number(r.put_vol_delta || 0)
        const v = c > 0 ? p / c : null
        if (v !== null) { labels.push(r.strike); vals.push(v) }
      }
      return { labels, datasets: [{ label: 'PCR (Live)', data: vals }] }
    },
    chartOptions() { return { responsive:true, maintainAspectRatio:false,
      scales:{ x:{ title:{ display:true, text:'Strike' } }, y:{ title:{ display:true, text:'PCR' } } } } }
  }
}
</script>
