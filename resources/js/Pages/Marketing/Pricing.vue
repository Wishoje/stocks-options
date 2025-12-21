<template>
  <MarketingLayout>
    <section class="mx-auto max-w-7xl px-4 pt-14 sm:px-6 lg:px-8">
      <div class="text-center">
        <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">Pricing</h1>
        <p class="mx-auto mt-3 max-w-2xl text-sm text-white/60">
          Early Bird access: monthly or yearly.
        </p>
      </div>

      <div class="mt-10">
        <PricingTable :plans="plans" @select="selectPlan" />
      </div>

      <div class="mt-10 rounded-3xl border border-white/10 bg-white/5 p-6 text-sm text-white/70">
        <div class="font-semibold text-white">Safe and simple</div>
        <ul class="mt-3 grid gap-2 md:grid-cols-3">
          <li class="flex gap-2"><span class="text-cyan-300">✓</span> Cancel anytime (once payments exist)</li>
          <li class="flex gap-2"><span class="text-cyan-300">✓</span> Upgrade/downgrade instantly</li>
          <li class="flex gap-2"><span class="text-cyan-300">✓</span> Secure checkout (Stripe placeholder)</li>
        </ul>
      </div>
    </section>
  </MarketingLayout>
</template>

<script setup>
import MarketingLayout from '@/Layouts/MarketingLayout.vue'
import PricingTable from '@/Components/Marketing/PricingTable.vue'

const sharedFeatures = [
  'All symbols',
  'EOD + 1-min intraday snapshots',
  'Net GEX by strike + levels',
  'Dealer positioning (DEX) + expiry pressure',
  'Live Flow + Premium by strike',
  'Unusual Activity + filters',
  'Options Calculator (P&L charts + payoff tables)',
  'Volatility suite (term, VRP, seasonality)',
  'Watchlist scanners',
]

const plans = [
  {
    key: 'earlybird_monthly',
    name: 'Early Bird',
    tagline: 'All features • All symbols',
    price: 29.99,
    unit: '/mo',
    subline: 'Billed monthly',
    cta: 'Get Early Bird (Monthly)',
    featured: true,
    badge: 'Most popular',
    note: 'Limited-time launch pricing • Price increases later',
    billing: 'monthly',
    features: sharedFeatures,
  },
  {
    key: 'earlybird_yearly',
    name: 'Early Bird — Yearly',
    tagline: 'All features • All symbols',
    price: 299,
    unit: '/yr',
    subline: 'Billed yearly • Save ~17%',
    cta: 'Get Early Bird (Yearly)',
    billing: 'yearly',
    features: sharedFeatures,
  },
]

function selectPlan(p) {
  const billing = p.billing || 'monthly'
  window.location.href = `/register?plan=earlybird&billing=${encodeURIComponent(billing)}`
}
</script>
