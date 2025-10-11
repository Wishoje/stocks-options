<script setup>
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import InfoTooltip from './InfoTooltip.vue'   // ← add this import

const props = defineProps({
  symbol: { type: String, default: 'SPY' },
  days:   { type: Number, default: 3 }
})

const data = ref(null)
const error = ref(null)
const loading = ref(false)
const showMore = ref(false)                   // ← new: controls the long help

const pinPct = computed(() => Number(data.value?.headline_pin ?? 0))

async function load() {
  loading.value = true
  try {
    const { data:resp } = await axios.get('/api/expiry-pressure', {
      params: { symbol: props.symbol, days: props.days }
    })
    data.value = resp
  } catch (e) {
    error.value = e?.response?.data || e.message
  } finally {
    loading.value = false
  }
}
onMounted(load)
</script>

<template>
  <div class="bg-gray-800 rounded-2xl p-4 space-y-3">
    <!-- Header w/ tooltip + Learn more -->
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-2">
        <h4 class="font-semibold">Expiry Pressure / Pin Risk</h4>

        <!-- Tiny 3-line cheatsheet -->
        <InfoTooltip label="Pin risk quick help">
          <div class="font-semibold mb-1">Pin risk (0–100)</div>
          <ul class="list-disc pl-4 space-y-1">
            <li><b>High</b> = big OI cluster near spot → price tends to pin.</li>
            <li><b>40–69</b> = mixed; clusters influence but can break.</li>
            <li><b>&lt;40</b> = weak; rely on other signals (trend/VRP).</li>
          </ul>
        </InfoTooltip>

        <button
          class="px-2 py-1 rounded bg-gray-700 text-gray-300 hover:bg-gray-600 text-xs"
          @click="showMore = !showMore"
        >
          How to use
        </button>
      </div>

      <span v-if="data?.data_date" class="text-xs text-gray-400">{{ data.data_date }}</span>
    </div>

    <!-- Pin score bar -->
    <div class="flex items-center gap-3">
      <div class="text-sm text-gray-300">Pin-risk score:</div>
      <div class="flex-1 h-2 bg-gray-700 rounded">
        <div class="h-2 rounded bg-yellow-400" :style="{ width: pinPct + '%' }"></div>
      </div>
      <div class="w-10 text-right text-sm text-gray-200">{{ pinPct.toFixed(0) }}%</div>
    </div>

    <!-- Entries -->
    <div v-if="data?.entries?.length" class="space-y-3">
      <div v-for="e in data.entries" :key="e.exp_date" class="bg-gray-700/50 rounded p-3">
        <div class="flex items-center justify-between mb-2">
          <div class="text-sm text-gray-300">
            <span class="text-gray-400">Expiry:</span> {{ e.exp_date }}
          </div>
          <div class="text-sm text-gray-200">
            <span class="text-gray-400 mr-1">Max pain:</span>
            <span class="font-medium">{{ e.max_pain ?? '—' }}</span>
          </div>
        </div>

        <div>
          <div class="text-xs text-gray-400 mb-1">Top strike clusters near spot</div>
          <ul class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <li v-for="c in e.clusters.slice(0,4)" :key="e.exp_date+'-'+c.strike"
                class="px-2 py-1 bg-gray-700 rounded text-sm flex items-center justify-between">
              <span>Strike {{ c.strike }}</span>
              <span class="text-gray-300">{{ c.score.toFixed(0) }}%</span>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Expandable “learn more” block (your long help text) -->
    <transition name="fade">
      <div v-if="showMore" class="bg-gray-700/40 rounded p-3 text-[12px] text-gray-200 space-y-2">
        <div class="font-semibold text-gray-100">How to use</div>
        <ul class="list-disc pl-5 space-y-1">
          <li><b>Pin-risk score</b> (0–100): combines OI density near spot and proximity. Higher → stronger pin risk.</li>
          <li><b>Clusters</b>: OI “humps” near spot that can act like magnets into expiry.</li>
          <li><b>Max pain</b>: classical payoff-minimizing price; treat as a reference, not a target.</li>
        </ul>

        <div class="font-semibold text-gray-100">Rules of thumb</div>
        <ul class="list-disc pl-5 space-y-1">
          <li><b>Score ≥ 70</b>: High pin risk; breakouts may need a catalyst.</li>
          <li><b>40–70</b>: Mixed—respect clusters, but flows can still move.</li>
          <li><b>&lt; 40</b>: Low pin risk; other signals dominate.</li>
        </ul>

        <div class="font-semibold text-gray-100">Examples</div>
        <ul class="list-disc pl-5 space-y-1">
          <li><b>Score 82, clusters 500/505</b> — Expect magnet behavior around those strikes into the close.</li>
          <li><b>Score 28, max pain 498</b> — Weak; treat max pain as informational only.</li>
        </ul>
      </div>
    </transition>
  </div>
</template>
