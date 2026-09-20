<script setup>
import { computed } from 'vue'
import { compact, dateLabel, numeric } from './numbers.js'
const props = defineProps({ items: { type: Array, required: true }, modelValue: String, unit: { type: String, default:'share equivalents' } })
const emit = defineEmits(['update:modelValue', 'reading-inspected'])
const bound = computed(() => Math.max(1,...props.items.map(row => Math.abs(numeric(row.value) ?? 0))))
const available = computed(() => props.items.filter(row => numeric(row.value) != null).length)
const activeIndex = computed(() => Math.max(0,props.items.findIndex(row => row.date === props.modelValue)))
function bar(value) { const n = numeric(value) ?? 0; const width = Math.abs(n) / bound.value * 50; return { width:`${width}%`, left:`${n < 0 ? 50 - width : 50}%` } }
function rowLabel(row) { const n=numeric(row.value); if(n==null) return `${row.date}: unavailable`; const direction=n<0?'negative':n>0?'positive':'zero'; const exact=`${n>0?'+':''}${n.toLocaleString('en-US')}`; return `${row.date}: ${exact} ${props.unit}, ${direction}` }
function inspect(index) { const row=props.items[index]; if(!row) return; emit('update:modelValue',row.date); if(numeric(row.value)!=null) emit('reading-inspected') }
function move(event,index) { let next; if(event.key==='ArrowDown'||event.key==='ArrowRight') next=(index+1)%props.items.length; else if(event.key==='ArrowUp'||event.key==='ArrowLeft') next=(index-1+props.items.length)%props.items.length; else if(event.key==='Home') next=0; else if(event.key==='End') next=props.items.length-1; else return; event.preventDefault(); inspect(next); event.currentTarget.parentElement.querySelectorAll('.gex-exposure-row')[next]?.focus() }
</script>
<template>
  <div class="gex-exposure-chart">
    <div class="gex-chart-heading">
      <div><h3>Signed expiry exposure</h3><p>{{ items.length }} expiry readings · {{ available }} available</p></div>
      <span class="gex-chart-scale gex-number">Symmetric scale ±{{ compact(bound).replace('+','') }}</span>
    </div>
    <div class="gex-legend" aria-label="Exposure legend"><span><i class="gex-swatch" data-negative="true" aria-hidden="true"></i>Negative</span><span><i class="gex-swatch" aria-hidden="true"></i>Positive</span><span><i class="gex-swatch" data-zero="true" aria-hidden="true"></i>Zero baseline</span></div>
    <p class="gex-small gex-muted gex-chart-unit">DEX in {{ unit }} · equal distance from center means equal magnitude</p>
    <div v-if="items.length" class="gex-exposure-axis" aria-hidden="true"><span></span><span class="gex-axis-track"><i>{{ compact(-bound) }}</i><i>0</i><i>{{ compact(bound) }}</i></span><span></span></div>
    <div role="group" aria-label="Delta exposure by expiry">
      <button v-for="(row,rowIndex) in items" :key="row.date" type="button" class="gex-exposure-row" :tabindex="rowIndex === activeIndex ? 0 : -1" :aria-pressed="modelValue === row.date" :aria-label="rowLabel(row)" @click="inspect(rowIndex)" @keydown="move($event,rowIndex)">
        <span>{{ dateLabel(row.date) }}</span><span class="gex-track" :data-missing="numeric(row.value) == null || undefined" aria-hidden="true"><i v-if="numeric(row.value) === 0" class="gex-zero"></i><i v-else-if="numeric(row.value) != null" class="gex-bar" :data-negative="numeric(row.value) < 0" :style="bar(row.value)"></i><i v-else class="gex-missing">No reading</i></span><span>{{ compact(row.value) }}</span>
      </button>
      <p v-if="!items.length" class="gex-muted">No expiry readings for this selection.</p>
    </div>
  </div>
</template>
