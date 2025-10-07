<template>
  <Card title="Seasonality (5d)" :asOf="date ? `EOD: ${date}` : ''"
        subtitle="Avg forward returns next 5 sessions">
    <InfoTip
      tip="Computed from ±2d calendar window across many prior years (note shows depth). z compares 5-day average vs the unconditional 5-day distribution.">
      <span class="text-[11px] text-gray-400">Blend across years</span>
    </InfoTip>

    <div v-if="hasData" class="grid grid-cols-5 gap-2 text-center text-sm mt-3">
      <div v-for="(x,i) in [d1,d2,d3,d4,d5]" :key="i" class="bg-gray-700 rounded p-2">
        <div class="text-xs text-gray-400">D{{ i+1 }}</div>
        <div class="text-base">{{ pct(x) }}</div>
      </div>
    </div>
    <div v-else class="text-sm text-gray-500 mt-2">No seasonality data.</div>

    <div v-if="hasData" class="mt-3 flex items-center justify-between">
      <div class="text-sm">Cum 5d: <span class="font-semibold">{{ pct(cum5) }}</span></div>
      <span class="px-3 py-1 rounded-full text-xs" :class="badgeClass">{{ badgeText }}</span>
    </div>
    <div v-if="hasData" class="text-xs text-gray-400 mt-1">z: {{ num(z) }}</div>

    <div v-if="note" class="mt-3 text-[11px] text-gray-500"><span class="opacity-80">Note:</span> {{ note }}</div>

    <HowTo>
      <ul class="list-disc ml-4 space-y-1">
        <li><b>z ≥ +1</b>: mild bullish seasonal tailwind; lean long or reduce credit risk.</li>
        <li><b>z ≤ −1</b>: mild bearish bias; conservative strikes/sizing on longs, or favor bearish spreads.</li>
        <li>Best used as a tiebreaker with VRP/Term/Momentum—not standalone.</li>
      </ul>
      <p><b>Example:</b> Cum5 = +1.2%, z = +1.1 with positive VRP → bullish, but consider defined-risk spreads if momentum is weak.</p>
    </HowTo>
  </Card>
</template>

<script setup>
import { computed } from 'vue'
import Card from '../Components/Card.vue'
import InfoTip from '../Components/InfoTip.vue'
import HowTo from '../Components/HowTo.vue'
const props = defineProps({ date:String, d1:Number,d2:Number,d3:Number,d4:Number,d5:Number, cum5:Number, z:Number, note:String })
const hasData = computed(()=> [props.d1,props.d2,props.d3,props.d4,props.d5,props.cum5].some(v=>v!=null))
const pct = v => v==null ? '—' : `${(v*100).toFixed(1)}%`
const num = v => v==null ? '—' : v.toFixed(2)
const badgeText  = computed(()=> (props.z ?? 0) >= 1 ? 'Bullish' : ((props.z ?? 0) <= -1 ? 'Bearish' : 'Neutral'))
const badgeClass = computed(()=> (props.z ?? 0) >= 1 ? 'bg-green-700' : ((props.z ?? 0) <= -1 ? 'bg-red-700' : 'bg-gray-700'))
</script>
