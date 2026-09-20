<script setup>
import { computed, ref } from 'vue'
const props = defineProps({ caption: { type: String, required: true }, rows: { type: Array, required: true }, columns: { type: Array, required: true }, rowKey: { type: String, default: 'id' } })
const sortKey = ref(null)
const direction = ref('ascending')
function sort(column) {
  if (!column.sortable) return
  direction.value = sortKey.value === column.key && direction.value === 'ascending' ? 'descending' : 'ascending'
  sortKey.value = column.key
}
const sorted = computed(() => {
  const copy = [...props.rows]
  if (!sortKey.value) return copy
  const column = props.columns.find(c => c.key === sortKey.value)
  return copy.sort((a,b) => {
    const av = a[sortKey.value], bv = b[sortKey.value]
    if (av == null || av === '') return bv == null || bv === '' ? 0 : 1
    if (bv == null || bv === '') return -1
    const result = column?.numeric ? Number(av) - Number(bv) : String(av).localeCompare(String(bv))
    return result * (direction.value === 'ascending' ? 1 : -1)
  })
})
function display(value, column) { return value == null || value === '' ? 'Unavailable' : column.format ? column.format(value) : value }
</script>
<template>
  <div class="gex-table-scroll" role="region" :aria-label="caption" tabindex="0">
    <table class="gex-table"><caption>{{ caption }} · {{ rows.length }} readings</caption>
      <thead><tr><th v-for="column in columns" :key="column.key" scope="col" :data-numeric="!!column.numeric" :aria-sort="column.sortable ? (sortKey === column.key ? direction : 'none') : undefined"><button v-if="column.sortable" type="button" class="gex-sort" @click="sort(column)">{{ column.label }} <span aria-hidden="true">{{ sortKey === column.key ? (direction === 'ascending' ? '↑' : '↓') : '↕' }}</span></button><template v-else>{{ column.label }}</template></th></tr></thead>
      <tbody><tr v-for="(row,index) in sorted" :key="row[rowKey] ?? index"><td v-for="column in columns" :key="column.key" :data-numeric="!!column.numeric">{{ display(row[column.key],column) }}</td></tr><tr v-if="!rows.length"><td :colspan="columns.length">No readings for this selection.</td></tr></tbody>
    </table>
  </div>
</template>
