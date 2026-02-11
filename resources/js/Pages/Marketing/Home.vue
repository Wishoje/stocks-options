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
                Trade with clarity:
                <span class="text-cyan-200">levels</span>,
                <span class="text-blue-200">positioning</span>,
                and flow<span class="text-white/70"> in one place</span>.
              </h1>

              <p class="mt-4 text-base text-white/70">
                Stop guessing. Spot the levels that matter, confirm with positioning, and follow real flow while it's happening.
                Built for fast daily prep and more confident execution.
              </p>

              <!-- persuasion bullets -->
              <ul class="mt-5 grid gap-2 text-sm text-white/70 sm:grid-cols-2">
                <li class="flex gap-2">
                  <span class="text-cyan-200">&#10003;</span>
                  Find real "walls" and reaction zones faster
                </li>
                <li class="flex gap-2">
                  <span class="text-cyan-200">&#10003;</span>
                  See dealer positioning context by expiry (DEX)
                </li>
                <li class="flex gap-2">
                  <span class="text-cyan-200">&#10003;</span>
                  Track intraday flow without tick noise (1-min snapshots)
                </li>
                <li class="flex gap-2">
                  <span class="text-cyan-200">&#10003;</span>
                  Size risk with payoff + P&L tools in seconds
                </li>
              </ul>

              <div v-if="showGlossary" class="mt-6 rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="text-sm font-semibold text-white/90">New to these terms?</div>
                <div class="mt-1 text-sm text-white/60">
                  Start with the quick glossary: GEX, DEX, 0DTE, Pin Risk, VRP, Term Structure.
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                  <a class="rounded-full border border-white/10 bg-black/20 px-3 py-1 text-xs text-white/70 hover:bg-white/10" href="/glossary/gex">GEX</a>
                  <a class="rounded-full border border-white/10 bg-black/20 px-3 py-1 text-xs text-white/70 hover:bg-white/10" href="/glossary/dex">DEX</a>
                  <a class="rounded-full border border-white/10 bg-black/20 px-3 py-1 text-xs text-white/70 hover:bg-white/10" href="/glossary/0dte">0DTE</a>
                  <a class="rounded-full border border-white/10 bg-black/20 px-3 py-1 text-xs text-white/70 hover:bg-white/10" href="/glossary/pin-risk">Pin Risk</a>
                  <a class="rounded-full border border-white/10 bg-black/20 px-3 py-1 text-xs text-white/70 hover:bg-white/10" href="/glossary/vrp">VRP</a>
                  <a class="rounded-full border border-white/10 bg-black/20 px-3 py-1 text-xs text-white/70 hover:bg-white/10" href="/glossary/term-structure">Term Structure</a>
                </div>
              </div>

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
                <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1">Fast scanning UI</span>
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
                Built for decisions, not chart collecting.
              </h3>
              <p class="mt-3 text-sm text-white/65">
                Get the full workflow in one terminal: scan, validate levels, confirm with positioning, and execute with clearer risk.
              </p>

              <ul class="mt-5 space-y-2.5 text-sm text-white/70">
                <li class="flex gap-2.5">
                  <span class="text-cyan-200">&#10003;</span>
                  1-minute intraday updates during market hours
                </li>
                <li class="flex gap-2.5">
                  <span class="text-cyan-200">&#10003;</span>
                  7-day free trial with full platform access
                </li>
                <li class="flex gap-2.5">
                  <span class="text-cyan-200">&#10003;</span>
                  Equities and ETFs with options chains
                </li>
                <li class="flex gap-2.5">
                  <span class="text-cyan-200">&#10003;</span>
                  Secure Stripe checkout and cancel anytime
                </li>
              </ul>

              <div class="mt-5 grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-white/10 bg-black/20 p-3">
                  <div class="text-sm font-semibold">Daily prep in minutes</div>
                  <div class="mt-1 text-xs text-white/60">Scan the watchlist and map key levels fast.</div>
                </div>
                <div class="rounded-xl border border-white/10 bg-black/20 p-3">
                  <div class="text-sm font-semibold">Positioning confirmation</div>
                  <div class="mt-1 text-xs text-white/60">Validate setups with expiry-level dealer context.</div>
                </div>
              </div>

              <div class="mt-5 rounded-2xl border border-cyan-300/25 bg-cyan-400/10 p-4">
                <div class="text-sm font-semibold text-white">From $29.99/mo</div>
                <div class="mt-1 text-xs text-white/70">
                  7-day trial. Cancel anytime. Full features included.
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
              <h2 class="text-xl font-semibold">Core workflow tools</h2>
              <p class="mt-2 text-sm text-white/60">
                These are the "daily drivers" most traders start with. There are many more tools inside the platform.
              </p>
            </div>
          </div>

          <!-- Bigger ShotCards (your ShotCard component should be the "bigger" version) -->
          <div class="mt-6 grid gap-5 2xl:grid-cols-2">
            <ShotCard
              title="Intraday: Live Flow (1-min snapshots)"
              pill="Intraday"
              :bullets="[
                'Updated every 60 seconds during market hours',
                'Quickly see call vs put pressure by strike',
                'Great for 0DTE / same-day setups'
              ]"
              thumb="/marketing/liveFlow.png"
              thumbAlt="Live Flow by Strike"
            />

            <ShotCard
              title="EOD: Net GEX by Strike + Levels"
              pill="EOD"
              :bullets="[
                'Walls / zones where price tends to react',
                'Combine with DEX for positioning context',
                'Build tomorrow\'s plan in minutes'
              ]"
              thumb="/marketing/gex.png"
              thumbAlt="Net GEX by Strike"
            />

            <ShotCard
              title="Options Calculator"
              pill="Tools"
              thumbFit="cover"
              thumbObjectPosition="object-top"
              :bullets="[
                'Scenario modeling with P&L curve',
                'Payoff tables for easy planning',
                'Fast sizing for intraday pivots'
              ]"
              thumb="/marketing/calculator.png"
              thumbAlt="Options Calculator"
            />

            <ShotCard
              title="Dealer Positioning (DEX)"
              pill="EOD"
              thumbFit="cover"
              thumbObjectPosition="object-top"
              :bullets="[
                'See dealer delta positioning by expiry',
                'Add context behind levels and walls',
                'Spot expiries that actually matter'
              ]"
              thumb="/marketing/dex.png"
              thumbAlt="Dealer Positioning (DEX)"
            />
          </div>

          <!-- "and more..." row -->
          <div class="mt-6 rounded-3xl border border-white/10 bg-white/5 p-5">
            <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
              <div class="text-sm text-white/70">
                <span class="text-white/90 font-semibold">And this is just the surface.</span>
                Unusual Activity, Premium by Strike, Volatility suite (VRP/term/seasonality), expiry pressure, scanners,
                and more.
              </div>
            </div>
          </div>

          <!-- value cards -->
          <div class="mt-8">
            <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
              <div class="max-w-3xl">
                <h2 class="text-xl font-semibold">Built for traders who want structure</h2>
                <p class="mt-2 text-sm text-white/60">
                  Most tools show a single angle. GexOptions is designed to stack context:
                  <span class="text-white/75">flow</span> (what's happening now) +
                  <span class="text-white/75">levels</span> (where it matters) +
                  <span class="text-white/75">positioning</span> (why it moves).
                </p>
              </div>

              <div class="mt-5 grid gap-3 md:grid-cols-3">
                <ValueCard
                  title="Faster daily prep"
                  desc="Scan watchlist, identify walls, and build a level-based plan in minutes."
                />
                <ValueCard
                  title="Cleaner intraday decisions"
                  desc="One-minute snapshots reduce noise while still showing meaningful shifts."
                />
                <ValueCard
                  title="Better risk discipline"
                  desc="Use payoff + sizing tools so entries/exits are not guesswork."
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
            <h2 class="text-2xl font-semibold tracking-tight">Scan your whole watchlist in seconds</h2>
            <p class="mt-2 text-sm text-white/60 max-w-3xl">
              The scanner highlights symbols sitting near key GEX walls across timeframes - so you know what deserves
              attention. Click a hit and jump straight into that symbol's levels + flow.
            </p>
          </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-12 lg:items-start">
          <!-- left: copy -->
          <div class="lg:col-span-5">
            <div class="rounded-2xl border border-white/10 bg-black/20 p-5">
              <div class="text-sm text-white/70">
                <span class="text-white/90 font-semibold">Find wall hits fast:</span>
                scan everything, spot what's near key levels, and focus your time where it matters.
              </div>

              <ul class="mt-4 space-y-2.5 text-sm text-white/70">
                <li class="flex gap-2.5">
                  <span class="mt-2 h-1.5 w-1.5 rounded-full bg-cyan-300/80" />
                  Watchlist-wide scanning (no tab hopping)
                </li>
                <li class="flex gap-2.5">
                  <span class="mt-2 h-1.5 w-1.5 rounded-full bg-cyan-300/80" />
                  Quick wall-hit labels (call/put walls)
                </li>
                <li class="flex gap-2.5">
                  <span class="mt-2 h-1.5 w-1.5 rounded-full bg-cyan-300/80" />
                  Built for tomorrow's plan (and intraday focus)
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
                    <div class="text-xs font-semibold">Watchlist-wide</div>
                    <div class="mt-1 text-xs text-white/60">Scan everything at once.</div>
                  </div>
                  <div class="rounded-xl border border-white/10 bg-white/5 p-3">
                    <div class="text-xs font-semibold">Wall-hit labels</div>
                    <div class="mt-1 text-xs text-white/60">Hits surfaced fast.</div>
                  </div>
                  <div class="rounded-xl border border-white/10 bg-white/5 p-3">
                    <div class="text-xs font-semibold">Rapid prep</div>
                    <div class="mt-1 text-xs text-white/60">Built for planning.</div>
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
        <h2 class="text-2xl font-semibold tracking-tight">Common use cases</h2>
        <p class="mt-2 text-sm text-white/60 max-w-3xl">
          People do not buy features - they buy outcomes. Here are a few repeatable workflows inside the terminal.
        </p>

        <div class="mt-6 grid gap-4 lg:grid-cols-3">
          <div class="rounded-2xl border border-white/10 bg-black/20 p-5">
            <div class="text-sm font-semibold text-white/90">0DTE Fade Reversal</div>
            <ul class="mt-3 space-y-2 text-sm text-white/65">
              <li>Scan for GEX walls near spot</li>
              <li>Confirm premium exhaustion in flow</li>
              <li>Validate with DEX / expiry pressure</li>
            </ul>
            <div class="mt-3 text-xs text-white/50">Outcome: higher-confidence fades at key zones.</div>
          </div>

          <div class="rounded-2xl border border-white/10 bg-black/20 p-5">
            <div class="text-sm font-semibold text-white/90">Momentum Breakout</div>
            <ul class="mt-3 space-y-2 text-sm text-white/65">
              <li>Watch delta OI expansion</li>
              <li>Confirm wall breaks with flow imbalance</li>
              <li>Align with term/VRP regime</li>
            </ul>
            <div class="mt-3 text-xs text-white/50">Outcome: trade continuation with structure.</div>
          </div>

          <div class="rounded-2xl border border-white/10 bg-black/20 p-5">
            <div class="text-sm font-semibold text-white/90">Expiry Pin Risk Week</div>
            <ul class="mt-3 space-y-2 text-sm text-white/65">
              <li>Check pin score + strike clusters</li>
              <li>Map levels with Net GEX</li>
              <li>Use flow to avoid noise</li>
            </ul>
            <div class="mt-3 text-xs text-white/50">Outcome: fewer "random" chop days.</div>
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

const title = 'GexOptions - Levels, Dealer Positioning (DEX), and Options Flow'
const description =
  'Options analytics terminal with 1-minute intraday snapshots, GEX levels, dealer positioning (DEX), scanners, and risk tools for daily prep and cleaner intraday decisions.'
const url = 'https://gexoptions.com/'
const showGlossary = !!page.props?.marketing?.show_glossary
const registerWithPlanUrl = '/register?plan=earlybird&billing=monthly'

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

