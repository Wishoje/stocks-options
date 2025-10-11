<template>
  <div class="bg-gray-800 rounded-2xl p-4">
    <div class="flex items-center justify-between">
      <h4 class="font-semibold">Dealer Positioning</h4>
      <button
        class="px-2 py-1 rounded bg-gray-700 text-gray-300 hover:bg-gray-600 text-xs"
        @click="open = !open"
      >How to use</button>
    </div>

    <!-- Chips row -->
    <div class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3 items-center">

    <!-- Headline -->
    <div class="flex items-center gap-2">
        <span
        class="px-2 py-1 rounded text-sm"
        :class="dex>=0 ? 'bg-green-500/15 text-green-300' : 'bg-red-500/15 text-red-300'"
        :title="`Total DEX (Σ Δ×OI×100) = ${fmt(dex)}`"
        v-if="dex!=null"
        >
        Dealer Delta: {{ dex>=0 ? 'Long (net +Δ)' : 'Short (net −Δ)' }}
        </span>

        <span
        v-if="gammaSign!=null"
        class="px-2 py-1 rounded text-sm"
        :class="gammaSign>0 ? 'bg-green-500/15 text-green-300' : 'bg-red-500/15 text-red-300'"
        :title="gammaSign>0 ? 'Net positive gamma regime' : 'Net negative gamma regime'"
        >
        Gamma: {{ gammaSign>0 ? 'Positive' : 'Negative' }}
        </span>
    </div>

    <!-- Strength meter -->
    <div v-if="strength!=null" class="space-y-1">
        <div class="flex items-center justify-between text-xs text-gray-300">
        <span>Regime strength</span>
        <span>{{ (strength*100).toFixed(0) }}%</span>
        </div>
        <div class="h-2 bg-gray-700 rounded">
        <div
            class="h-2 rounded"
            :class="gammaSign>0 ? 'bg-green-400' : 'bg-red-400'"
            :style="{ width: (Math.min(1, Math.max(0,strength))*100) + '%' }"
        />
        </div>
    </div>

    <!-- One-line implication -->
    <div class="text-xs md:text-sm text-gray-300 md:text-right" v-if="dex!=null && gammaSign!=null && strength!=null">
        <span class="opacity-70">Implication:</span>
        <span class="ml-1 font-medium">
        {{ regimeHint }}
        </span>
    </div>
    </div>

    <DexByExpiryDiverging v-if="byExpiry.length" :items="byExpiry" />
    <div v-if="byExpiry.length" class="grid grid-cols-2 gap-4 mt-2">
        <div>
            <div class="text-xs text-gray-400 mb-1">Top +DEX</div>
            <ul class="space-y-1 text-sm">
            <li v-for="r in [...byExpiry].filter(x=>x.dex_total>0).sort((a,b)=>b.dex_total-a.dex_total).slice(0,3)" :key="r.exp_date">
                {{ shortDate(r.exp_date) }} — {{ fmt(r.dex_total) }}
            </li>
            </ul>
        </div>
        <div>
            <div class="text-xs text-gray-400 mb-1">Top −DEX</div>
            <ul class="space-y-1 text-sm">
            <li v-for="r in [...byExpiry].filter(x=>x.dex_total<0).sort((a,b)=>Math.abs(b.dex_total)-Math.abs(a.dex_total)).slice(0,3)" :key="r.exp_date">
                {{ shortDate(r.exp_date) }} — {{ fmt(r.dex_total) }}
            </li>
            </ul>
        </div>
    </div>

    <!-- Help -->
    <div v-if="open" class="mt-4 text-[12px] leading-5 text-gray-200 space-y-2 bg-gray-700/40 rounded p-3">
      <div class="font-semibold text-gray-100">What am I seeing?</div>
      <ul class="list-disc pl-5 space-y-1">
        <li><b>DEX</b> (Dealer Delta Exposure) ≈ Σ(Δ × OI × 100) across the chain.</li>
        <li>
          <b>Dealer Delta:</b> <span class="text-green-300">Long</span> means net +Δ; dealers tend to
          <i>sell rips / buy dips</i>. <span class="text-red-300">Short</span> means net −Δ; dealers may
          <i>chase moves</i>.
        </li>
        <li>
          <b>Gamma sign:</b> Positive → flows dampen moves (pinning tendency). Negative → flows amplify
          moves (air-pocket risk).
        </li>
        <li>
          <b>Strength:</b> 0–100% coherence of the gamma regime:
          <em>|Σ GammaNotional| / Σ|GammaNotional|</em>. Higher → stronger regime.
        </li>
      </ul>

      <div class="font-semibold text-gray-100">Quick rules of thumb</div>
      <ul class="list-disc pl-5 space-y-1">
        <li><b>Long Δ + Positive Gamma + Strong (≥70%)</b> → mean-revert/pinning bias; breakouts need news.</li>
        <li><b>Short Δ + Negative Gamma + Strong (≥70%)</b> → moves can overshoot; fades are dangerous.</li>
        <li><b>Strength &lt; 40%</b> → mixed book; other signals (trend, macro) dominate.</li>
      </ul>

      <div class="font-semibold text-gray-100">Examples</div>
      <ul class="list-disc pl-5 space-y-1">
        <li>
          <b>Dealer Delta: Long • Gamma: Positive • Strength: 82%</b> — Expect intraday fades of spikes; 
          consider selling wings or using tighter profit-takes on momentum.
        </li>
        <li>
          <b>Dealer Delta: Short • Gamma: Negative • Strength: 76%</b> — Expect directional extension; 
          consider momentum continuation tactics; avoid mean-reversion entries.
        </li>
        <li>
          <b>Dealer Delta: Long • Gamma: Negative • Strength: 55%</b> — Mixed: dips may still get bought, 
          but volatility can expand; prefer defined-risk entries.
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import DexByExpiryDiverging from './DexByExpiryDiverging.vue'
import axios from 'axios'

const props = defineProps({
  symbol: { type: String, default: 'SPY' }
})

const dex = ref(null)
const byExpiry = ref([])
const dataDate = ref(null)
const strength = ref(null)
const gammaSign = ref(null)
const open = ref(false)

function fmt(x){
  const n = Number(x)
  if (!Number.isFinite(n)) return '—'
  // compact, keep sign
  const s = Math.sign(n) < 0 ? '-' : ''
  const a = Math.abs(n)
  if (a >= 1e9) return s + (a/1e9).toFixed(2) + 'B'
  if (a >= 1e6) return s + (a/1e6).toFixed(2) + 'M'
  if (a >= 1e3) return s + (a/1e3).toFixed(2) + 'K'
  return s + a.toFixed(0)
}
function shortDate(d){
  if (!d) return ''
  const p = d.split('-'); return p.length===3 ? `${p[1]}/${p[2]}` : d
}

const barModel = computed(() => {
  if (!byExpiry.value.length) return []
  const vals = byExpiry.value.map(x => Math.abs(Number(x.dex_total)||0))
  const max = Math.max(...vals, 1e-6)
  return byExpiry.value.map(r => ({
    exp: r.exp_date,
    v: Number(r.dex_total)||0,
    h: Math.min(100, (Math.abs(Number(r.dex_total)||0)/max)*100)
  }))
})

const regimeHint = computed(() => {
  if (strength.value==null || gammaSign.value==null || dex.value==null) return ''
  const strong = strength.value >= 0.7
  if (gammaSign.value > 0) {
    return dex.value >= 0
      ? (strong ? 'Pinning / mean-revert bias' : 'Mild pinning; breaks need catalyst')
      : (strong ? 'Pinning, but short Δ can chase dips' : 'Mixed pinning; watch tape')
  } else {
    return dex.value >= 0
      ? (strong ? 'Extension risk on rips; beware fades' : 'Choppy with expansion risk')
      : (strong ? 'Trend/extension risk; avoid fading' : 'Mixed-book; other signals dominate')
  }
})

async function load() {
  const { data } = await axios.get('/api/dex', { params: { symbol: props.symbol } })
  dex.value = data?.total ?? null
  byExpiry.value = Array.isArray(data?.by_expiry) ? data.by_expiry : []
  dataDate.value = data?.data_date ?? null

  // pull regime_strength + gamma sign from your /gex-levels payload
  try {
    const g = await axios.get('/api/gex-levels', { params: { symbol: props.symbol, timeframe: '14d' } })
    strength.value = g?.data?.regime_strength ?? null
    gammaSign.value = g?.data?.gamma_sign ?? null
  } catch {}
}

onMounted(load)
</script>
