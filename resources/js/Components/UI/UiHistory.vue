<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import UiDataTable from './UiDataTable.vue'
import { numeric, dateLabel } from './numbers.js'
const props = defineProps({ title: { type:String, default:'Skew history' }, items:{ type:Array, required:true }, unit:{ type:String, default:'pp' }, scope:String, scopeKey:[String,Number], explanation:String })
const emit = defineEmits(['reading-inspected'])
const host = ref(null), width = ref(360), index = ref(0), selectedDate = ref(null)
let observer
watch([() => props.items, () => props.scopeKey], ([items, scopeKey], previous) => {
  const scopeChanged = previous && scopeKey !== previous[1]
  const retained = scopeChanged ? -1 : items.findIndex(row => row.date === selectedDate.value)
  index.value = retained >= 0 ? retained : Math.max(0, items.length - 1)
  selectedDate.value = items[index.value]?.date ?? null
}, { immediate:true })
watch(index, value => { selectedDate.value = props.items[value]?.date ?? null })
onMounted(() => { if (typeof ResizeObserver !== 'undefined') { observer = new ResizeObserver(entries => { width.value = Math.max(220,entries[0].contentRect.width) }); observer.observe(host.value) } })
onBeforeUnmount(() => observer?.disconnect())
const range = computed(() => { const values = props.items.map(r=>numeric(r.value)).filter(v=>v!=null); const lo=Math.min(0,...values), hi=Math.max(0,...values), pad=Math.max((hi-lo)*.12,.1); return { lo:lo-pad, hi:hi+pad } })
const x = i => props.items.length <= 1 ? 52+(width.value-76)/2 : 52 + i / (props.items.length-1) * (width.value-76)
const y = value => 170 - (value-range.value.lo)/(range.value.hi-range.value.lo)*140
const path = computed(() => { let previous=false; return props.items.map((row,i)=>{const n=numeric(row.value); if(n==null){previous=false;return ''} const command=previous?'L':'M';previous=true;return `${command}${x(i)},${y(n)}`}).join(' ') })
const selected = computed(() => props.items[index.value])
const selectedValue = computed(() => numeric(selected.value?.value))
const available = computed(() => props.items.filter(row => numeric(row.value) != null).length)
const latestIndex = computed(() => {
  for (let itemIndex = props.items.length - 1; itemIndex >= 0; itemIndex -= 1) {
    if (numeric(props.items[itemIndex]?.value) != null) return itemIndex
  }
  return -1
})
const latestValue = computed(() => latestIndex.value >= 0 ? numeric(props.items[latestIndex.value]?.value) : null)
const ticks = computed(() => Array.from({length:4},(_,index)=>range.value.lo+(range.value.hi-range.value.lo)*index/3))
const columns = computed(() => [{key:'date',label:'Snapshot date',sortable:true},{key:'value',label:`Skew (${props.unit})`,numeric:true,sortable:true,format:axis}])
function axis(value) { if(value===0) return '0'; const magnitude=Math.abs(value); const label=magnitude>=10?magnitude.toFixed(0):magnitude>=1?magnitude.toFixed(1):String(Number(magnitude.toPrecision(2))); return value>0?`+${label}`:`−${label}` }
function inspect(event) { const next=Number(event?.target?.value); if(Number.isInteger(next)&&numeric(props.items[next]?.value)!=null) emit('reading-inspected') }
const selectedAria = computed(() => selected.value ? `${selected.value.date}, ${selectedValue.value == null ? 'unavailable' : `${axis(selectedValue.value)} ${props.unit}`}` : 'No history readings')
</script>
<template>
  <section :aria-label="title"><h3>{{ title }}</h3><div class="gex-history" style="margin-top:14px">
    <div ref="host"><div class="gex-chart-heading"><div><h3>Rolling expiry history</h3><p>{{ scope }} · {{ available }} of {{ items.length }} available</p></div><output class="gex-chart-value gex-number" aria-live="polite"><span>{{ selected ? selected.date : 'No date' }}</span><strong>{{ selectedValue == null ? 'Unavailable' : `${axis(selectedValue)} ${unit}` }}</strong></output></div>
      <div class="gex-legend" aria-label="History chart legend"><span><i class="gex-line-swatch" aria-hidden="true"></i>Skew</span><span><i class="gex-swatch" data-zero="true" aria-hidden="true"></i>Zero baseline</span><span><i class="gex-gap-swatch" aria-hidden="true"></i>Missing reading</span></div>
      <svg class="gex-history-chart" :viewBox="`0 0 ${width} 214`" role="img" :aria-label="`${scope || 'History'}; ${items.length} dates and ${available} available readings; values in ${unit}; zero is marked. Daily values are available with the date control and table.`">
        <template v-if="available"><template v-for="tick in ticks" :key="tick"><line class="gex-chart-grid" x1="52" :x2="width-24" :y1="y(tick)" :y2="y(tick)"/><text x="44" :y="y(tick)+4" text-anchor="end">{{ axis(tick) }}</text></template>
        <line class="gex-chart-zero" x1="52" :x2="width-24" :y1="y(0)" :y2="y(0)"/>
        <text class="gex-chart-zero-label" :x="width-26" :y="y(0)-6" text-anchor="end">zero</text><text x="52" y="18">{{ unit }}</text><path class="gex-history-line" :d="path"/>
        <template v-for="(row,rowIndex) in items" :key="row.date"><circle v-if="numeric(row.value) != null" class="gex-history-dot" :cx="x(rowIndex)" :cy="y(numeric(row.value))" r="2"/></template></template>
        <g v-else class="gex-chart-empty"><text :x="width/2" y="96" text-anchor="middle">{{ items.length ? 'Readings unavailable for these dates' : 'No history readings' }}</text><text :x="width/2" y="116" text-anchor="middle">History rows remain available below</text></g>
        <circle v-if="latestValue != null" class="gex-history-latest-halo" :cx="x(latestIndex)" :cy="y(latestValue)" r="9"/>
        <circle v-if="latestValue != null" class="gex-history-latest-point" :cx="x(latestIndex)" :cy="y(latestValue)" r="3"/>
        <line v-if="selectedValue != null" class="gex-history-guide" :x1="x(index)" :x2="x(index)" y1="26" y2="170"/>
        <circle v-if="selectedValue != null" class="gex-history-point-halo" :cx="x(index)" :cy="y(selectedValue)" r="8"/>
        <circle v-if="selectedValue != null" class="gex-history-point" :cx="x(index)" :cy="y(selectedValue)" r="4"/>
        <text v-if="items.length" x="52" y="202">{{ dateLabel(items[0].date) }}</text><text v-if="items.length>1" :x="width-24" y="202" text-anchor="end">{{ dateLabel(items.at(-1).date) }}</text>
      </svg>
      <label class="gex-history-scrubber"><span>Inspect date <small>Keyboard: arrow keys</small></span><input v-model.number="index" type="range" min="0" :max="Math.max(0,items.length-1)" :disabled="!items.length" aria-label="History date" :aria-valuetext="selectedAria" @input="inspect"/></label>
      <details data-testid="history-readings-disclosure"><summary>All {{ items.length }} daily readings</summary><UiDataTable caption="Daily history values" :rows="items" :columns="columns" row-key="date"/></details>
    </div>
    <details class="gex-explanation gex-calculation-details" data-testid="history-calculation-disclosure">
      <summary><strong>Calculation details</strong></summary>
      <div class="gex-calculation-details__body"><p>{{ explanation }}</p><slot name="calculation"/></div>
    </details>
  </div></section>
</template>
