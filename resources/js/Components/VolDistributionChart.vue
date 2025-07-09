<template>
  <Pie :data="chartData" :options="chartOptions" />
</template>

<script>
import { Pie } from 'vue-chartjs'
import {
  Chart, ArcElement, Tooltip, Legend
} from 'chart.js'
Chart.register(ArcElement, Tooltip, Legend)

export default {
  name: 'VolDistributionChart',
  components: { Pie },
  props: {
    callVol: { type: Number, required: true },
    putVol:  { type: Number, required: true },
  },
  computed: {
    chartData() {
      return {
        labels: ['Call Vol', 'Put Vol'],
        datasets: [{
          data: [this.callVol, this.putVol],
          backgroundColor: ['rgba(75,192,192,0.6)', 'rgba(255,99,132,0.6)']
        }]
      }
    },
    chartOptions() {
      return {
        responsive: true,
        plugins: { legend: { position: 'bottom' } }
      }
    }
  }
}
</script>
