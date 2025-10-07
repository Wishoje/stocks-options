<template>
  <div class="bg-gray-800 rounded-2xl p-4 ring-1 ring-white/5">
    <div class="flex items-center justify-between mb-2">
      <div class="flex items-center gap-2">
        <h3 class="font-semibold">Seasonality (5d)</h3>
        <span class="text-[11px] text-gray-400">Avg forward returns next 5 sessions</span>
      </div>
      <span class="text-xs text-gray-400" v-if="date">EOD: {{ date }}</span>
    </div>

    <div v-if="hasData" class="grid grid-cols-5 gap-2 text-center text-sm">
      <div v-for="(x,i) in [d1,d2,d3,d4,d5]" :key="i" class="bg-gray-700 rounded p-2">
        <div class="text-xs text-gray-400">D{{ i+1 }}</div>
        <div class="text-base">{{ pct(x) }}</div>
      </div>
    </div>
    <div v-else class="text-sm text-gray-500">No seasonality data.</div>

    <div v-if="hasData" class="mt-3 flex items-center justify-between">
      <div class="text-sm">Cum 5d: <span class="font-semibold">{{ pct(cum5) }}</span></div>
      <span class="px-3 py-1 rounded-full text-xs" :class="badgeClass">{{ badgeText }}</span>
    </div>
    <div v-if="hasData" class="text-xs text-gray-400 mt-1">z: {{ num(z) }}</div>

    <div class="mt-3 text-[11px] text-gray-500" v-if="note">
      <span class="opacity-80">Note:</span> {{ note }}
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  date:String,
  d1:Number, d2:Number, d3:Number, d4:Number, d5:Number,
  cum5:Number, z:Number,
  note:{ type:String, default:'' }
})

const hasData   = computed(() => [props.d1,props.d2,props.d3,props.d4,props.d5,props.cum5]
  .some(v => v !== null && v !== undefined))

const pct = x => (x ?? null) === null ? '—' : `${(x*100).toFixed(1)}%`
const num = x => (x ?? null) === null ? '—' : x.toFixed(2)
const badgeText  = computed(()=> (props.z ?? 0) >= 1 ? 'Bullish' : ((props.z ?? 0) <= -1 ? 'Bearish' : 'Neutral'))
const badgeClass = computed(()=> (props.z ?? 0) >= 1 ? 'bg-green-700' : ((props.z ?? 0) <= -1 ? 'bg-red-700' : 'bg-gray-700'))
</script>
