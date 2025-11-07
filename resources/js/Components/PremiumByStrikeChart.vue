<template><Bar :data="chartData" :options="chartOptions" /></template>
<script>
import { Bar } from 'vue-chartjs'
import { Chart, BarElement, CategoryScale, LinearScale, Tooltip, Legend } from 'chart.js'
Chart.register(BarElement, CategoryScale, LinearScale, Tooltip, Legend)

export default {
  name: 'PremiumByStrikeChart',
  components: { Bar },
  props: { strikeData: Array },
  computed: {
    chartData() {
      const labels=[], calls=[], puts=[]
      for (const r of this.strikeData || []) {
        labels.push(r.strike)
        calls.push(Number(r.premium_call || 0))
        puts.push(Number(r.premium_put  || 0))
      }
      return { labels, datasets: [
        { label: 'Call Premium ($)', data: calls, stack: 'prem' },
        { label: 'Put Premium ($)',  data: puts,  stack: 'prem' },
      ] }
    },
    chartOptions() { return { responsive:true, maintainAspectRatio:false,
      scales:{ x:{ stacked:true, title:{ display:true, text:'Strike' } },
               y:{ stacked:true, title:{ display:true, text:'Notional ($)' } } } } }
  }
}
</script>
