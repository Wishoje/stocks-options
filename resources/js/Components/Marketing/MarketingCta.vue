<template>
  <a
    :id="id"
    :href="href"
    class="mk-button"
    :class="`mk-button--${variant}`"
    :data-marketing-destination="destination"
    @click="trackActivation"
  >
    {{ label }}
    <span aria-hidden="true">&rarr;</span>
  </a>
</template>

<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { trackEvent } from '@/lib/ga'
import { journeyUrl, selectionForPage } from '@/Support/marketing-journey'

const props = defineProps({
  id: { type: String, default: undefined },
  location: { type: String, required: true },
  source: { type: String, default: 'marketing' },
  variant: { type: String, default: 'primary' },
  guestLabel: { type: String, default: '' },
  checkoutLabel: { type: String, default: 'Continue to checkout' },
  dashboardLabel: { type: String, default: 'Open dashboard' },
})

const page = usePage()
const user = computed(() => page.props.auth?.user ?? null)
const trialDays = computed(() => Number(page.props.offer?.trial_days) || 7)
const selection = computed(() => selectionForPage(page.url || '/', page.props.billing?.intent))

const destination = computed(() => {
  if (!user.value) return 'register'
  if (page.props.billing?.needs_checkout) return 'checkout'
  return 'dashboard'
})

const href = computed(() => destination.value === 'dashboard'
  ? '/dashboard'
  : journeyUrl(destination.value, selection.value))

const label = computed(() => {
  if (destination.value === 'checkout') return props.checkoutLabel
  if (destination.value === 'dashboard') return props.dashboardLabel
  return props.guestLabel || `Start ${trialDays.value}-day free trial`
})

function trackActivation() {
  trackEvent('hero_cta_click', {
    location: props.location,
    source: props.source,
    destination: destination.value,
    plan: selection.value.plan,
    billing: selection.value.billing,
  })

  if (destination.value === 'checkout') {
    trackEvent('checkout_start', {
      source: props.source,
      location: props.location,
      plan: selection.value.plan,
      billing: selection.value.billing,
    })
  }
}
</script>
