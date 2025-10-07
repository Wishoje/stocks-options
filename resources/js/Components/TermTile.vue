<template>
  <Card title="Term Structure" :asOf="date ? `EOD: ${date}` : ''"
        subtitle="Implied vol across expiries (near→far)">
    <InfoTip tip="Upward sloping = contango (front IV < back IV): tends to damp moves; selling premium / calendars easier.
Downward sloping = backwardation: stress regime; prefer long options or defined-risk.">
      <span class="text-[11px] text-gray-400">Up = contango</span>
    </InfoTip>

    <div v-if="items?.length" class="h-28 mt-3"><canvas ref="canvas"></canvas></div>
    <div v-else class="text-sm text-gray-500">No term data.</div>

    <HowTo>
      <p><b>Quick read:</b> Compare front-month IV vs 2–3 months out.</p>
      <ul class="list-disc ml-4 space-y-1">
        <li><b>Contango</b>: calendars/diagonals collect carry; short premium safer.</li>
        <li><b>Backwardation</b>: expect movement; own gamma (long straddles/strangles) or tight defined risk.</li>
      </ul>
      <p><b>Example:</b> 1M IV 20% → 3M IV 24% (upward). Pair with positive VRP → iron condor OK.</p>
    </HowTo>
  </Card>
</template>

<script setup>
import Card from '../Components/Card.vue'
import InfoTip from '../Components/InfoTip.vue'
import HowTo from '../Components/HowTo.vue'
import { onMounted, onBeforeUnmount, watch, ref, computed } from 'vue'
import Chart from 'chart.js/auto'

const props = defineProps({ items:{type:Array,default:()=>[]}, date:String })
const canvas = ref(null); let chart
const labels = computed(()=> props.items.map(i=>i.exp.slice(5)))
const ivData = computed(()=> props.items.map(i => (i.iv ?? 0) * 100))
function draw(){
  if (!canvas.value) return
  const existing = Chart.getChart(canvas.value); if (existing) existing.destroy()
  chart?.destroy()
  chart = new Chart(canvas.value.getContext('2d'), {
    type:'line',
    data:{ labels:labels.value, datasets:[{ data:ivData.value, fill:false, tension:0.25 }] },
    options:{ plugins:{ legend:{display:false}, tooltip:{ callbacks:{ label:c=>`${c.parsed.y.toFixed(2)}%`}} },
      scales:{ y:{ title:{ display:true,text:'IV %'} } } }
  })
}
onMounted(draw); watch(()=>props.items, draw); onBeforeUnmount(()=>chart?.destroy())
</script>
