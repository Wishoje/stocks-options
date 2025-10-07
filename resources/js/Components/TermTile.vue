
<template>
  <div class="bg-gray-800 rounded-2xl p-4">
    <div class="flex items-center justify-between mb-2">
      <h3 class="font-semibold">Term</h3>
      <span class="text-xs text-gray-400" v-if="date">EOD: {{ date }}</span>
    </div>
    <div class="text-xs text-gray-400 mb-2">IV across expiries (near → far). Up = contango (easier to sell premium).</div>

    <div v-if="items && items.length" class="h-24">
      <canvas ref="canvas"></canvas>
    </div>
    <div v-else class="text-sm text-gray-500">No term data.</div>
  </div>
</template>

<script setup>
import { onMounted, onBeforeUnmount, watch, ref, computed } from 'vue'
import { Chart, LineElement, PointElement, CategoryScale, LinearScale, Tooltip } from 'chart.js'
Chart.register(LineElement, PointElement, CategoryScale, LinearScale, Tooltip)

const props = defineProps({
  items: { type: Array, default: () => [] }, // [{exp, iv}]
  date:  { type: String, default: null }
})

const canvas = ref(null)
let chart

const labels  = computed(() => props.items.map(i => i.exp.slice(5)))       // 'MM-DD'
const ivData  = computed(() => props.items.map(i => (i.iv ?? 0) * 100))    // %

function draw () {
  if (!canvas.value) return
  if (chart) chart.destroy()
  chart = new Chart(canvas.value.getContext('2d'), {
    type: 'line',
    data: { labels: labels.value, datasets: [{ data: ivData.value, fill: false, tension: 0.25 }] },
    options: {
      responsive: true,
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => `${c.parsed.y.toFixed(2)}%` } } },
      scales: { x: { ticks: { maxTicksLimit: 6 } }, y: { title: { display: true, text: 'IV %' } } }
    }
  })
}

onMounted(draw)
watch(() => props.items, draw) // reference changes trigger redraw
onBeforeUnmount(() => { if (chart) chart.destroy() })

</script>