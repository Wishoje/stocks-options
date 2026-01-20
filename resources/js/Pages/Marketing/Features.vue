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
    <!-- HERO -->
    <section class="mx-auto max-w-7xl px-4 pt-6 sm:px-6 lg:px-8">
      <div class="grid gap-10 lg:grid-cols-2 lg:items-center">
        <div>
          <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">
            Features built for fast decision making
          </h1>

          <p class="mt-4 text-sm text-white/60">
            Built around a simple workflow. Scan your watchlist, validate levels with positioning, then execute with more confidence.
          </p>

          <div class="mt-5 flex flex-wrap gap-2 text-xs text-white/60">
            <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1">Supports all symbols</span>
            <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1">Intraday snapshots</span>
            <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1">End of day positioning</span>
            <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1">Scanners and tools</span>
          </div>

          <div class="mt-7 flex flex-wrap gap-3">
            <a
              href="#features"
              class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm hover:bg-white/10"
            >
              View all features
            </a>

            <Link
              :href="route('register')"
              class="rounded-xl bg-gradient-to-r from-cyan-500 to-blue-600 px-4 py-2 text-sm font-semibold shadow-lg shadow-cyan-500/20 hover:opacity-95"
            >
              Start free
            </Link>

            <Link
              :href="route('pricing')"
              class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold hover:bg-white/10"
            >
              Pricing
            </Link>
          </div>
        </div>

        <div class="relative">
          <div class="absolute -inset-6 rounded-[32px] bg-gradient-to-br from-cyan-500/20 to-blue-600/10 blur-2xl" />
          <div class="relative overflow-hidden rounded-[28px] border border-white/10 bg-white/5 shadow-2xl shadow-black/40">
            <img src="/marketing/liveFlow.png" alt="Live flow preview" class="w-full" />
          </div>
        </div>
      </div>
    </section>

    <!-- ALL FEATURES (uniform rows) -->
    <section id="features" class="mx-auto max-w-7xl px-4 pt-14 sm:px-6 lg:px-8">
      <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
        <div>
          <h2 class="text-2xl font-semibold tracking-tight">All features</h2>
          <p class="mt-2 text-sm text-white/60 max-w-2xl">
            Everything included in the terminal — flow, levels, positioning, scanners, and risk tools.
          </p>
        </div>

        <div class="mt-4 md:mt-0 flex gap-3">
          <Link
            :href="route('register')"
            class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-cyan-500 to-blue-600 px-4 py-2 text-sm font-semibold shadow-lg shadow-cyan-500/20 hover:opacity-95"
          >
            Start free
          </Link>

          <Link
            :href="route('pricing')"
            class="inline-flex items-center justify-center rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold hover:bg-white/10"
          >
            Pricing
          </Link>
        </div>
      </div>

      <div class="mt-10 space-y-6">
        <FeatureRow
          v-for="(t, idx) in features"
          :key="t.title"
          :flip="idx % 2 === 1"
          :title="t.title"
          :kicker="t.kicker"
          :desc="t.desc"
          :bullets="t.bullets"
          :src="t.src"
          :alt="t.alt"
        />
      </div>
    </section>

    <!-- FAQ -->
    <section class="mx-auto max-w-7xl px-4 pt-16 pb-16 sm:px-6 lg:px-8">
      <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
        <div class="flex items-end justify-between gap-6">
          <div>
            <h3 class="text-xl font-semibold">FAQ</h3>
            <p class="mt-2 text-sm text-white/60">
              Quick answers to the questions people ask before subscribing.
            </p>
          </div>

          <Link :href="route('pricing')" class="hidden md:inline-flex text-sm text-cyan-300 hover:text-cyan-200">
            Pricing
          </Link>
        </div>

        <div class="mt-6 grid gap-4 md:grid-cols-2">
          <div
            v-for="f in faqs"
            :key="f.q"
            class="rounded-2xl border border-white/10 bg-black/20 p-5"
          >
            <div class="text-sm font-semibold text-white/90">{{ f.q }}</div>
            <div class="mt-2 text-sm text-white/60">{{ f.a }}</div>
          </div>
        </div>
      </div>
    </section>

    <!-- CTA -->
    <section class="mx-auto max-w-7xl px-4 pb-20 sm:px-6 lg:px-8">
      <div class="rounded-3xl border border-white/10 bg-gradient-to-br from-white/10 to-white/5 p-8 text-center">
        <h3 class="text-2xl font-semibold">Ready to use the full terminal?</h3>
        <p class="mt-2 text-sm text-white/60">
          Create an account and explore the tools. Upgrade when you are ready.
        </p>
        <div class="mt-6 flex flex-wrap justify-center gap-3">
          <Link
            :href="route('register')"
            class="rounded-xl bg-gradient-to-r from-cyan-500 to-blue-600 px-5 py-3 text-sm font-semibold shadow-lg shadow-cyan-500/20 hover:opacity-95"
          >
            Start free
          </Link>
          <Link
            :href="route('pricing')"
            class="rounded-xl border border-white/10 bg-white/5 px-5 py-3 text-sm font-semibold hover:bg-white/10"
          >
            View pricing
          </Link>
        </div>
      </div>
    </section>
  </MarketingLayout>
</template>

<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3'
import MarketingLayout from '@/Layouts/MarketingLayout.vue'
import FeatureRow from '@/Components/Marketing/FeatureRow.vue'

const page = usePage()
/**
 * NOTE (recommended):
 * In FeatureRow.vue, wrap the <ul> in v-if="bullets?.length"
 * so rows without bullets don’t render an empty list.
 */

const features = [
  {
    kicker: 'Flow',
    title: 'Live flow by strike',
    desc: 'See where contracts are trading right now. Built for quick reads without tick noise.',
    bullets: ['Intraday snapshots', 'Call and put pressure by strike', 'Fast context for entries and exits'],
    src: '/marketing/liveFlow.png',
    alt: 'Live flow by strike',
  },
  {
    kicker: 'Flow',
    title: 'Premium live by strike',
    desc: 'Notional premium makes big trades stand out. Spot where size is concentrating.',
    bullets: ['Call and put premium', 'Focus on the main activity band', 'Great for momentum and wall confirmation'],
    src: '/marketing/premiumLive.png',
    alt: 'Premium live by strike',
  },
  {
    kicker: 'Levels',
    title: 'Net GEX by strike',
    desc: 'Map the level landscape and identify likely reaction zones before the open.',
    bullets: ['Clear level clusters', 'Zoom and focus controls', 'Useful for daily planning'],
    src: '/marketing/gex.png',
    alt: 'Net GEX by strike',
  },
  {
    kicker: 'Positioning',
    title: 'Dealer positioning (DEX)',
    desc: 'Understand positioning by expiry to see where pressure and hedging may matter most.',
    bullets: ['DEX by expiry', 'Top positive and negative expiries', 'Adds context behind levels'],
    src: '/marketing/dex.png',
    alt: 'Dealer positioning',
  },
  {
    kicker: 'Expiry',
    title: 'Expiry pressure and pin risk',
    desc: 'Identify expiries that matter and where pin risk is elevated near key strikes.',
    bullets: ['Pin risk score', 'Top strike clusters near spot', 'Useful around weekly expirations'],
    src: '/marketing/pin.png',
    alt: 'Expiry pressure and pin risk',
  },
  {
    kicker: 'Stats',
    title: 'PCR live by strike',
    desc: 'A fast sentiment lens. See skewed put/call activity by strike at a glance.',
    bullets: ['Strike-based PCR', 'Find imbalances quickly', 'Pairs well with flow and levels'],
    src: '/marketing/pcr.png',
    alt: 'PCR live by strike',
  },
  {
    kicker: 'Open Interest',
    title: 'OI and volume distribution',
    desc: 'Big picture positioning in one view. Confirm when a move is supported by participation.',
    bullets: ['Call and put OI percent', 'Total OI and volume', 'Distribution charts for quick validation'],
    src: '/marketing/oiAndVolChart.png',
    alt: 'Open interest and volume distribution',
  },
  {
    kicker: 'Open Interest',
    title: 'Delta OI by strike',
    desc: 'See where positioning changed so you can separate new interest from old open interest.',
    bullets: ['Call and put delta OI', 'Focus band support', 'Pairs well with wall detection'],
    src: '/marketing/oiByStrike.png',
    alt: 'Delta OI by strike',
  },
  {
    kicker: 'Volatility',
    title: 'IV skew and curvature',
    desc: 'A fast volatility regime read. Helps frame tail risk and strategy selection.',
    bullets: ['Put and call IV at 25 delta', 'Skew and curvature signals', 'Useful for strategy selection'],
    src: '/marketing/ivSkew.png',
    alt: 'IV skew and curvature',
  },
  {
    kicker: 'Tools',
    title: 'Options calculator',
    desc: 'Model a trade before you take it. Know the move needed, risk, and payoff shape.',
    bullets: ['P&L vs price and time', 'Payoff tables', 'Breakeven and max loss clarity'],
    src: '/marketing/calculator.png',
    alt: 'Options calculator',
  },

  // Previously "more tools" — now same sizing
  {
    kicker: 'Scanner',
    title: 'GEX wall scanner',
    desc: 'Scan your watchlist and instantly surface symbols sitting near key call and put walls.',
    bullets: ['Watchlist-wide scan', 'Call/put wall-hit labels', 'Jump into symbol fast'],
    src: '/marketing/scanner.png',
    alt: 'GEX wall scanner',
  },
  {
    kicker: 'Watchlist',
    title: 'Watchlist and pin alerts',
    desc: 'Manage symbols and track pin scores so you can focus on what matters.',
    bullets: ['Quick symbol switching', 'Pin score visibility', 'Built for daily workflows'],
    src: '/marketing/watchlist-4.png',
    alt: 'Watchlist',
  },
  {
    kicker: 'Unusual',
    title: 'Unusual activity table',
    desc: 'Flags strikes where activity is abnormal so you can investigate what’s driving the move.',
    bullets: ['Find abnormal prints fast', 'Filters and sorting', 'Great for follow-through ideas'],
    src: '/marketing/ua.png',
    alt: 'Unusual activity',
  },
  {
    kicker: 'Signals',
    title: 'Q Score dashboard',
    desc: 'A blended read of key signals so you can stay constructive, selective, or defensive with structure.',
    bullets: ['Single-glance read', 'Multiple signals combined', 'Helps reduce noise'],
    src: '/marketing/qscore.png',
    alt: 'Q score',
  },
  {
    kicker: 'Volatility',
    title: 'Variance risk premium (VRP)',
    desc: 'Compare implied vs realized volatility to understand the regime and pricing.',
    bullets: ['Regime context', 'IV vs RV comparison', 'Useful for strategy selection'],
    src: '/marketing/risk.png',
    alt: 'Variance risk premium',
  },
  {
    kicker: 'Volatility',
    title: 'Term structure',
    desc: 'See implied vol across expiries to frame contango, backwardation, and risk.',
    bullets: ['IV across expiries', 'Spot curve shape shifts', 'Context for calendar risk'],
    src: '/marketing/term.png',
    alt: 'Term structure',
  },
  {
    kicker: 'Seasonality',
    title: 'Seasonality snapshot',
    desc: 'A simple forward return read to add context and avoid fighting the tape blindly.',
    bullets: ['Quick seasonal bias', 'Forward-return context', 'Best used as a filter'],
    src: '/marketing/seasonality.png',
    alt: 'Seasonality',
  },
  {
    kicker: 'Flow',
    title: 'Delta volume by strike',
    desc: 'See where volume is changing by strike to spot shifting interest and pressure.',
    bullets: ['Highlights changing demand', 'Pairs well with walls', 'Good for intraday shifts'],
    src: '/marketing/volBYStrike.png',
    alt: 'Delta volume by strike',
  },
  {
    kicker: 'Flow',
    title: 'Vol over OI by strike',
    desc: 'Find where today’s volume is large relative to existing open interest.',
    bullets: ['Spot “new interest” areas', 'Easy relative activity read', 'Useful for follow-through'],
    src: '/marketing/volOiByStrike.png',
    alt: 'Vol over OI by strike',
  },
  {
    kicker: 'Navigation',
    title: 'Timeframes and expiries navigator',
    desc: 'Switch timeframe, toggle intraday vs EOD, and jump between expiries fast.',
    bullets: ['Fast timeframe switching', 'Expiry jumping', 'Smooth workflow between tools'],
    src: '/marketing/overviewNav.jpg',
    alt: 'Timeframes and expiries navigation',
  },
  {
    kicker: 'Levels',
    title: 'Net GEX by strike',
    desc: 'Map the level landscape and identify likely reaction zones before the open.',
    bullets: ['Clear level clusters', 'Zoom and focus controls', 'Useful for daily planning'],
    src: '/marketing/gex.png',
    alt: 'Net GEX by strike',
    learn: { label: 'What is GEX?', href: '/glossary/gex' },
  },
]

const faqs = [
  {
    q: 'What does intraday snapshots mean?',
    a: 'The app refreshes key strike-based metrics once per minute during market hours. It is fast enough for decision making without noisy tick updates.',
  },
  {
    q: 'Does it support all symbols?',
    a: 'Yes. The platform is built to work across the symbols you enable and scan through, including watchlist workflows.',
  },
  { q: 'Is this financial advice?', a: 'No. This is analytics tooling only. Always trade at your own risk.' },
  {
    q: 'How is this different from a single chart tool?',
    a: 'Most tools show one angle. This stacks flow, levels, and positioning so you can validate a setup instead of guessing.',
  },
  {
    q: 'Is it good for zero days to expiry?',
    a: 'Yes. Intraday snapshots and strike-based flow are useful for same-day trading, while end-of-day levels help frame the map.',
  },
  {
    q: 'Do you have scanners?',
    a: 'Yes. Watchlist scanning helps you find wall hits and key level proximity quickly so you can focus only where it matters.',
  },
  { q: 'Can I cancel anytime?', a: 'Yes. You can cancel from your account. You keep access through the end of your billing period.' },
  { q: 'Do you offer yearly billing?', a: 'Yes. Yearly billing is available and is discounted compared to monthly.' },
]

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

const title = 'GexOptions Features — Flow, GEX Levels, DEX, Scanners, VRP & Term Structure'
const description =
  'Explore all tools in the GexOptions terminal: live flow by strike, premium, net GEX levels, dealer positioning (DEX), expiry pressure, unusual activity, VRP, term structure, and more.'
const url = 'https://gexoptions.com/features'
</script>
