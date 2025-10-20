<!-- components/UnusualActivityTable.vue -->
<template>
  <div class="bg-gray-700 rounded p-4">
    <div class="flex items-center justify-between mb-3">
      <h4 class="font-semibold">Unusual Activity</h4>
      <div class="text-xs text-gray-300" v-if="dataDate">Data: {{ dataDate }}</div>
    </div>

    <p class="text-xs text-gray-300 mb-3 leading-relaxed">
      Flags strikes where today’s total volume (calls+puts) is abnormally high vs its own 30-day history.<br>
      <span class="opacity-75">
        <b>Z-Score</b>: deviations above 30-day mean (winsorized). 
        <b>Vol/OI</b>: today’s volume ÷ current open interest.
      </span>
    </p>

    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="text-gray-300">
          <tr>
            <th class="text-left pb-2 cursor-pointer" @click="sortBy('exp_date')">Expiry</th>
            <th class="text-left pb-2">Strike</th>
            <th class="text-right pb-2 cursor-pointer" @click="sortBy('z_score')">
              Z-Score
              <span class="ml-1 text-xs opacity-70" title="How many std dev above baseline volume">ⓘ</span>
            </th>
            <th class="text-right pb-2 cursor-pointer" @click="sortBy('vol_oi')">
              Vol/OI
              <span class="ml-1 text-xs opacity-70" title="Today volume divided by open interest">ⓘ</span>
            </th>
            <th class="text-right pb-2">Total Vol</th>
            <th class="text-right pb-2">Premium ($)</th>
            <th class="text-right pb-2">Action</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="row in sorted"
            :key="row.exp_date + '-' + row.strike"
            class="border-t border-gray-600/50"
          >
            <td class="py-2">{{ row.exp_date }}</td>
            <td>
              {{ row.strike }}
              <span v-if="(row.meta?.call_vol ?? 0) > (row.meta?.put_vol ?? 0)"
                    class="ml-2 text-[11px] px-1.5 py-0.5 rounded bg-emerald-500/15 text-emerald-300">
                Call-led
              </span>
              <span v-else-if="(row.meta?.put_vol ?? 0) > (row.meta?.call_vol ?? 0)"
                    class="ml-2 text-[11px] px-1.5 py-0.5 rounded bg-rose-500/15 text-rose-300">
                Put-led
              </span>
            </td>

            <td class="text-right font-semibold" :class="row.z_score>=4?'text-red-300':'text-amber-300'">
              {{ row.z_score.toFixed(2) }}
            </td>

            <td class="text-right" :class="row.vol_oi>=1 ? 'text-emerald-300 font-medium' : ''">
              {{ row.vol_oi.toFixed(2) }}
            </td>

            <td class="text-right">{{ (row.meta?.total_vol ?? 0).toLocaleString() }}</td>

            <td class="text-right">
              <span v-if="row.meta?.premium_usd != null" :title="premTooltip(row)">
                {{ formatCurrency(row.meta.premium_usd) }}
              </span>
              <span v-else class="text-gray-400">—</span>
            </td>

            <td class="text-right">
              <RouterLink
                class="text-blue-300 underline"
                :to="`/strategy?symbol=${symbol}&exp=${row.exp_date}&strike=${row.strike}`"
              >
                Build
              </RouterLink>
            </td>
          </tr>

          <tr v-if="!sorted.length">
            <td colspan="7" class="py-6 text-center text-gray-400">No flags for the selected filters.</td>
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
function formatCurrency(n) {
  try { return new Intl.NumberFormat(undefined, { style:'currency', currency:'USD', maximumFractionDigits:0 }).format(n) }
  catch { return `$${Math.round(n).toLocaleString()}` }
}

function premTooltip(row){
  const c = row.meta?.call_prem ?? 0
  const p = row.meta?.put_prem ?? 0
  if (!c && !p) return 'No side breakdown'
  return `Call: ${formatCurrency(c)} • Put: ${formatCurrency(p)}`
}

</script>

