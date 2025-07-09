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
  name: 'VolumeDeltaChart',
  components: { Bar },
  props: { strikeData: Array },
  computed: {
    chartData() {
      return {
        labels: this.strikeData.map(r => r.strike),
        datasets: [
          {
            label: 'Call ΔVol',
            data: this.strikeData.map(r => r.call_vol_delta),
            backgroundColor: 'rgba(75,192,192,0.6)'
          },
          {
            label: 'Put ΔVol',
            data: this.strikeData.map(r => r.put_vol_delta),
            backgroundColor: 'rgba(255,99,132,0.6)'
          }
        ]
      }
    },
    chartOptions() {
      return {
        responsive: true,
        scales: {
          x: { title: { display: true, text: 'Strike' } },
          y: { title: { display: true, text: 'ΔVolume' } }
        }
      }
    }
  }
}
</script>
