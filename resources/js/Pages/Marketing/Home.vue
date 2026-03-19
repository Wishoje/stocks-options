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
    <!-- HERO (copy-first) -->
    <section class="relative overflow-hidden">
      <!-- background -->
      <div class="pointer-events-none absolute inset-0 -z-10">
        <div class="absolute inset-0 bg-[#070A12]" />
        <div
          class="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(34,211,238,.20),transparent_45%),radial-gradient(circle_at_80%_15%,rgba(59,130,246,.18),transparent_40%),radial-gradient(circle_at_55%_85%,rgba(99,102,241,.14),transparent_45%)]"
        />
        <div
          class="absolute inset-0 opacity-[0.18] bg-[radial-gradient(#ffffff_1px,transparent_1px)] [background-size:26px_26px]"
        />
        <div class="absolute inset-0 bg-gradient-to-b from-black/10 via-black/40 to-black/80" />
      </div>

      <div class="mx-auto max-w-[1440px] px-4 pt-2 sm:px-6 lg:px-8">
        <div class="mt-4 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs text-white/70">
          <span class="h-2 w-2 rounded-full bg-green-400 shadow-[0_0_25px_rgba(34,197,94,.45)]" />
          Intraday snapshots and end of day positioning in one terminal.
        </div>

        <div class="mt-5 grid gap-8 xl:grid-cols-12 xl:items-start xl:gap-10">
          <!-- headline + pitch -->
          <div class="xl:col-span-7 2xl:col-span-6">
            <div class="max-w-3xl">
              <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">
                Know the important levels
                <span class="text-cyan-200">before the open</span>
                and confirm the move
                <span class="text-white/70">before you click</span>.
              </h1>

              <p class="mt-4 text-base text-white/70">
                Map likely support and resistance, see where hedging pressure may matter, and track options flow in one
                terminal instead of bouncing between six tabs. Built for faster prep and cleaner intraday decisions.
              </p>

              <!-- persuasion bullets -->
              <ul class="mt-5 grid gap-2 text-sm text-white/70 sm:grid-cols-2">
                <li class="flex gap-2">
                  <span class="text-cyan-200">&#10003;</span>
                  Plan the key levels before the bell
                </li>
                <li class="flex gap-2">
                  <span class="text-cyan-200">&#10003;</span>
                  Stop guessing where price may stall or squeeze
                </li>
                <li class="flex gap-2">
                  <span class="text-cyan-200">&#10003;</span>
                  See flow and positioning in one place
                </li>
                <li class="flex gap-2">
                  <span class="text-cyan-200">&#10003;</span>
                  Define risk and sizing in seconds
                </li>
              </ul>

              <!-- CTAs -->
              <div class="mt-7 flex flex-col gap-3 sm:flex-row">
                <Link
                  :href="registerWithPlanUrl"
                  @click="trackHeroCta('home_hero_primary')"
                  class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-cyan-500 to-blue-600 px-5 py-3 text-sm font-semibold shadow-lg shadow-cyan-500/20 hover:opacity-95"
                >
                  Start 7-Day Free Trial
                </Link>

                <div class="flex items-center text-sm text-white/65">
                  <Link :href="route('pricing')" @click="trackHeroCta('home_hero_secondary_pricing')" class="text-cyan-300 hover:text-cyan-200">
                    See full pricing ->
                  </Link>
                </div>
              </div>

              <!-- trust line -->
              <div class="mt-4 flex flex-wrap gap-2 text-xs text-white/50">
                <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1">No financial advice</span>
                <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1">Built for repeatable prep</span>
                <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1">Browser-based, no install</span>
              </div>

            </div>
          </div>

          <!-- Hero conversion panel -->
          <div class="xl:col-span-5 2xl:col-span-6">
            <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
              <div class="text-[11px] uppercase tracking-[0.18em] text-cyan-200/80">
                Why traders start here
              </div>
              <h3 class="mt-3 text-2xl font-semibold leading-tight text-white">
                Built for a trade plan, not just another chart.
              </h3>
              <p class="mt-3 text-sm text-white/65">
                See what matters before the open, confirm it at the open, and stay focused during the session without
                tab hopping between disconnected tools.
              </p>

              <ul class="mt-5 space-y-2.5 text-sm text-white/70">
                <li class="flex gap-2.5">
                  <span class="text-cyan-200">&#10003;</span>
                  Find likely support and resistance before price gets there
                </li>
                <li class="flex gap-2.5">
                  <span class="text-cyan-200">&#10003;</span>
                  See when dealer hedging may amplify the move
                </li>
                <li class="flex gap-2.5">
                  <span class="text-cyan-200">&#10003;</span>
                  Catch aggressive options flow faster
                </li>
                <li class="flex gap-2.5">
                  <span class="text-cyan-200">&#10003;</span>
                  Build one repeatable workflow for prep and execution
                </li>
              </ul>

              <div class="mt-5 grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-white/10 bg-black/20 p-3">
                  <div class="text-sm font-semibold">Before the open</div>
                  <div class="mt-1 text-xs text-white/60">Map the levels and expiries that deserve attention.</div>
                </div>
                <div class="rounded-xl border border-white/10 bg-black/20 p-3">
                  <div class="text-sm font-semibold">During the session</div>
                  <div class="mt-1 text-xs text-white/60">Use flow and scanner signals to confirm or reject the setup.</div>
                </div>
              </div>

              <div class="mt-5 rounded-2xl border border-cyan-300/25 bg-cyan-400/10 p-4">
                <div class="text-sm font-semibold text-white">From $29.99/mo</div>
                <div class="mt-1 text-xs text-white/70">
                  7-day trial. Full access from day one. Cancel anytime.
                </div>
                <Link
                  :href="route('pricing')"
                  @click="trackHeroCta('home_hero_offer_pricing')"
                  class="mt-3 inline-flex text-sm font-semibold text-cyan-200 hover:text-cyan-100"
                >
                  Compare plans ->
                </Link>
              </div>
            </div>
          </div>
        </div>

        <!-- Core tools header -->
        <div class="mt-2 pb-6">
          <div class="flex items-end justify-between gap-6">
            <div>
              <h2 class="text-xl font-semibold">What traders use most</h2>
              <p class="mt-2 text-sm text-white/60">
                Start with the outcomes first. The technical views below are the tools most traders use to build that
                workflow.
              </p>
            </div>
          </div>

          <!-- Bigger ShotCards (your ShotCard component should be the "bigger" version) -->
          <div class="mt-6 grid gap-5 2xl:grid-cols-2">
            <ShotCard
              title="Catch aggressive flow without watching every tick"
              pill="Live Flow"
              :bullets="[
                '1-minute snapshots keep the view readable',
                'See where call and put pressure is building',
                'Useful for same-day confirmation and follow-through'
              ]"
              thumb="/marketing/liveFlow.png"
              thumbAlt="Live Flow by Strike"
            />

            <ShotCard
              title="Mark likely support and resistance before price gets there"
              pill="Net GEX"
              :bullets="[
                'Spot likely reaction zones before the session starts',
                'Use dealer context to understand why they matter',
                'Build tomorrow\'s map in minutes'
              ]"
              thumb="/marketing/gex.png"
              thumbAlt="Net GEX by Strike"
            />

            <ShotCard
              title="Define the trade before you enter"
              pill="Calculator"
              thumbFit="cover"
              thumbObjectPosition="object-top"
              :bullets="[
                'Model the move, payoff, and max loss fast',
                'Use payoff tables to plan entries and exits',
                'Size risk before the trade is live'
              ]"
              thumb="/marketing/calculator.png"
              thumbAlt="Options Calculator"
            />

            <ShotCard
              title="See when hedging pressure may help or hurt the move"
              pill="DEX"
              thumbFit="cover"
              thumbObjectPosition="object-top"
              :bullets="[
                'See positioning by expiry instead of guessing',
                'Add context behind levels and squeezes',
                'Spot the expiries that actually matter'
              ]"
              thumb="/marketing/dex.png"
              thumbAlt="Dealer Positioning (DEX)"
            />
          </div>

          <!-- "and more..." row -->
          <div class="mt-6 rounded-3xl border border-white/10 bg-white/5 p-5">
            <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
              <div class="text-sm text-white/70">
                <span class="text-white/90 font-semibold">Then go deeper when you need it.</span>
                Unusual activity, watchlist scanners, expiry tools, premium views, volatility context, and more are all
                included.
              </div>
            </div>
          </div>

          <!-- value cards -->
          <div class="mt-8">
            <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
              <div class="max-w-3xl">
                <h2 class="text-xl font-semibold">Why traders stick with it</h2>
                <p class="mt-2 text-sm text-white/60">
                  Most platforms give you one angle at a time. GexOptions stacks
                  <span class="text-white/75">levels</span>,
                  <span class="text-white/75">positioning</span>, and
                  <span class="text-white/75">flow</span> so you can make a decision with context instead of reacting
                  blindly.
                </p>
              </div>

              <div class="mt-5 grid gap-3 md:grid-cols-3">
                <ValueCard
                  title="Faster daily prep"
                  desc="Go from watchlist to clear plan in minutes instead of checking names one by one."
                />
                <ValueCard
                  title="Fewer impulse trades"
                  desc="Use levels, positioning, and flow together before acting on a move."
                />
                <ValueCard
                  title="Better risk discipline"
                  desc="Model payoff and sizing before entry so risk is defined, not guessed."
                />
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- SCANNER (balanced, not huge) -->
    <section class="mx-auto max-w-[1440px] px-4 pt-2 sm:px-6 lg:px-8">
      <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
        <div class="flex items-end justify-between gap-6">
          <div>
            <h2 class="text-2xl font-semibold tracking-tight">Find what matters on your watchlist in seconds</h2>
            <p class="mt-2 text-sm text-white/60 max-w-3xl">
              Instead of checking symbols one by one, scan the whole list and surface the names sitting near important
              levels. Click a hit and jump straight into that symbol's map and flow.
            </p>
          </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-12 lg:items-start">
          <!-- left: copy -->
          <div class="lg:col-span-5">
            <div class="rounded-2xl border border-white/10 bg-black/20 p-5">
              <div class="text-sm text-white/70">
                <span class="text-white/90 font-semibold">Cut the tab hopping:</span>
                scan everything at once, spot which names are near the important levels, and spend time only where
                there is an actual setup.
              </div>

              <ul class="mt-4 space-y-2.5 text-sm text-white/70">
                <li class="flex gap-2.5">
                  <span class="mt-2 h-1.5 w-1.5 rounded-full bg-cyan-300/80" />
                  Scan the watchlist in one pass
                </li>
                <li class="flex gap-2.5">
                  <span class="mt-2 h-1.5 w-1.5 rounded-full bg-cyan-300/80" />
                  Surface the names sitting near key levels
                </li>
                <li class="flex gap-2.5">
                  <span class="mt-2 h-1.5 w-1.5 rounded-full bg-cyan-300/80" />
                  Jump straight into the symbol that deserves attention
                </li>
              </ul>

              <div class="mt-5 flex flex-col gap-2 sm:flex-row">
                <Link
                  :href="route('features')"
                  class="inline-flex items-center justify-center rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-white/80 hover:bg-white/10"
                >
                  View scanner features
                </Link>

                <Link
                  :href="registerWithPlanUrl"
                  @click="trackHeroCta('home_scanner_primary')"
                  class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-cyan-500 to-blue-600 px-4 py-2 text-sm font-semibold shadow-lg shadow-cyan-500/20 hover:opacity-95"
                >
                  Start Free
                </Link>
              </div>
            </div>
          </div>

          <!-- right: image (constrained) -->
          <div class="lg:col-span-7">
            <div class="overflow-hidden rounded-2xl border border-white/10 bg-black/20">
              <div class="flex items-center justify-between border-b border-white/10 px-4 py-2">
                <div class="text-xs text-white/70">GEX Wall Scanner (Watchlist)</div>
                <div class="text-[11px] text-white/50">Scanner</div>
              </div>

              <div class="bg-black/30 p-3">
                <img
                  src="/marketing/terminal_scanner.png"
                  alt="Watchlist scanner"
                  class="w-full object-contain rounded-xl"
                  loading="lazy"
                />
              </div>

              <div class="px-4 pb-4">
                <div class="mt-2 grid gap-2 sm:grid-cols-3">
                  <div class="rounded-xl border border-white/10 bg-white/5 p-3">
                    <div class="text-xs font-semibold">One scan</div>
                    <div class="mt-1 text-xs text-white/60">Run the whole watchlist at once.</div>
                  </div>
                  <div class="rounded-xl border border-white/10 bg-white/5 p-3">
                    <div class="text-xs font-semibold">Level proximity</div>
                    <div class="mt-1 text-xs text-white/60">See which names need attention.</div>
                  </div>
                  <div class="rounded-xl border border-white/10 bg-white/5 p-3">
                    <div class="text-xs font-semibold">Faster focus</div>
                    <div class="mt-1 text-xs text-white/60">Spend time only on valid setups.</div>
                  </div>
                </div>
              </div>
            </div>

            <div class="mt-3 text-xs text-white/50">
              Want to see everything?
              <Link :href="route('features')" class="text-cyan-300 hover:text-cyan-200">
                Explore all features ->
              </Link>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="mx-auto max-w-[1440px] px-4 pt-14 sm:px-6 lg:px-8">
      <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
        <h2 class="text-2xl font-semibold tracking-tight">A practical example: planning SPY before the open</h2>
        <p class="mt-2 text-sm text-white/60 max-w-3xl">
          The value is not the chart itself. It is having a plan before price reaches the level and a way to confirm or
          reject that plan once the session starts.
        </p>

        <div class="mt-6 grid gap-4 lg:grid-cols-3">
          <div class="rounded-2xl border border-white/10 bg-black/20 p-5">
            <div class="text-sm font-semibold text-white/90">1. Mark the map before the bell</div>
            <ul class="mt-3 space-y-2 text-sm text-white/65">
              <li>Identify likely support and resistance</li>
              <li>Note which expiries may matter most</li>
              <li>Write down the levels that would change your bias</li>
            </ul>
            <div class="mt-3 text-xs text-white/50">Outcome: you start the day with a plan instead of reacting late.</div>
          </div>

          <div class="rounded-2xl border border-white/10 bg-black/20 p-5">
            <div class="text-sm font-semibold text-white/90">2. Confirm or reject the move at the open</div>
            <ul class="mt-3 space-y-2 text-sm text-white/65">
              <li>Watch whether aggressive flow appears at the planned level</li>
              <li>See if price is being absorbed, rejected, or pushed through</li>
              <li>Stay selective when flow and positioning disagree</li>
            </ul>
            <div class="mt-3 text-xs text-white/50">Outcome: fewer random entries and cleaner confirmation.</div>
          </div>

          <div class="rounded-2xl border border-white/10 bg-black/20 p-5">
            <div class="text-sm font-semibold text-white/90">3. Stay focused during the session</div>
            <ul class="mt-3 space-y-2 text-sm text-white/65">
              <li>Scan the watchlist for names near important levels</li>
              <li>Jump directly into the symbols with real setup potential</li>
              <li>Ignore the noise from everything else</li>
            </ul>
            <div class="mt-3 text-xs text-white/50">Outcome: more attention on the names that actually matter.</div>
          </div>
        </div>
      </div>
    </section>

    <!-- PRICING -->
    <section class="mx-auto max-w-[1440px] px-4 pt-16 sm:px-6 lg:px-8">
      <div class="flex items-end justify-between gap-6">
        <div>
          <h2 class="text-2xl font-semibold tracking-tight">Simple pricing</h2>
          <p class="mt-2 text-sm text-white/60">Limited Early Bird pricing. Choose monthly or yearly (save ~17%).</p>
        </div>

        <Link :href="route('pricing')" @click="trackHeroCta('home_pricing_section_link')" class="hidden text-sm text-cyan-300 hover:text-cyan-200 md:inline-flex">
          Full pricing ->
        </Link>
      </div>

      <div class="mt-8">
        <PricingTable :plans="plans" @select="selectPlan" />
      </div>
    </section>

    <!-- FAQ -->
    <section class="mx-auto max-w-[1440px] px-4 pt-16 pb-16 sm:px-6 lg:px-8">
      <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
        <h3 class="text-xl font-semibold">FAQ</h3>
        <div class="mt-6 grid gap-4 md:grid-cols-2">
          <div
            v-for="(item, idx) in faqs"
            :key="idx"
            class="rounded-2xl border border-white/10 bg-white/5 p-5"
          >
            <div class="text-sm font-semibold">{{ item.q }}</div>
            <div class="mt-2 text-sm text-white/60">{{ item.a }}</div>
          </div>
        </div>
      </div>
    </section>
  </MarketingLayout>
</template>

<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3'
import MarketingLayout from '@/Layouts/MarketingLayout.vue'
import PricingTable from '@/Components/Marketing/PricingTable.vue'
import ShotCard from '@/Components/Marketing/ShotCard.vue'
import { trackEvent } from '@/lib/ga'

const page = usePage()

const title = 'GexOptions - Premarket Levels, Positioning, and Options Flow'
const description =
  'Map key levels before the open, confirm moves with positioning and options flow, and manage watchlists and risk in one trading terminal.'
const url = 'https://gexoptions.com/'
const registerWithPlanUrl = '/register?plan=earlybird&billing=monthly'

const sharedFeatures = [
  'All symbols',
  'Premarket level map + 1-minute intraday updates',
  'Net GEX levels + dealer positioning (DEX)',
  'Live flow + premium by strike',
  'Unusual activity + watchlist scanning',
  'Options calculator + payoff planning',
  'Volatility suite + expiry context',
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
    badge: 'Best value',
    savings: 'Save $60/year vs monthly',
    note: 'Lock in lowest effective monthly rate',
    cta: 'Get Early Bird (Yearly)',
    billing: 'yearly',
    features: sharedFeatures,
  },
]

function selectPlan(p) {
  const billing = p?.billing || 'monthly'
  const nextStep = page.props.auth?.user ? 'checkout' : 'register'
  trackEvent('plan_select', { plan: 'earlybird', billing, source: 'home_pricing', next_step: nextStep })
  if (nextStep === 'checkout') {
    trackEvent('checkout_start', { plan: 'earlybird', billing, source: 'home_pricing' })
  }

  const url = page.props.auth?.user
    ? `/checkout?plan=earlybird&billing=${encodeURIComponent(billing)}`
    : `/register?plan=earlybird&billing=${encodeURIComponent(billing)}`

  window.location.assign(url)
}

function trackHeroCta(location) {
  const pricingLocations = new Set([
    'home_hero_secondary_pricing',
    'home_hero_offer_pricing',
    'home_pricing_section_link',
  ])
  trackEvent('hero_cta_click', {
    location,
    destination: pricingLocations.has(location) ? 'pricing' : 'register_with_plan',
  })
}

const ValueCard = {
  props: ['title', 'desc'],
  template: `
    <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
      <div class="text-sm font-semibold text-white/90">{{ title }}</div>
      <div class="mt-1 text-sm text-white/60">{{ desc }}</div>
    </div>
  `,
}

const faqs = [
  {
    q: 'What does 1 minute intraday snapshots mean?',
    a: 'We refresh the intraday view once per minute during market hours so you can track meaningful shifts without noisy tick updates.',
  },
  {
    q: 'Does it support all symbols?',
    a: 'Yes. You can run the platform on any symbol you enable and switch fast via watchlists.',
  },
  {
    q: 'Is this financial advice?',
    a: 'No. This is analytics tooling only. You are responsible for your own trading decisions and risk.',
  },
  {
    q: 'What is the difference between intraday and end of day views?',
    a: 'Intraday shows flow and pressure updating during the session. End of day shows positioning and levels used for planning and context.',
  },
  {
    q: 'How often is end of day data updated?',
    a: 'End of day data updates after the close once the data is finalized, so your next day plan is based on stable numbers.',
  },
  {
    q: 'What markets does this cover?',
    a: 'It is designed for US listed equities and ETFs with options chains. (SPY, QQQ, IWM, and single names.)',
  },
  {
    q: 'Do I need to install anything?',
    a: 'No. It runs in your browser. Create an account and you can start immediately.',
  },
  {
    q: 'Can I cancel anytime?',
    a: 'Yes. You can cancel whenever you want. If you are on early access manual checkout, we will handle it the same way.',
  },
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
</script>

