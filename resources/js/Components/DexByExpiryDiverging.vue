<template>
  <div class="space-y-2">
    <div class="text-xs text-gray-400">DEX by expiry</div>

    <!-- Past -->
    <div class="space-y-1">
      <div v-for="r in past" :key="'p'+r.exp" class="flex items-center gap-2" :title="`${r.exp} • ${fmt(r.v)}`">
        <div class="w-14 text-[11px] text-gray-400">{{ shortDate(r.exp) }}</div>
        <div class="relative flex-1 h-3 bg-gray-700 rounded">
          <div class="absolute inset-y-0 left-1/2 w-px bg-gray-500/40"></div>
          <div class="absolute h-3 rounded" :class="r.v>=0 ? 'bg-green-400/80' : 'bg-red-400/80'" :style="barStyle(r.v)" />
        </div>
        <div class="w-20 text-right text-[11px] text-gray-300">{{ fmt(r.v) }}</div>
      </div>
    </div>

    <!-- Today marker -->
    <div class="flex items-center gap-2" v-if="todayR">
      <div class="w-14 text-[11px] text-blue-300 font-medium">Today</div>
      <div class="relative flex-1 h-4">
        <div class="absolute inset-0 border-t border-b border-blue-500/40 rounded"></div>
        <div class="absolute inset-y-0 left-1/2 w-px bg-blue-400/60"></div>
      </div>
      <div class="w-20 text-right text-[11px] text-blue-300 font-medium">{{ shortDate(todayR.exp) }}</div>
    </div>

    <!-- Today row (if you also want its DEX shown) -->
    <div v-if="todayR" class="flex items-center gap-2" :title="`${todayR.exp} • ${fmt(todayR.v)}`">
      <div class="w-14 text-[11px] text-gray-200">{{ shortDate(todayR.exp) }}</div>
      <div class="relative flex-1 h-3 bg-gray-700 rounded">
        <div class="absolute inset-y-0 left-1/2 w-px bg-gray-500/60"></div>
        <div class="absolute h-3 rounded ring-1 ring-blue-400/50"
             :class="todayR.v>=0 ? 'bg-green-400/90' : 'bg-red-400/90'"
             :style="barStyle(todayR.v)" />
      </div>
      <div class="w-20 text-right text-[11px] text-gray-200">{{ fmt(todayR.v) }}</div>
    </div>

    <!-- Future -->
    <div class="space-y-1">
      <div v-for="r in fut" :key="'f'+r.exp" class="flex items-center gap-2" :title="`${r.exp} • ${fmt(r.v)}`">
        <div class="w-14 text-[11px] text-gray-400">{{ shortDate(r.exp) }}</div>
        <div class="relative flex-1 h-3 bg-gray-700 rounded">
          <div class="absolute inset-y-0 left-1/2 w-px bg-gray-500/40"></div>
          <div class="absolute h-3 rounded" :class="r.v>=0 ? 'bg-green-400/60' : 'bg-red-400/60'" :style="barStyle(r.v)" />
        </div>
        <div class="w-20 text-right text-[11px] text-gray-300">{{ fmt(r.v) }}</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
const props = defineProps({ items: { type: Array, default: () => [] }, today: { type: String, default: '' } })

const rows = computed(() => (props.items || []).map(x => ({ exp: x.exp_date, v: Number(x.dex_total)||0 }))
  .sort((a,b) => a.exp.localeCompare(b.exp)))

const past   = computed(() => rows.value.filter(r => r.exp <  props.today))
const todayR = computed(() => rows.value.find( r => r.exp === props.today))
const fut    = computed(() => rows.value.filter(r => r.exp >  props.today))

const maxAbs = computed(() => Math.max(1e-6, ...rows.value.map(r => Math.abs(r.v))))
function barStyle(v){
  const pct = Math.min(50, (Math.abs(v)/maxAbs.value)*50)
  return v>=0 ? { left: '50%', width: pct+'%' } : { left: (50-pct)+'%', width: pct+'%' }
}
function shortDate(d){ if(!d) return ''; const p=d.split('-'); return p.length===3?`${p[1]}/${p[2]}`:d }
function fmt(n){ const s=Math.sign(n)<0?'-':''; const a=Math.abs(n); if(a>=1e9)return s+(a/1e9).toFixed(2)+'B'; if(a>=1e6)return s+(a/1e6).toFixed(2)+'M'; if(a>=1e3)return s+(a/1e3).toFixed(2)+'K'; return s+a.toFixed(0) }
</script>