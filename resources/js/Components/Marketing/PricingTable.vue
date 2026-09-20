<template>
  <article class="pricing-card" aria-labelledby="pricing-plan-name">
    <div class="pricing-card__header">
      <div>
        <p class="pricing-card__eyebrow">{{ offer.trial_days }}-day trial</p>
        <h2 id="pricing-plan-name">{{ offer.label }}</h2>
        <p>One plan with the complete GexOptions workspace.</p>
      </div>
      <span class="pricing-card__status">Full access</span>
    </div>

    <fieldset
      class="pricing-card__options"
      role="radiogroup"
      aria-labelledby="pricing-billing-interval-label"
    >
      <legend id="pricing-billing-interval-label">Billing interval</legend>
      <div class="pricing-card__option-grid">
        <button
          v-for="option in options"
          :key="option.key"
          :ref="element => setOptionRef(option.key, element)"
          type="button"
          class="pricing-card__option"
          :class="{ 'is-selected': option.key === modelValue }"
          :disabled="!option.available"
          role="radio"
          :aria-checked="option.key === modelValue"
          :tabindex="option.key === tabStopKey ? 0 : -1"
          @click="selectOption(option.key)"
          @keydown="onOptionKeydown($event, option.key)"
        >
          <span class="pricing-card__option-copy">
            <strong>{{ option.name }}</strong>
            <small>{{ option.detail }}</small>
          </span>
          <span class="pricing-card__option-price" :data-available="option.available">
            <strong>{{ option.label }}</strong>
            <small>/ {{ option.interval }}</small>
          </span>
        </button>
      </div>
    </fieldset>

    <div class="pricing-card__price" aria-live="polite">
      <span>{{ selectedDisplay.label }}</span>
      <small v-if="selectedDisplay.available">per {{ selectedDisplay.interval }}</small>
    </div>
    <p class="pricing-card__trial">
      No subscription charge during the {{ offer.trial_days }}-day trial. Your selected recurring price starts
      afterward unless you cancel.
    </p>

    <button
      type="button"
      class="pricing-card__cta"
      :disabled="busy || !selectedDisplay.available"
      @click="emit('select', modelValue)"
    >
      <span v-if="busy" class="pricing-card__spinner" aria-hidden="true" />
      {{ busy ? 'Opening secure checkout…' : (selectedDisplay.available ? ctaLabel : 'Billing option unavailable') }}
    </button>
    <p class="pricing-card__checkout-note">Stripe shows the final total and any applicable tax before you confirm.</p>

    <div class="pricing-card__features">
      <h3>Included</h3>
      <ul>
        <li v-for="feature in features" :key="feature">
          <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
            <path d="m5 10.25 3.1 3.1L15 6.75" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
          <span>{{ feature }}</span>
        </li>
      </ul>
    </div>
  </article>
</template>

<script setup>
import { computed, nextTick } from 'vue'
import { yearlySavings } from '@/Support/marketing-journey.js'

const props = defineProps({
  offer: {
    type: Object,
    required: true,
  },
  modelValue: {
    type: String,
    default: 'monthly',
  },
  busy: Boolean,
  ctaLabel: {
    type: String,
    default: 'Start 7-day trial',
  },
  features: {
    type: Array,
    default: () => [],
  },
})

const emit = defineEmits(['update:modelValue', 'select'])

const display = computed(() => props.offer?.display || {})
const savings = computed(() => yearlySavings(display.value))
const money = value => new Intl.NumberFormat('en-US', {
  style: 'currency',
  currency: display.value.currency || 'USD',
  minimumFractionDigits: 0,
  maximumFractionDigits: 2,
}).format(value)

const options = computed(() => ['monthly', 'yearly'].map(key => {
  const item = display.value[key] || {}
  const amountMinor = item.amount_minor
  const available = Number.isInteger(amountMinor)
    && amountMinor >= 0
    && typeof item.interval === 'string'
    && item.interval.trim() !== ''
  const saved = key === 'yearly' && savings.value > 0
    ? `Save ${money(savings.value)} compared with 12 monthly payments`
    : (key === 'monthly' ? 'Pay month to month' : 'Pay once per year')

  return {
    key,
    name: key === 'yearly' ? 'Yearly' : 'Monthly',
    label: available ? money(amountMinor / 100) : 'Price unavailable',
    interval: available ? item.interval : '',
    detail: available ? saved : 'This billing option is not configured.',
    available,
  }
}))

const selectedDisplay = computed(() => {
  const selected = options.value.find(option => option.key === props.modelValue)
  return selected || options.value[0]
})

const availableOptions = computed(() => options.value.filter(option => option.available))
const tabStopKey = computed(() => {
  const selected = availableOptions.value.find(option => option.key === props.modelValue)
  return selected?.key || availableOptions.value[0]?.key
})
const optionRefs = new Map()

function setOptionRef(key, element) {
  if (element) optionRefs.set(key, element)
  else optionRefs.delete(key)
}

function selectOption(key, moveFocus = false) {
  emit('update:modelValue', key)
  if (moveFocus) nextTick(() => optionRefs.get(key)?.focus())
}

function onOptionKeydown(event, currentKey) {
  const keys = availableOptions.value.map(option => option.key)
  if (!keys.length) return

  const currentIndex = Math.max(0, keys.indexOf(currentKey))
  let nextIndex

  switch (event.key) {
    case 'ArrowRight':
    case 'ArrowDown':
      nextIndex = (currentIndex + 1) % keys.length
      break
    case 'ArrowLeft':
    case 'ArrowUp':
      nextIndex = (currentIndex - 1 + keys.length) % keys.length
      break
    case 'Home':
      nextIndex = 0
      break
    case 'End':
      nextIndex = keys.length - 1
      break
    default:
      return
  }

  event.preventDefault()
  selectOption(keys[nextIndex], true)
}
</script>

<style scoped>
.pricing-card {
  width: min(100%, 760px);
  margin-inline: auto;
  border: 1px solid rgba(103, 232, 249, .2);
  border-radius: 1.5rem;
  background:
    radial-gradient(circle at 80% 0%, rgba(34, 211, 238, .09), transparent 34%),
    rgba(8, 15, 29, .9);
  padding: clamp(1.25rem, 3vw, 2rem);
  box-shadow: 0 24px 70px rgba(0, 0, 0, .28);
}
.pricing-card__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
.pricing-card__eyebrow { margin: 0 0 .4rem; color: #67e8f9; font-size: .72rem; font-weight: 700; letter-spacing: .16em; text-transform: uppercase; }
.pricing-card h2 { margin: 0; color: #f8fafc; font-size: clamp(1.6rem, 3vw, 2.1rem); }
.pricing-card__header p:last-child { margin: .45rem 0 0; color: #94a3b8; font-size: .9rem; }
.pricing-card__status { flex: none; border: 1px solid rgba(52, 211, 153, .3); border-radius: 999px; background: rgba(16, 185, 129, .1); color: #a7f3d0; padding: .35rem .65rem; font-size: .72rem; font-weight: 700; }
.pricing-card__options { margin: 1.5rem 0 0; border: 0; padding: 0; }
.pricing-card__options legend { margin-bottom: .6rem; color: #cbd5e1; font-size: .78rem; font-weight: 700; }
.pricing-card__option-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .65rem; }
.pricing-card__option { display: flex; min-width: 0; align-items: center; justify-content: space-between; gap: .75rem; border: 1px solid rgba(148, 163, 184, .18); border-radius: 1rem; background: rgba(15, 23, 42, .72); padding: .85rem; color: #e2e8f0; text-align: left; transition: border-color .16s ease, background-color .16s ease, transform .16s ease; }
.pricing-card__option:hover { border-color: rgba(103, 232, 249, .4); transform: translateY(-1px); }
.pricing-card__option:disabled { cursor: not-allowed; opacity: .55; transform: none; }
.pricing-card__option:focus-visible { outline: 2px solid #22d3ee; outline-offset: 3px; }
.pricing-card__option.is-selected { border-color: #22d3ee; background: rgba(8, 145, 178, .13); box-shadow: inset 0 0 0 1px rgba(34, 211, 238, .12); }
.pricing-card__option-copy, .pricing-card__option-price { display: grid; gap: .2rem; }
.pricing-card__option-price { flex: none; text-align: right; }
.pricing-card__option strong { font-size: .88rem; }
.pricing-card__option small { color: #94a3b8; font-size: .68rem; line-height: 1.35; }
.pricing-card__price { display: flex; align-items: baseline; gap: .45rem; margin-top: 1.5rem; color: #f8fafc; }
.pricing-card__price span { font-size: clamp(2.4rem, 7vw, 3.8rem); font-weight: 750; letter-spacing: -.045em; }
.pricing-card__price small { color: #94a3b8; }
.pricing-card__trial { margin: .2rem 0 0; color: #b8c4d5; font-size: .82rem; line-height: 1.55; }
.pricing-card__cta { display: flex; width: 100%; align-items: center; justify-content: center; gap: .55rem; margin-top: 1.25rem; border-radius: .85rem; background: linear-gradient(110deg, #22d3ee, #38bdf8); padding: .82rem 1rem; color: #082f49; font-size: .9rem; font-weight: 800; transition: filter .16s ease, transform .16s ease; }
.pricing-card__cta:hover:not(:disabled) { filter: brightness(1.08); transform: translateY(-1px); }
.pricing-card__cta:focus-visible { outline: 2px solid #e0f2fe; outline-offset: 3px; }
.pricing-card__cta:disabled { cursor: wait; opacity: .7; }
.pricing-card__spinner { width: .9rem; height: .9rem; border: 2px solid rgba(8, 47, 73, .3); border-top-color: #082f49; border-radius: 999px; animation: pricing-spin .7s linear infinite; }
.pricing-card__checkout-note { margin: .65rem 0 0; color: #718096; font-size: .72rem; text-align: center; }
.pricing-card__features { margin-top: 1.5rem; border-top: 1px solid rgba(148, 163, 184, .14); padding-top: 1.25rem; }
.pricing-card__features h3 { margin: 0; color: #e2e8f0; font-size: .82rem; }
.pricing-card__features ul { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .65rem 1rem; margin: .85rem 0 0; padding: 0; list-style: none; }
.pricing-card__features li { display: flex; gap: .55rem; color: #b8c4d5; font-size: .8rem; }
.pricing-card__features svg { flex: none; width: 1rem; color: #34d399; }
@keyframes pricing-spin { to { transform: rotate(360deg); } }
@media (max-width: 640px) {
  .pricing-card__header { display: grid; }
  .pricing-card__option-grid, .pricing-card__features ul { grid-template-columns: 1fr; }
}
@media (prefers-reduced-motion: reduce) {
  .pricing-card__option, .pricing-card__cta { transition: none; }
  .pricing-card__spinner { animation-duration: 1.4s; }
}
</style>
