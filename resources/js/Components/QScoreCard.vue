<script setup>
import { computed } from 'vue'
import { numeric } from './UI/numbers.js'

const props = defineProps({
  title: { type: String, required: true },
  score: { type: [Number, String], default: null },
  explanation: { type: String, default: '' },
  weight: { type: Number, required: true },
})

const numericScore = computed(() => numeric(props.score))
const available = computed(() => numericScore.value !== null)
const displayScore = computed(() => available.value ? numericScore.value.toFixed(1) : 'Unavailable')
const meterValue = computed(() => available.value ? Math.max(0, Math.min(4, numericScore.value)) : 0)
const meterWidth = computed(() => `${meterValue.value / 4 * 100}%`)

const label = computed(() => {
  if (!available.value) return 'No reading'
  if (numericScore.value >= 3.5) return 'High'
  if (numericScore.value >= 2.5) return 'Moderate'
  if (numericScore.value >= 1.5) return 'Neutral'
  if (numericScore.value >= 0.5) return 'Low'
  return 'Very low'
})

const tone = computed(() => {
  if (!available.value) return 'missing'
  if (numericScore.value >= 3) return 'positive'
  if (numericScore.value <= 1) return 'negative'
  return 'neutral'
})
</script>

<template>
  <article
    class="gex-qscore-card"
    :data-tone="tone"
    :aria-label="`${title} Q-Score: ${available ? `${displayScore} out of 4, ${label}` : 'unavailable'}`"
  >
    <header>
      <h3>{{ title }}</h3>
      <span>{{ Math.round(weight * 100) }}% weight</span>
    </header>

    <div class="gex-qscore-card__reading gex-number">
      <strong>{{ displayScore }}</strong>
      <small v-if="available">/ 4</small>
      <span>{{ label }}</span>
    </div>

    <div
      class="gex-qscore-card__meter"
      role="meter"
      aria-valuemin="0"
      aria-valuemax="4"
      :aria-valuenow="available ? numericScore : undefined"
      :aria-valuetext="available ? `${displayScore} out of 4, ${label}` : 'Unavailable'"
    >
      <i :style="{ width: meterWidth }" aria-hidden="true" />
    </div>

    <p>{{ explanation || 'Explanation unavailable for this signal.' }}</p>
  </article>
</template>

<style scoped>
.gex-qscore-card {
  --score-color: var(--gex-data);
  display: grid;
  gap: 12px;
  min-width: 0;
  padding: 16px;
  border: 1px solid var(--gex-border);
  border-radius: 11px;
  background: color-mix(in srgb, var(--gex-raised) 58%, var(--gex-surface));
  transition: border-color var(--gex-duration) var(--gex-ease), background-color var(--gex-duration) var(--gex-ease), transform var(--gex-duration) var(--gex-ease);
}

.gex-qscore-card[data-tone="positive"] { --score-color: var(--gex-positive); }
.gex-qscore-card[data-tone="negative"] { --score-color: var(--gex-negative); }
.gex-qscore-card[data-tone="missing"] { --score-color: var(--gex-muted); }

.gex-qscore-card:hover {
  border-color: color-mix(in srgb, var(--score-color) 48%, var(--gex-border));
  background: color-mix(in srgb, var(--score-color) 5%, var(--gex-raised));
  transform: translateY(-1px);
}

.gex-qscore-card header,
.gex-qscore-card__reading {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10px;
}

.gex-qscore-card header h3 {
  font-size: 12px;
  letter-spacing: .07em;
  text-transform: uppercase;
}

.gex-qscore-card header span,
.gex-qscore-card__reading small,
.gex-qscore-card__reading span {
  color: var(--gex-muted);
  font-size: 11px;
  font-weight: 500;
}

.gex-qscore-card__reading { justify-content: flex-start; }

.gex-qscore-card__reading strong {
  color: var(--score-color);
  font-size: 27px;
  font-weight: 720;
  line-height: 1;
}

.gex-qscore-card__reading span { margin-left: auto; }

.gex-qscore-card__meter {
  position: relative;
  height: 5px;
  overflow: hidden;
  border-radius: 999px;
  background: var(--gex-border);
}

.gex-qscore-card__meter::after {
  content: "";
  position: absolute;
  inset: 0;
  background: linear-gradient(90deg, transparent 24.5%, var(--gex-surface) 25% 26%, transparent 26.5% 49.5%, var(--gex-surface) 50% 51%, transparent 51.5% 74.5%, var(--gex-surface) 75% 76%, transparent 76.5%);
}

.gex-qscore-card__meter i {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--score-color);
  transition: width var(--gex-duration) var(--gex-ease);
}

.gex-qscore-card p {
  color: var(--gex-muted);
  font-size: 12px;
  line-height: 1.5;
}

@media (prefers-reduced-motion: reduce) {
  .gex-qscore-card:hover { transform: none; }
}

:global(.gex-ui[data-motion="reduced"]) .gex-qscore-card:hover { transform: none; }
</style>
