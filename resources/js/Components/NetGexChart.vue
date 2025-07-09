<template>
  <Bar :data="chartData" :options="chartOptions" />
</template>

<script>
import { Bar } from 'vue-chartjs'
import {
  Chart, BarElement, CategoryScale, LinearScale, Tooltip, Legend
} from 'chart.js'
Chart.register(BarElement, CategoryScale, LinearScale, Tooltip, Legend)

export default {
  name: 'NetGexChart',
  components: { Bar },
  props: { strikeData: Array },
  computed: {
    chartData() {
      return {
        labels: this.strikeData.map(r => r.strike),
        datasets: [
          {
            label: 'Net GEX',
            data: this.strikeData.map(r => r.net_gex),
            backgroundColor: 'rgba(100,150,250,0.6)'
          }
        ]
      }
    },
    chartOptions() {
      return {
        responsive: true,
        scales: {
          x: { title: { display: true, text: 'Strike' } },
          y: { title: { display: true, text: 'Net GEX' } }
        }
      }
    }
  }
}
</script>
