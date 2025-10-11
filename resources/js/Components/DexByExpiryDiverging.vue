<template>
  <div class="space-y-2">
    <div class="text-xs text-gray-400">DEX by expiry</div>
    <div class="space-y-1">
      <div
        v-for="r in rows" :key="r.exp"
        class="flex items-center gap-2"
        :title="`${r.exp} • ${fmt(r.v)}`"
      >
        <div class="w-14 text-[11px] text-gray-400">{{ shortDate(r.exp) }}</div>
        <div class="relative flex-1 h-3 bg-gray-700 rounded">
          <!-- zero axis -->
          <div class="absolute inset-y-0 left-1/2 w-px bg-gray-500/40"></div>
          <!-- bar -->
          <div
            class="absolute h-3 rounded"
            :class="r.v>=0 ? 'bg-green-400/80' : 'bg-red-400/80'"
            :style="barStyle(r.v)"
          />
        </div>
        <div class="w-20 text-right text-[11px] text-gray-300">{{ fmt(r.v) }}</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({ items: { type: Array, default: () => [] } })

const rows = computed(() => {
  const copy = (props.items || []).map(x => ({ exp: x.exp_date, v: Number(x.dex_total)||0 }))
  return copy.sort((a,b) => a.exp.localeCompare(b.exp))
})
const maxAbs = computed(() => Math.max(1e-6, ...rows.value.map(r => Math.abs(r.v))))
function barStyle(v){
  // fill up to 50% on either side of the axis
  const pct = Math.min(50, (Math.abs(v)/maxAbs.value)*50)
  return v>=0
    ? { left: '50%', width: pct+'%' }
    : { left: (50 - pct)+'%', width: pct+'%' }
}
function fmt(x){
  const n = Number(x); if(!Number.isFinite(n)) return '—'
  const s = Math.sign(n)<0?'-':''; const a = Math.abs(n)
  if (a>=1e9) return s+(a/1e9).toFixed(2)+'B'
  if (a>=1e6) return s+(a/1e6).toFixed(2)+'M'
  if (a>=1e3) return s+(a/1e3).toFixed(2)+'K'
  return s+a.toFixed(0)
}
function shortDate(d){ if(!d) return ''; const p=d.split('-'); return p.length===3?`${p[1]}/${p[2]}`:d }
</script>
