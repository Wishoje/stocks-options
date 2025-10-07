
<template>
  <div class="bg-gray-800 rounded-2xl p-4">
    <div class="flex items-center justify-between mb-1">
      <h3 class="font-semibold">Seasonality (5d)</h3>
      <span class="text-xs text-gray-400" v-if="date">EOD: {{ date }}</span>
    </div>
    <div class="text-xs text-gray-400 mb-3">Avg forward returns for the next 5 sessions from past years (±{{ window }}d calendar window).</div>

    <div class="grid grid-cols-5 gap-2 text-center text-sm">
      <div v-for="(v,i) in [d1,d2,d3,d4,d5]" :key="i" class="bg-gray-700 rounded p-2">
        <div class="text-xs text-gray-400">D{{ i+1 }}</div>
        <div class="text-base">{{ fmtPct(v) }}</div>
      </div>
    </div>

    <div class="mt-3 flex items-center justify-between">
      <div class="text-sm">Cum 5d: <span class="font-semibold">{{ fmtPct(cum5) }}</span></div>
      <span class="px-3 py-1 rounded-full text-xs" :class="badgeClass"> {{ badgeText }} </span>
    </div>
    <div class="text-xs text-gray-400 mt-1">z: {{ fmtNum(z) }}</div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
const props = defineProps({ date:String, d1:Number,d2:Number,d3:Number,d4:Number,d5:Number, cum5:Number, z:Number, window:{type:Number,default:2} })
const fmtPct = v => (v ?? null) === null ? '—' : `${(v*100).toFixed(1)}%`
const fmtNum = v => (v ?? null) === null ? '—' : v.toFixed(2)
const badgeText = computed(()=> props.z>=1 ? 'Bullish' : (props.z<=-1 ? 'Bearish' : 'Neutral'))
const badgeClass = computed(()=> props.z>=1 ? 'bg-green-700' : (props.z<=-1 ? 'bg-red-700' : 'bg-gray-700'))
</script>
