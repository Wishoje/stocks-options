<template>
  <MarketingSeo
    :title="title"
    :description="description"
    :canonical="canonicalUrl"
    :image="page.props.seo?.image"
    :image-alt="page.props.seo?.image_alt"
    :image-width="page.props.seo?.image_width"
    :image-height="page.props.seo?.image_height"
    :faqs="pricingFaqs"
  />

  <MarketingLayout>
    <div class="pricing-page">
      <section class="pricing-page__hero">
        <p class="pricing-page__eyebrow">Simple pricing</p>
        <h1>Choose how you want to be billed.</h1>
        <p>
          One GexOptions plan gives you the dashboard, positioning views, intraday analysis, scanner,
          calculator, watchlist, and export tools.
        </p>
      </section>

      <section
        v-if="activationPending"
        class="pricing-notice"
        data-tone="progress"
        role="status"
        aria-live="polite"
      >
        <span class="pricing-notice__pulse" aria-hidden="true" />
        <div>
          <strong>Confirming your subscription</strong>
          <p>
            Stripe returned you to GexOptions. We are waiting for the confirmed subscription state before
            opening the dashboard.
          </p>
          <button type="button" :disabled="checkingActivation" @click="checkActivation(true)">
            {{ checkingActivation ? 'Checking…' : 'Check again' }}
          </button>
        </div>
      </section>

      <section
        v-else-if="activationState === 'delayed'"
        class="pricing-notice"
        data-tone="warning"
        role="status"
      >
        <div>
          <strong>Activation is taking longer than usual</strong>
          <p>Your checkout is recorded, but the local subscription record is not confirmed yet. You can check again safely.</p>
          <button type="button" :disabled="checkingActivation" @click="checkActivation(true)">
            {{ checkingActivation ? 'Checking…' : 'Check activation' }}
          </button>
        </div>
      </section>

      <section
        v-else-if="canceledCheckout"
        class="pricing-notice"
        data-tone="warning"
        role="status"
      >
        <div>
          <strong>Checkout canceled</strong>
          <p>No plan change was confirmed. Your billing selection is still here when you are ready.</p>
        </div>
      </section>

      <section
        v-else-if="flashStatus === 'checkout-active-other-selection'"
        class="pricing-notice"
        data-tone="warning"
        role="status"
      >
        <div>
          <strong>Another billing choice already has an active checkout</strong>
          <p>Finish or cancel that Stripe checkout before starting a different billing choice.</p>
        </div>
      </section>

      <section
        v-else-if="flashStatus === 'checkout-already-starting'"
        class="pricing-notice"
        data-tone="progress"
        role="status"
      >
        <div>
          <strong>Checkout is already opening</strong>
          <p>We ignored the duplicate request so a second checkout session was not started.</p>
        </div>
      </section>

      <section
        v-else-if="flashStatus === 'checkout-return-unconfirmed'"
        class="pricing-notice"
        data-tone="warning"
        role="status"
      >
        <div>
          <strong>We could not verify that checkout return</strong>
          <p>Your access has not changed. Start checkout again when you are ready.</p>
        </div>
      </section>

      <PricingTable
        v-model="selectedBilling"
        :offer="offer"
        :features="includedFeatures"
        :busy="checkoutBusy"
        :cta-label="ctaLabel"
        @select="selectPlan"
      />

      <section class="pricing-page__assurances" aria-labelledby="billing-details-heading">
        <div>
          <p class="pricing-page__eyebrow">Billing details</p>
          <h2 id="billing-details-heading">Clear before you confirm</h2>
        </div>
        <ul>
          <li><strong>Hosted checkout</strong><span>Stripe collects payment details on its checkout page.</span></li>
          <li><strong>Manage in account</strong><span>Open the billing portal from your account to manage the subscription.</span></li>
          <li><strong>Cancel from billing</strong><span>Cancellation keeps access through the current paid period.</span></li>
        </ul>
      </section>

      <section class="pricing-page__faq" aria-labelledby="pricing-faq-heading">
        <p class="pricing-page__eyebrow">Questions</p>
        <h2 id="pricing-faq-heading">Before you start</h2>
        <details v-for="item in faqItems" :key="item.question">
          <summary>{{ item.question }}</summary>
          <p>{{ item.answer }}</p>
        </details>
      </section>
    </div>
  </MarketingLayout>
</template>

<script setup>
import axios from 'axios'
import { usePage } from '@inertiajs/vue3'
import { computed, onMounted, onUnmounted, ref } from 'vue'
import MarketingLayout from '@/Layouts/MarketingLayout.vue'
import MarketingSeo from '@/Components/Marketing/MarketingSeo.vue'
import PricingTable from '@/Components/Marketing/PricingTable.vue'
import { journeyUrl, normalizePlanSelection, selectionFromUrl } from '@/Support/marketing-journey.js'
import { trackEvent, trackEventOnce } from '@/lib/ga'

const page = usePage()
const title = computed(() => page.props.seo?.title || 'GexOptions Pricing')
const description = computed(() => page.props.seo?.description || 'Choose monthly or yearly billing for the complete GexOptions analytics workspace.')
const canonicalUrl = computed(() => page.props.seo?.canonical || 'https://gexoptions.com/pricing')
const offer = computed(() => page.props.offer || {
  plan: 'earlybird',
  label: 'Early Bird',
  trial_days: 7,
  display: {},
})

const serverIntent = normalizePlanSelection(page.props.billing?.intent || selectionFromUrl(page.url))
const selectedBilling = ref(serverIntent.billing)
const checkoutBusy = ref(false)
const activationState = ref(page.props.billing?.activation?.pending ? 'pending' : 'idle')
const checkingActivation = ref(false)
let activationAttempts = 0
let activationTimer = null

const query = computed(() => new URL(page.url || '/pricing', window.location.origin).searchParams)
const canceledCheckout = computed(() => query.value.get('canceled') === '1')
const flashStatus = computed(() => page.props.flash?.status || '')
const activationPending = computed(() => activationState.value === 'pending')
const hasUser = computed(() => Boolean(page.props.auth?.user))
const needsCheckout = computed(() => page.props.billing?.needs_checkout !== false)
const ctaLabel = computed(() => {
  if (hasUser.value && !needsCheckout.value) return 'Open dashboard'
  return hasUser.value ? `Continue with ${selectedBilling.value} billing` : `Create account for ${selectedBilling.value} billing`
})

const includedFeatures = [
  'End-of-day and intraday dashboards',
  'Dealer positioning and volatility views',
  'Strike charts and options activity',
  'Scanner and options calculator',
  'Watchlist and AI export workflows',
  'Account billing controls',
]

const faqItems = [
  {
    question: 'What happens after the trial?',
    answer: 'The billing interval you selected begins after the trial unless you cancel before it ends.',
  },
  {
    question: 'Can I cancel?',
    answer: 'Yes. You can open the Stripe billing portal from your account and cancel the subscription.',
  },
  {
    question: 'Do monthly and yearly include the same product?',
    answer: 'Yes. They provide the same product access and differ only in billing interval and price.',
  },
  {
    question: 'Is GexOptions financial advice?',
    answer: 'No. GexOptions provides analytics and research tools. Trading decisions and risk remain yours.',
  },
]

const pricingFaqs = computed(() => faqItems.map(item => ({
  q: item.question,
  a: item.answer,
})))

function scheduleActivationCheck() {
  window.clearTimeout(activationTimer)
  activationTimer = window.setTimeout(() => checkActivation(false), 2000)
}

async function checkActivation(manual = false) {
  if (checkingActivation.value || !hasUser.value) return
  checkingActivation.value = true

  try {
    const response = await axios.get(route('billing.status'), {
      headers: { Accept: 'application/json' },
    })
    const state = response?.data?.state

    if (response?.data?.active && response.data.redirect) {
      activationState.value = 'active'
      trackEventOnce(
        'subscription_activation_confirmed',
        'confirmed',
        { surface: 'pricing', state: 'confirmed' },
      )
      window.location.assign(response.data.redirect)
      return
    }

    activationAttempts += 1
    if (state === 'pending' && activationAttempts < 30) {
      activationState.value = 'pending'
      scheduleActivationCheck()
    } else {
      activationState.value = 'delayed'
    }
  } catch {
    activationAttempts += 1
    activationState.value = activationAttempts < 30 ? 'pending' : 'delayed'
    if (activationState.value === 'pending' && !manual) scheduleActivationCheck()
  } finally {
    checkingActivation.value = false
  }
}

function selectPlan(billing) {
  const selection = normalizePlanSelection({ plan: offer.value.plan, billing })
  const nextStep = hasUser.value
    ? (needsCheckout.value ? 'checkout' : 'dashboard')
    : 'register'
  trackEvent('plan_select', {
    plan: selection.plan,
    billing: selection.billing,
    source: 'pricing_page',
    next_step: nextStep,
  })

  if (hasUser.value && !needsCheckout.value) {
    window.location.assign('/dashboard')
    return
  }

  checkoutBusy.value = true
  if (hasUser.value) {
    trackEvent('checkout_start', { ...selection, source: 'pricing_page' })
  }
  window.location.assign(journeyUrl(hasUser.value ? 'checkout' : 'register', selection))
}

onMounted(() => {
  trackEvent('pricing_view', { source: 'pricing_page' })
  if (canceledCheckout.value) {
    trackEvent('checkout_canceled', { source: 'pricing_page' })
  }
  if (activationPending.value) {
    trackEvent('checkout_activating', { source: 'pricing_page', state: 'pending' })
    scheduleActivationCheck()
  }
})

onUnmounted(() => window.clearTimeout(activationTimer))
</script>

<style scoped>
.pricing-page { width: min(100%, 1180px); margin-inline: auto; padding: clamp(3.5rem, 8vw, 6.5rem) 1rem 5rem; color: #f8fafc; }
.pricing-page__hero { max-width: 720px; margin: 0 auto 2rem; text-align: center; }
.pricing-page__eyebrow { margin: 0 0 .6rem; color: #67e8f9; font-size: .72rem; font-weight: 800; letter-spacing: .18em; text-transform: uppercase; }
.pricing-page__hero h1 { margin: 0; font-size: clamp(2.35rem, 6vw, 4.4rem); line-height: 1.02; letter-spacing: -.045em; }
.pricing-page__hero > p:last-child { max-width: 650px; margin: 1rem auto 0; color: #a8b4c6; font-size: .95rem; line-height: 1.65; }
.pricing-notice { display: flex; width: min(100%, 760px); margin: 0 auto 1rem; gap: .8rem; border: 1px solid rgba(103, 232, 249, .25); border-radius: 1rem; background: rgba(8, 145, 178, .1); padding: 1rem; color: #cffafe; }
.pricing-notice[data-tone="warning"] { border-color: rgba(251, 191, 36, .28); background: rgba(217, 119, 6, .09); color: #fef3c7; }
.pricing-notice strong { font-size: .88rem; }
.pricing-notice p { margin: .2rem 0 0; color: currentColor; opacity: .78; font-size: .78rem; line-height: 1.5; }
.pricing-notice button { margin-top: .6rem; border-bottom: 1px solid currentColor; color: inherit; font-size: .75rem; font-weight: 750; }
.pricing-notice__pulse { flex: none; width: .55rem; height: .55rem; margin-top: .32rem; border-radius: 999px; background: #22d3ee; box-shadow: 0 0 0 0 rgba(34, 211, 238, .5); animation: pricing-pulse 1.8s infinite; }
.pricing-page__assurances, .pricing-page__faq { width: min(100%, 920px); margin: 3rem auto 0; border-top: 1px solid rgba(148, 163, 184, .15); padding-top: 2rem; }
.pricing-page__assurances { display: grid; grid-template-columns: .65fr 1.35fr; gap: 2rem; }
.pricing-page__assurances h2, .pricing-page__faq h2 { margin: 0; font-size: 1.5rem; letter-spacing: -.02em; }
.pricing-page__assurances ul { display: grid; gap: .8rem; margin: 0; padding: 0; list-style: none; }
.pricing-page__assurances li { display: grid; grid-template-columns: 145px 1fr; gap: .75rem; border-radius: .8rem; background: rgba(15, 23, 42, .5); padding: .8rem; }
.pricing-page__assurances strong { color: #e2e8f0; font-size: .78rem; }
.pricing-page__assurances span { color: #94a3b8; font-size: .76rem; line-height: 1.5; }
.pricing-page__faq { max-width: 760px; }
.pricing-page__faq details { border-bottom: 1px solid rgba(148, 163, 184, .14); padding: 1rem 0; }
.pricing-page__faq summary { cursor: pointer; color: #e2e8f0; font-size: .86rem; font-weight: 700; }
.pricing-page__faq details p { margin: .6rem 0 0; color: #94a3b8; font-size: .8rem; line-height: 1.6; }
@keyframes pricing-pulse { 70% { box-shadow: 0 0 0 8px rgba(34, 211, 238, 0); } 100% { box-shadow: 0 0 0 0 rgba(34, 211, 238, 0); } }
@media (max-width: 720px) {
  .pricing-page__assurances { grid-template-columns: 1fr; }
  .pricing-page__assurances li { grid-template-columns: 1fr; gap: .25rem; }
}
@media (prefers-reduced-motion: reduce) { .pricing-notice__pulse { animation: none; } }
</style>
