<template>
  <Head>
    <title>{{ title }}</title>
    <meta name="description" :content="description" />
    <link rel="canonical" :href="url" />

    <meta property="og:type" content="website" />
    <meta property="og:title" :content="title" />
    <meta property="og:description" :content="description" />
    <meta property="og:url" :content="url" />

    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" :content="title" />
    <meta name="twitter:description" :content="description" />
    <script type="application/ld+json" v-html="JSON.stringify(faqJsonLd)"></script>
  </Head>
  <MarketingLayout>
    <section class="mx-auto max-w-[1440px] px-4 pt-14 sm:px-6 lg:px-8">
      <div class="text-center">
        <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">Pricing</h1>
        <p class="mx-auto mt-3 max-w-2xl text-sm text-white/60">
          Early Bird access with full feature access. Choose monthly or yearly and save $60/year.
        </p>
      </div>

      <div class="mt-10">
        <PricingTable :plans="plans" @select="selectPlan" />
      </div>

      <div class="mt-10 rounded-3xl border border-white/10 bg-white/5 p-6 text-sm text-white/70">
        <div class="font-semibold text-white">Safe and simple</div>
        <ul class="mt-3 grid gap-3 md:grid-cols-3">
          <li class="flex gap-2"><span class="text-cyan-300">&#10003;</span> Cancel anytime in Billing - you keep access through the end of your current period.</li>
          <li class="flex gap-2"><span class="text-cyan-300">&#10003;</span> Change plans anytime. Stripe handles billing updates automatically (including prorations when applicable).</li>
          <li class="flex gap-2"><span class="text-cyan-300">&#10003;</span> Secure checkout powered by Stripe - card details never touch our servers.</li>
        </ul>
      </div>
    </section>
  </MarketingLayout>
</template>

<script setup>
import MarketingLayout from '@/Layouts/MarketingLayout.vue'
import PricingTable from '@/Components/Marketing/PricingTable.vue'
import { Head, usePage } from '@inertiajs/vue3'
import { onMounted } from 'vue'
import { trackEvent } from '@/lib/ga'

const page = usePage()

const title = 'GexOptions - Levels, Dealer Positioning (DEX), and Options Flow'
const description =
  'Options analytics terminal with 1-minute intraday snapshots, GEX levels, dealer positioning (DEX), scanners, and risk tools for daily prep and cleaner intraday decisions.'
const url = 'https://gexoptions.com/pricing'

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
    tagline: 'All features - All symbols',
    price: 29.99,
    unit: '/mo',
    subline: 'Billed monthly',
    cta: 'Get Early Bird (Monthly)',
    featured: true,
    badge: 'Most popular',
    note: 'Limited-time launch pricing - price increases later',
    billing: 'monthly',
    features: sharedFeatures,
  },
  {
    key: 'earlybird_yearly',
    name: 'Early Bird - Yearly',
    tagline: 'All features - All symbols',
    price: 299,
    unit: '/yr',
    subline: 'Billed yearly - Save $60/year (~17%)',
    cta: 'Get Early Bird (Yearly)',
    badge: 'Best value',
    savings: 'Save $60/year vs monthly',
    note: 'Lock in lowest effective monthly rate',
    billing: 'yearly',
    features: sharedFeatures,
  },
]

onMounted(() => {
  trackEvent('pricing_view', { source: 'pricing_page' })
})

function selectPlan(p) {
  const billing = p.billing || 'monthly'
  const nextStep = page.props.auth?.user ? 'checkout' : 'register'
  trackEvent('plan_select', { plan: 'earlybird', billing, source: 'pricing_page', next_step: nextStep })

  if (nextStep === 'checkout') {
    trackEvent('checkout_start', { plan: 'earlybird', billing, source: 'pricing_page' })
  }

  const url = page.props.auth?.user
    ? `/checkout?plan=earlybird&billing=${encodeURIComponent(billing)}`
    : `/register?plan=earlybird&billing=${encodeURIComponent(billing)}`

  window.location.assign(url)
}

const faqJsonLd = {
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "What does intraday snapshots mean?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "The app refreshes key strike-based metrics once per minute during market hours. It is fast enough for decision making without noisy tick updates."
      }
    },
    {
      "@type": "Question",
      "name": "Does it support all symbols?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Yes. The platform is built to work across the symbols you enable and scan through, including watchlist workflows."
      }
    },
    {
      "@type": "Question",
      "name": "Is this financial advice?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "No. This is analytics tooling only. Always trade at your own risk."
      }
    },
    {
      "@type": "Question",
      "name": "How is this different from a single chart tool?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Most tools show one angle. This stacks flow, levels, and positioning so you can validate a setup instead of guessing."
      }
    },
    {
      "@type": "Question",
      "name": "Is it good for zero days to expiry?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Yes. Intraday snapshots and strike-based flow are useful for same-day trading, while end-of-day levels help frame the map."
      }
    },
    {
      "@type": "Question",
      "name": "Do you have scanners?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Yes. Watchlist scanning helps you find wall hits and key level proximity quickly so you can focus only where it matters."
      }
    },
    {
      "@type": "Question",
      "name": "Can I cancel anytime?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Yes. You can cancel from your account. You keep access through the end of your billing period."
      }
    },
    {
      "@type": "Question",
      "name": "Do you offer yearly billing?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "Yes. Yearly billing is available and is discounted compared to monthly."
      }
    }
  ]
}
</script>
