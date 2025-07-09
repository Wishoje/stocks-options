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
  name: 'OiDistributionChart',
  components: { Pie },
  props: {
    callOi: { type: Number, required: true },
    putOi:  { type: Number, required: true },
  },
  computed: {
    chartData() {
      return {
        labels: ['Call OI', 'Put OI'],
        datasets: [{
          data: [this.callOi, this.putOi],
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
