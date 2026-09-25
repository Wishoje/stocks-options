<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import axios from 'axios'
import QScoreCard from './QScoreCard.vue'
import UiBadge from './UI/UiBadge.vue'
import UiHelpDialog from './UI/UiHelpDialog.vue'
import UiPanel from './UI/UiPanel.vue'
import UiStatus from './UI/UiStatus.vue'
import { numeric } from './UI/numbers.js'

const props = defineProps({
  symbol: { type: String, required: true },
  snapshotDate: { type: String, default: null },
  active: { type: Boolean, default: true },
})

const configuration = [
  { key: 'option', title: 'Option positioning', weight: .35 },
  { key: 'vol', title: 'Volatility', weight: .25 },
  { key: 'momo', title: 'Momentum', weight: .30 },
  { key: 'season', title: 'Seasonality', weight: .10 },
]

const qscorePayload = ref(null)
const loading = ref(false)
const errorMessage = ref('')
let activeController = null
let requestSequence = 0

const scores = computed(() => qscorePayload.value?.scores ?? null)
const asOf = computed(() => qscorePayload.value?.date ?? null)
const hasSourceDates = computed(() => qscorePayload.value?.source_dates && typeof qscorePayload.value.source_dates === 'object')
const sourceDateItems = computed(() => {
  if (!hasSourceDates.value) return []
  const sourceDates = qscorePayload.value.source_dates
  const option = sourceDates.option
  let optionValue = 'Unavailable'
  if (option && typeof option === 'object') {
    const earliest = option.earliest || null
    const latest = option.latest || null
    if (earliest && latest) optionValue = earliest === latest ? earliest : `${earliest} to ${latest}`
    else if (earliest) optionValue = `${earliest} to latest unavailable`
    else if (latest) optionValue = `Earliest unavailable to ${latest}`
  }
  return [
    { label: 'Option positioning', value: optionValue },
    { label: 'Volatility', value: sourceDates.volatility || 'Unavailable' },
    { label: 'Momentum', value: sourceDates.momentum || 'Unavailable' },
    { label: 'Seasonality', value: sourceDates.seasonality || 'Unavailable' },
  ]
})
const scoreItems = computed(() => configuration.map(item => ({
  ...item,
  score: scores.value?.[item.key]?.score ?? null,
  explanation: scores.value?.[item.key]?.expl ?? '',
})))

const overall = computed(() => {
  const values = scoreItems.value.map(item => numeric(item.score))
  if (values.some(value => value === null)) return null
  const weighted = values.reduce((sum, value, index) => sum + value * configuration[index].weight, 0)
  return Math.max(0, Math.min(4, weighted))
})

const overallDisplay = computed(() => overall.value === null ? 'Unavailable' : overall.value.toFixed(1))
const overallLabel = computed(() => {
  if (overall.value === null) return 'Score not available'
  if (overall.value >= 3.2) return 'Strong'
  if (overall.value >= 2.4) return 'Constructive'
  if (overall.value >= 1.6) return 'Mixed'
  if (overall.value >= .8) return 'Cautious'
  return 'Defensive'
})
const overallBlurb = computed(() => {
  if (overall.value === null) return 'All four inputs are required for the weighted score.'
  if (overall.value >= 3.2) return 'Bullish setup with positioning and trend tailwinds.'
  if (overall.value >= 2.4) return 'Constructive conditions, with selective signals.'
  if (overall.value >= 1.6) return 'Mixed signals and range-like conditions.'
  if (overall.value >= .8) return 'Cautious conditions with signal headwinds.'
  return 'Defensive conditions with broad signal headwinds.'
})
const overallTone = computed(() => {
  if (overall.value === null) return 'warning'
  if (overall.value >= 3) return 'positive'
  if (overall.value <= 1.2) return 'negative'
  return 'data'
})

function cancelled(error, controller) {
  return controller?.signal.aborted || error?.code === 'ERR_CANCELED' || error?.name === 'CanceledError'
}

async function load() {
  if (!props.active) return
  const sequence = ++requestSequence
  activeController?.abort()
  const controller = new AbortController()
  activeController = controller
  const requestedSymbol = String(props.symbol || '').trim().toUpperCase()
  const requestedDate = props.snapshotDate || null

  qscorePayload.value = null
  errorMessage.value = ''
  loading.value = true

  try {
    const { data } = await axios.get('/api/qscore', {
      params: {
        symbol: requestedSymbol,
        ...(requestedDate ? { date: requestedDate } : {}),
      },
      signal: controller.signal,
    })
    if (sequence !== requestSequence || controller.signal.aborted) return
    qscorePayload.value = data ?? null
  } catch (error) {
    if (sequence !== requestSequence || cancelled(error, controller)) return
    errorMessage.value = error?.response?.data?.message || error?.message || 'The Q-Score request failed.'
  } finally {
    if (sequence === requestSequence) loading.value = false
  }
}

function pause() {
  requestSequence += 1
  activeController?.abort()
  activeController = null
  loading.value = false
}

watch([() => props.symbol, () => props.snapshotDate, () => props.active], () => {
  if (props.active) load()
  else pause()
}, { immediate: true })
onBeforeUnmount(pause)

defineExpose({ qscorePayload, scores, scoreItems, overall, sourceDateItems, load })
</script>

<template>
  <UiPanel
    class="gex-qscore-panel"
    title="Q-Score"
    subtitle="Symbol-wide signals anchored to one completed EOD date; the expiry timeframe does not change this score."
    :tone="overallTone"
  >
    <template #actions>
      <div class="gex-qscore-panel__actions">
        <UiBadge tone="data">{{ String(symbol).toUpperCase() }}</UiBadge>
        <UiBadge v-if="asOf" tone="neutral">Scoring anchor {{ asOf }}</UiBadge>
        <UiHelpDialog id="overview-qscore-guide" title="How to read Q-Score">
          <p>Q-Score combines four signals on a scale from 0 to 4. A higher result means more of the measured inputs are supportive; a lower result means more are cautious.</p>
          <dl class="gex-qscore-weights">
            <div><dt>Option positioning</dt><dd>35%</dd></div>
            <div><dt>Momentum</dt><dd>30%</dd></div>
            <div><dt>Volatility</dt><dd>25%</dd></div>
            <div><dt>Seasonality</dt><dd>10%</dd></div>
          </dl>
          <p>Read the four explanations with the overall number. The score summarizes the inputs; it does not predict direction by itself.</p>
        </UiHelpDialog>
      </div>
    </template>

    <UiStatus
      v-if="loading"
      state="loading"
      layout="metrics"
      :title="`Loading ${String(symbol).toUpperCase()} Q-Score`"
      message="Fetching the latest four signal readings."
    />
    <UiStatus
      v-else-if="errorMessage"
      state="error"
      title="Q-Score unavailable"
      :message="errorMessage"
      retry
      @retry="load"
    />
    <template v-else-if="qscorePayload">
      <div class="gex-qscore-panel__hero" :data-tone="overallTone">
        <div>
          <p>Overall weighted score</p>
          <output
            class="gex-qscore-panel__overall gex-number"
            aria-live="polite"
            :aria-label="overall === null ? 'Overall Q-Score unavailable' : `Overall Q-Score ${overallDisplay} out of 4`"
          >
            <strong>{{ overallDisplay }}</strong>
            <small v-if="overall !== null">/ 4</small>
          </output>
        </div>
        <div class="gex-qscore-panel__interpretation">
          <strong>{{ overallLabel }}</strong>
          <p>{{ overallBlurb }}</p>
        </div>
      </div>

      <div class="gex-qscore-panel__grid">
        <QScoreCard
          v-for="item in scoreItems"
          :key="item.key"
          :title="item.title"
          :score="item.score"
          :explanation="item.explanation"
          :weight="item.weight"
        />
      </div>

      <div v-if="hasSourceDates" class="gex-qscore-panel__sources" aria-label="Q-Score dates">
        <p>Signal dates</p>
        <dl>
          <div v-for="item in sourceDateItems" :key="item.label">
            <dt>{{ item.label }}</dt>
            <dd class="gex-number" :data-missing="item.value === 'Unavailable' ? 'true' : undefined">{{ item.value }}</dd>
          </div>
        </dl>
      </div>
    </template>
    <UiStatus
      v-else
      state="sparse"
      title="Q-Score unavailable"
      message="No score payload was returned for this symbol."
    />
  </UiPanel>
</template>

<style scoped>
.gex-qscore-panel__actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 8px;
}

.gex-qscore-panel__hero {
  --score-color: var(--gex-data);
  display: grid;
  grid-template-columns: minmax(150px, .7fr) minmax(220px, 1.3fr);
  align-items: center;
  gap: 18px;
  padding: 18px;
  border: 1px solid color-mix(in srgb, var(--score-color) 42%, var(--gex-border));
  border-radius: 12px;
  background: color-mix(in srgb, var(--score-color) 8%, var(--gex-surface));
}

.gex-qscore-panel__hero[data-tone="positive"] { --score-color: var(--gex-positive); }
.gex-qscore-panel__hero[data-tone="negative"] { --score-color: var(--gex-negative); }
.gex-qscore-panel__hero[data-tone="warning"] { --score-color: var(--gex-warning); }

.gex-qscore-panel__hero > div:first-child > p {
  color: var(--gex-muted);
  font-size: 12px;
  font-weight: 600;
}

.gex-qscore-panel__overall {
  display: flex;
  align-items: baseline;
  gap: 7px;
  margin-top: 5px;
}

.gex-qscore-panel__overall strong {
  color: var(--score-color);
  font-size: 38px;
  font-weight: 740;
  letter-spacing: -.03em;
  line-height: 1;
}

.gex-qscore-panel__overall small {
  color: var(--gex-muted);
  font-size: 13px;
}

.gex-qscore-panel__interpretation {
  padding-left: 18px;
  border-left: 1px solid color-mix(in srgb, var(--score-color) 36%, var(--gex-border));
}

.gex-qscore-panel__interpretation strong {
  color: var(--score-color);
  font-size: 14px;
}

.gex-qscore-panel__interpretation p {
  margin-top: 4px;
  color: var(--gex-muted);
  font-size: 12px;
}

.gex-qscore-panel__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  margin-top: 14px;
}

.gex-qscore-weights {
  display: grid;
  gap: 8px;
  margin: 14px 0;
}

.gex-qscore-weights div {
  display: flex;
  justify-content: space-between;
  gap: 20px;
  padding-bottom: 7px;
  border-bottom: 1px solid var(--gex-border);
}

.gex-qscore-weights dt { color: var(--gex-muted); }
.gex-qscore-weights dd { margin: 0; color: var(--gex-text); font-variant-numeric: tabular-nums; }

.gex-qscore-panel__sources {
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid var(--gex-border);
}

.gex-qscore-panel__sources > p {
  color: var(--gex-muted);
  font-size: 11px;
  font-weight: 600;
  letter-spacing: .05em;
  text-transform: uppercase;
}

.gex-qscore-panel__sources dl {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 8px;
  margin: 8px 0 0;
}

.gex-qscore-panel__sources dl div {
  min-width: 0;
  padding: 8px 9px;
  border-radius: 7px;
  background: color-mix(in srgb, var(--gex-raised) 72%, transparent);
}

.gex-qscore-panel__sources dt { color: var(--gex-muted); font-size: 10px; }
.gex-qscore-panel__sources dd { overflow-wrap: anywhere; margin: 2px 0 0; color: var(--gex-text); font-size: 11px; }
.gex-qscore-panel__sources dd[data-missing="true"] { color: var(--gex-warning); }

@media (max-width: 680px) {
  .gex-qscore-panel__hero { grid-template-columns: 1fr; }
  .gex-qscore-panel__interpretation { padding: 12px 0 0; border-left: 0; border-top: 1px solid color-mix(in srgb, var(--score-color) 36%, var(--gex-border)); }
  .gex-qscore-panel__grid { grid-template-columns: 1fr; }
  .gex-qscore-panel__sources dl { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .gex-qscore-panel__actions { justify-content: flex-start; }
}
</style>
