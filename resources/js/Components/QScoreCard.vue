<template>
  <div class="rounded-2xl p-4 border border-gray-700 bg-gray-800">
    <div class="flex items-baseline justify-between">
      <div class="text-3xl font-bold" :class="colorClass">{{ score }}</div>
      <div class="text-sm uppercase tracking-wide text-gray-400">{{ title }}</div>
    </div>

    <div class="mt-1 text-sm" :class="colorClass">{{ label }}</div>
    <p class="mt-2 text-xs text-gray-300 leading-relaxed">
      {{ explanation }}
    </p>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  title: String,               // "OPTION", "VOLATILITY", ...
  score: { type: Number, default: 2 }, // 0..4
  explanation: String
})

const label = computed(() => {
  if (props.score >= 3.5) return 'High'
  if (props.score >= 2.5) return 'Moderate'
  if (props.score >= 1.5) return 'Neutral'
  if (props.score >= 0.5) return 'Low'
  return 'Very Low'
})
const colorClass = computed(() => {
  if (props.score >= 3.0) return 'text-green-400'
  if (props.score <= 1.0) return 'text-red-400'
  return 'text-gray-200'
})
</script>
