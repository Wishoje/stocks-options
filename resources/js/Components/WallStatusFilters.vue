<script setup>
import { WALL_STATUS_FILTERS, filterWallRows } from '@/Support/wallLifecycle'
const props = defineProps({ rows: { type: Array, default: () => [] }, modelValue: { type: String, default: 'all' }, symbol: { type: String, default: '' }, direction: { type: String, default: 'all' }, unread: Boolean, inbox: Boolean })
defineEmits(['update:modelValue', 'update:symbol', 'update:direction', 'update:unread'])
const count = key => filterWallRows(props.rows, { symbol: props.symbol, direction: props.direction, unread: props.unread, status: key }).length
</script>
<template>
  <div class="wall-status-filters">
    <div class="wall-status-search"><label>Symbol<input :value="symbol" type="search" placeholder="Search symbols" maxlength="16" @input="$emit('update:symbol', $event.target.value)"></label>
      <label>Direction<select :value="direction" @change="$emit('update:direction', $event.target.value)"><option value="all">Both directions</option><option value="bullish">Bullish</option><option value="bearish">Bearish</option></select></label>
      <label v-if="inbox" class="wall-unread"><input type="checkbox" :checked="unread" @change="$emit('update:unread', $event.target.checked)">Unread only</label></div>
    <div class="filters" role="group" aria-label="Setup status filter"><button v-for="[key, label] in WALL_STATUS_FILTERS" :key="key" type="button" :aria-pressed="modelValue === key" @click="$emit('update:modelValue', key)">{{ label }} · {{ count(key) }}</button></div>
  </div>
</template>
<style scoped>
.wall-status-filters{margin:16px 0}.wall-status-search,.filters{display:flex;align-items:end;gap:9px;flex-wrap:wrap}.wall-status-search{margin-bottom:10px}.wall-status-search label{display:grid;gap:5px;font-size:.72rem;color:#becce0}.wall-status-search input[type=search],select{background:#1a2434;color:#eef4fc;border:1px solid #536382;border-radius:7px;font-size:.8rem;padding:8px 10px;width:170px;max-width:100%;min-height:36px}.wall-status-search .wall-unread{display:flex;align-items:center;min-height:36px;gap:7px}.filters button{border:1px solid #536382;border-radius:7px;padding:7px 10px;background:#253148;color:#dce9fa;font-size:.75rem;cursor:pointer;min-height:36px}.filters button[aria-pressed=true]{background:#3c4b70;border-color:#b5bcff;color:#fff}button:focus-visible,input:focus-visible,select:focus-visible{outline:2px solid #b5bcff;outline-offset:3px}@media(max-width:520px){.wall-status-search label{flex:1;min-width:120px}.wall-status-search input[type=search],select{width:100%}}
</style>
