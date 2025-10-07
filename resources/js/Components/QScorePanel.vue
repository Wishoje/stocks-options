<template>
  <div class="bg-gray-900 rounded-2xl p-6 space-y-6 text-white">
    <div class="text-center">
      <div class="text-sm text-gray-400">Our quantitative blend of key signals</div>
      <div class="mt-2 inline-flex items-center gap-3 px-4 py-2 rounded-full border border-gray-700 bg-gray-800">
        <span class="text-xs uppercase tracking-wide text-gray-400">Overall</span>
        <span class="text-2xl font-bold" :class="overallColor">{{ overall.toFixed(1) }}</span>
        <span class="text-sm text-gray-300">— {{ overallBlurb }}</span>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <QScoreCard
        title="OPTION"
        :score="scores.option.score"
        :explanation="scores.option.expl"
      />
      <QScoreCard
        title="VOLATILITY"
        :score="scores.vol.score"
        :explanation="scores.vol.expl"
      />
      <QScoreCard
        title="MOMENTUM"
        :score="scores.momo.score"
        :explanation="scores.momo.expl"
      />
      <QScoreCard
        title="SEASONALITY"
        :score="scores.season.score"
        :explanation="scores.season.expl"
      />
    </div>

    <div class="text-xs text-gray-500 text-center">
      {{ asOf ? `As of ${asOf}` : '' }}
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import axios from 'axios'
import QScoreCard from './QScoreCard.vue'

const props = defineProps({
  symbol: { type: String, required: true }
})

const scores = ref({
  option: { score: 2, expl: 'Neutral option positioning.' },
  vol:    { score: 2, expl: 'Neutral volatility regime.' },
  momo:   { score: 2, expl: 'Neutral momentum.' },
  season: { score: 2, expl: 'Neutral 5-day seasonality.' }
})
const asOf = ref(null)

async function load() {
  const { data } = await axios.get('/api/qscore', { params: { symbol: props.symbol } })
  scores.value = data.scores
  asOf.value   = data.date || null
}

onMounted(load)
watch(() => props.symbol, load)

const overall = computed(() => {
  // Weights: Option 35%, Vol 25%, Momentum 30%, Seasonality 10%
  const s = scores.value
  const w = 0.35*s.option.score + 0.25*s.vol.score + 0.30*s.momo.score + 0.10*s.season.score
  return Math.max(0, Math.min(4, w))
})

const overallBlurb = computed(() => {
  if (overall.value >= 3.2) return 'Bullish setup with positioning & trend tailwinds'
  if (overall.value >= 2.4) return 'Constructive but selective'
  if (overall.value >= 1.6) return 'Mixed / range-like'
  if (overall.value >= 0.8) return 'Cautious with headwinds'
  return 'Defensive / avoid risk'
})

const overallColor = computed(() => {
  if (overall.value >= 3.0) return 'text-green-400'
  if (overall.value <= 1.2) return 'text-red-400'
  return 'text-gray-200'
})
</script>
