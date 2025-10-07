<template>
  <div class="bg-gray-800 rounded-2xl p-4">
    <div class="flex items-center justify-between mb-1">
      <h3 class="font-semibold">VRP</h3>
      <span class="text-xs text-gray-400" v-if="date">EOD: {{ date }}</span>
    </div>

    <div class="text-xs text-gray-400 mb-3 flex items-center gap-2">
      IV(1M) − RV(20). Positive = IV rich.
      <span class="text-gray-500 cursor-help" title="IV(1M): ATM implied vol near 21 trading days out. RV(20): past 20d realized (annualized). VRP positive often favors selling premium; negative favors buying.">ⓘ</span>
    </div>


    <div class="grid grid-cols-3 gap-3 text-center">
      <div>
        <div class="text-xs text-gray-400">IV(1M)</div>
        <div class="text-lg">{{ fmtPct(iv1m) }}</div>
      </div>
      <div>
        <div class="text-xs text-gray-400">RV(20)</div>
        <div class="text-lg">{{ fmtPct(rv20) }}</div>
      </div>
      <div>
        <div class="text-xs text-gray-400">VRP</div>
        <div class="text-lg">{{ fmtPct(vrp) }}</div>
      </div>
    </div>

    <div class="mt-3 text-center">
      <span
        class="px-3 py-1 rounded-full text-sm"
        :class="badgeClass"
        :title="hint"
      >{{ badgeText }}</span>
      <div class="text-xs text-gray-400 mt-1">z: {{ fmtNum(z) }}</div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  date: String,
  iv1m: Number,
  rv20: Number,
  vrp:  Number,
  z:    Number
})

const fmtPct = v => (v ?? null) === null ? '—' : `${(v*100).toFixed(1)}%`
const fmtNum = v => (v ?? null) === null ? '—' : v.toFixed(2)

const badgeText = computed(() => {
  if (props.z >= 1) return 'SELL premium'
  if (props.z <= -1) return 'BUY premium'
  return 'Neutral'
})
const badgeClass = computed(() => {
  if (props.z >= 1)  return 'bg-green-700'
  if (props.z <= -1) return 'bg-blue-700'
  return 'bg-gray-700'
})
const hint = computed(() => {
  if (props.z >= 1)  return 'IV rich vs realized → short credit (condors/puts/calendars).'
  if (props.z <= -1) return 'IV cheap vs realized → long debit (calls/puts/diagonals).'
  return 'Little edge; be selective on structure.'
})
</script>
