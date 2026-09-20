<template>
  <section class="first-use-guide" aria-labelledby="first-use-guide-title">
    <div class="first-use-guide__copy">
      <p>Quick start</p>
      <h2 id="first-use-guide-title">Read the current view in three steps.</h2>
      <ol>
        <li><strong>Confirm context.</strong> Check symbol, dataset, expiry scope, and snapshot time.</li>
        <li><strong>Read the headline.</strong> Use the primary metric for context, then compare nearby levels.</li>
        <li><strong>Inspect a reading.</strong> Select a chart point or row before acting on the summary.</li>
      </ol>
    </div>
    <div class="first-use-guide__context" aria-label="Your current dashboard context">
      <span>{{ symbol }}</span>
      <span>{{ modeLabel }}</span>
      <span>{{ tabLabel }}</span>
      <span v-if="timeframe">{{ timeframe }}</span>
    </div>
    <div class="first-use-guide__actions">
      <button type="button" data-variant="primary" @click="$emit('continue')">Continue in this view</button>
      <button type="button" @click="$emit('dismiss')">Hide for today</button>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  symbol: { type: String, required: true },
  mode: { type: String, required: true },
  tabLabel: { type: String, required: true },
  timeframe: { type: String, default: '' },
})

defineEmits(['continue', 'dismiss'])

const modeLabel = computed(() => props.mode === 'intraday' ? 'Intraday' : 'End of day')
</script>

<style scoped>
.first-use-guide { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 1rem 2rem; margin: .75rem 1rem 0; border: 1px solid rgba(103, 232, 249, .22); border-radius: 1rem; background: linear-gradient(110deg, rgba(8, 145, 178, .1), rgba(15, 23, 42, .86) 48%); padding: 1rem 1.15rem; color: #e2e8f0; }
.first-use-guide__copy > p { margin: 0 0 .3rem; color: #67e8f9; font-size: .64rem; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; }
.first-use-guide h2 { margin: 0; color: #f8fafc; font-size: .95rem; }
.first-use-guide ol { display: grid; gap: .25rem; margin: .65rem 0 0; padding-left: 1.05rem; color: #94a3b8; font-size: .72rem; line-height: 1.45; }
.first-use-guide li strong { color: #cbd5e1; }
.first-use-guide__context { display: flex; max-width: 19rem; flex-wrap: wrap; align-content: flex-start; justify-content: flex-end; gap: .35rem; }
.first-use-guide__context span { border: 1px solid rgba(148, 163, 184, .2); border-radius: 999px; background: rgba(2, 6, 23, .55); padding: .28rem .5rem; color: #b8c4d5; font-size: .66rem; }
.first-use-guide__actions { display: flex; grid-column: 1 / -1; flex-wrap: wrap; gap: .5rem; }
.first-use-guide button { border: 1px solid rgba(148, 163, 184, .22); border-radius: .6rem; padding: .45rem .7rem; color: #aeb7c2; font-size: .7rem; font-weight: 700; }
.first-use-guide button[data-variant='primary'] { border-color: rgba(34, 211, 238, .45); background: rgba(34, 211, 238, .13); color: #a5f3fc; }
.first-use-guide button:hover { border-color: rgba(103, 232, 249, .5); color: #f8fafc; }
.first-use-guide button:focus-visible { outline: 2px solid #22d3ee; outline-offset: 3px; }
@media (max-width: 720px) {
  .first-use-guide { grid-template-columns: 1fr; }
  .first-use-guide__context { justify-content: flex-start; }
  .first-use-guide__actions { grid-column: auto; }
}
</style>
