<!-- components/UnusualActivityTable.vue -->
<template>
  <div class="bg-gray-700 rounded p-4">
    <div class="flex items-center justify-between mb-3">
      <h4 class="font-semibold">Unusual Activity</h4>
      <div class="text-xs text-gray-300" v-if="dataDate">Data: {{ dataDate }}</div>
    </div>

    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="text-gray-300">
          <tr>
            <th class="text-left pb-2 cursor-pointer" @click="sortBy('exp_date')">Expiry</th>
            <th class="text-left pb-2">Strike</th>
            <th class="text-right pb-2 cursor-pointer" @click="sortBy('z_score')">Z-Score</th>
            <th class="text-right pb-2 cursor-pointer" @click="sortBy('vol_oi')">Vol/OI</th>
            <th class="text-right pb-2">Total Vol</th>
            <th class="text-right pb-2">Action</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in sorted" :key="row.exp_date + '-' + row.strike" class="border-t border-gray-600/50">
            <td class="py-2">{{ row.exp_date }}</td>
            <td>{{ row.strike }}</td>
            <td class="text-right font-semibold" :class="row.z_score>=4?'text-red-300':'text-amber-300'">
              {{ row.z_score.toFixed(2) }}
            </td>
            <td class="text-right">{{ row.vol_oi.toFixed(2) }}</td>
            <td class="text-right">{{ (row.meta?.total_vol ?? 0).toLocaleString() }}</td>
            <td class="text-right">
              <!-- link to your strategy builder; adjust route/query as needed -->
              <RouterLink class="text-blue-300 underline"
                          :to="`/strategy?symbol=${symbol}&exp=${row.exp_date}&strike=${row.strike}`">
                Build
              </RouterLink>
            </td>
          </tr>
          <tr v-if="!sorted.length">
            <td colspan="6" class="py-6 text-center text-gray-400">No flags for the selected filters.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
const props = defineProps({
  rows: { type: Array, default: () => [] },
  dataDate: { type: String, default: null },
  symbol: { type: String, required: true }
})
const key = ref('z_score'); const dir = ref('desc')
function sortBy(k){ key.value = k; dir.value = (dir.value==='asc'?'desc':'asc') }
const sorted = computed(()=>{
  const arr = [...props.rows]
  arr.sort((a,b)=>{
    const av=a[key.value], bv=b[key.value]
    if (av===bv) return 0
    return dir.value==='asc' ? (av<bv?-1:1) : (av>bv?-1:1)
  })
  return arr
})
</script>
