<template>
  <MarketingSeo
    :title="homeTitle"
    :description="homeDescription"
    :canonical="homeCanonical"
    :image="page.props.seo?.image"
    :image-alt="page.props.seo?.image_alt"
    :image-width="page.props.seo?.image_width"
    :image-height="page.props.seo?.image_height"
    :faqs="homeFaqs"
  />

  <Head>
    <component
      :is="'script'"
      type="application/ld+json"
      head-key="website-json-ld"
    >{{ homeStructuredDataJson }}</component>
  </Head>

  <MarketingLayout>
    <div ref="motionRoot" class="mk-page-shell mk-home-page">
    <section class="mk-hero">
      <div class="mk-container mk-hero-grid">
        <div class="mk-hero-copy" data-reveal data-reveal-order="0">
          <p class="mk-eyebrow">GEX levels and dealer positioning</p>
          <h1 class="mk-title">Plan your session with GEX levels and dealer positioning.</h1>
          <p class="mk-lede">
            Find important strikes, compare put-versus-call pricing, and inspect intraday options activity—all in one workspace.
          </p>
          <div class="mk-actions">
            <MarketingCta location="home_hero" source="home" />
            <Link href="/features" class="mk-button mk-button--secondary">Explore every view</Link>
          </div>
          <div class="mk-home-offer" aria-label="Trial and pricing">
            <p v-if="monthlyPrice && yearlyPrice" class="mk-home-offer__prices"><strong>{{ monthlyPrice }}/month</strong> or <strong>{{ yearlyPrice }}/year</strong> after your {{ trialDays }}-day trial.</p>
            <p class="mk-meta">Trial starts after checkout. Cancel before it ends to avoid a subscription charge. <Link href="/pricing">See billing details</Link>.</p>
          </div>
          <ul class="mk-proof-list" aria-label="Product principles">
            <li>Dashboard, scanners, calculator, and exports included.</li>
            <li>Browser-based. No installation.</li>
          </ul>
        </div>

        <div class="mk-hero-visual" data-reveal data-reveal-order="1">
          <ProductMedia :media="productMedia.eodStrikes" priority chart-detail />
          <div class="mk-metric-grid mk-hero-metrics" aria-label="How to read the GEX example">
            <div class="mk-metric"><span>Green bars</span><strong class="mk-positive">Positive GEX</strong></div>
            <div class="mk-metric"><span>Coral bars</span><strong class="mk-negative">Negative GEX</strong></div>
            <div class="mk-metric"><span>Larger bars</span><strong>More exposure</strong></div>
          </div>
        </div>
      </div>
    </section>

    <section class="mk-section">
      <div class="mk-container mk-stack">
        <div class="mk-section-intro" data-reveal>
          <p class="mk-eyebrow">Five focused views</p>
          <h2 class="mk-heading">See what matters, then open the evidence.</h2>
          <p class="mk-lede">Explore real examples of pricing, dealer exposure, intraday flow, scanning, and contract risk.</p>
        </div>
        <div class="mk-home-product-stories" data-reveal data-reveal-order="1">
          <ProductPreview id="home_product_stories" :items="homePreviews" aria-label="GEX Options product stories" />
        </div>
      </div>
    </section>

    <section class="mk-section mk-home-outcomes">
      <div class="mk-container mk-stack">
        <div class="mk-section-intro" data-reveal>
          <p class="mk-eyebrow">A SPY walkthrough</p>
          <h2 class="mk-heading">From your first level to a contract scenario.</h2>
          <p class="mk-lede">Use the same symbol throughout. Each step helps you answer the next question in your plan.</p>
        </div>
        <div class="mk-workflow">
          <article class="mk-card" data-reveal data-reveal-order="0">
            <p class="mk-eyebrow">01 · Map the levels</p>
            <h3 class="mk-subheading">Find strikes worth watching.</h3>
            <p class="mk-copy">Open SPY's EOD Strikes view. Compare the largest positive and negative GEX bars and note the strikes you want to monitor.</p>
            <Link href="/features#eod-strikes" class="mk-button mk-button--quiet">Explore GEX levels <span aria-hidden="true">&rarr;</span></Link>
          </article>
          <article class="mk-card" data-reveal data-reveal-order="1">
            <p class="mk-eyebrow">02 · Add context</p>
            <h3 class="mk-subheading">Compare positioning and activity.</h3>
            <p class="mk-copy">Check SPY's dealer DEX and put-versus-call pricing. During the session, inspect call and put activity near your selected strikes and check the source time.</p>
            <Link href="/features#eod-positioning" class="mk-button mk-button--quiet">Compare pricing and flow <span aria-hidden="true">&rarr;</span></Link>
          </article>
          <article class="mk-card" data-reveal data-reveal-order="2">
            <p class="mk-eyebrow">03 · Model the risk</p>
            <h3 class="mk-subheading">Inspect a contract before deciding.</h3>
            <p class="mk-copy">Select a SPY call or put in the calculator. Compare breakeven, maximum loss, and payoff across price scenarios using the displayed assumptions.</p>
            <Link href="/features#options-calculator" class="mk-button mk-button--quiet">Explore the calculator <span aria-hidden="true">&rarr;</span></Link>
          </article>
        </div>
      </div>
    </section>

    <section class="mk-section">
      <div class="mk-container mk-grid-2 mk-page-split mk-page-split--center">
        <div class="mk-section-intro" data-reveal>
          <p class="mk-eyebrow">Why subscribe</p>
          <h2 class="mk-heading">Put the full workspace to work during your trial.</h2>
          <p class="mk-lede">Build a watchlist, use the scanners to find candidates, and explore the positioning and pricing behind each symbol.</p>
          <p v-if="monthlyPrice && yearlyPrice" class="mk-home-offer__prices"><strong>{{ monthlyPrice }}/month</strong> or <strong>{{ yearlyPrice }}/year</strong> after {{ trialDays }} days.</p>
          <div class="mk-actions">
            <MarketingCta location="home_offer" source="home" />
            <Link href="/pricing" class="mk-button mk-button--secondary">View current pricing</Link>
          </div>
          <p class="mk-meta">Choose monthly or yearly billing at checkout. Your selected subscription renews automatically unless canceled. Stripe shows the final total and any applicable tax before you confirm.</p>
        </div>
        <div class="mk-card mk-card-pad" data-reveal data-reveal-order="1">
          <h3 class="mk-subheading">Both billing options include</h3>
          <ul class="mk-feature-list">
            <li v-for="item in offerFeatures" :key="item">{{ item }}</li>
          </ul>
          <p class="mk-meta">Check the date, scope, and freshness beside each reading. Full data and calculation details remain available when you need them.</p>
        </div>
      </div>
    </section>

    <section class="mk-section">
      <div class="mk-container mk-grid-2 mk-page-split">
        <div class="mk-section-intro" data-reveal>
          <p class="mk-eyebrow">Questions before subscribing</p>
          <h2 class="mk-heading">Clear about data timing and coverage.</h2>
          <p class="mk-copy">Know when your trial starts, how cancellation works, and what to expect from the data.</p>
        </div>
        <div class="mk-faqs">
          <details v-for="(item, index) in homeFaqs" :key="item.q" class="mk-faq" data-reveal :data-reveal-order="index % 2">
            <summary>{{ item.q }}</summary>
            <p>{{ item.a }}</p>
          </details>
        </div>
      </div>
    </section>

    <section class="mk-section">
      <div class="mk-container">
        <div class="mk-card mk-callout" data-reveal>
          <p class="mk-eyebrow">Build a repeatable process</p>
          <h2 class="mk-heading">Move from GEX levels to a tested scenario.</h2>
          <p class="mk-lede">Try the complete workspace for {{ trialDays }} days. Explore the features first if you want a closer look.</p>
          <div class="mk-actions">
            <MarketingCta location="home_final" source="home" />
            <Link href="/features" class="mk-button mk-button--secondary">Review features</Link>
          </div>
        </div>
      </div>
    </section>
    </div>
  </MarketingLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import MarketingLayout from '@/Layouts/MarketingLayout.vue'
import MarketingCta from '@/Components/Marketing/MarketingCta.vue'
import MarketingSeo from '@/Components/Marketing/MarketingSeo.vue'
import ProductMedia from '@/Components/Marketing/ProductMedia.vue'
import ProductPreview from '@/Components/Marketing/ProductPreview.vue'
import { buildHomeFaqs, homePreviews, productMedia } from '@/Support/marketing-content'
import { useMarketingMotion } from '@/Support/marketing-motion'

const page = usePage()
const motionRoot = useMarketingMotion()
const homeTitle = computed(() => page.props.seo?.title || 'GexOptions - Premarket Levels, Positioning, and Options Flow')
const homeDescription = computed(() => page.props.seo?.description || 'Map dated GEX levels and dealer positioning, inspect put-versus-call pricing and stored intraday flow, and scan optionable stocks with source scope visible.')
const homeCanonical = computed(() => page.props.seo?.canonical || 'https://gexoptions.com/')
const trialDays = computed(() => Number(page.props.offer?.trial_days) || 7)
function offerPrice(billing) {
  const display = page.props.offer?.display
  const amount = display?.[billing]?.amount_minor
  if (!Number.isInteger(amount) || amount < 0 || !display?.currency) return null
  return new Intl.NumberFormat('en-US', {
    style: 'currency', currency: display.currency,
    minimumFractionDigits: amount % 100 === 0 ? 0 : 2,
    maximumFractionDigits: 2,
  }).format(amount / 100)
}
const monthlyPrice = computed(() => offerPrice('monthly'))
const yearlyPrice = computed(() => offerPrice('yearly'))
const homeFaqs = computed(() => buildHomeFaqs(trialDays.value))
const homeStructuredDataJson = computed(() => {
  return JSON.stringify({
    '@context': 'https://schema.org',
    '@type': 'WebSite',
    '@id': 'https://gexoptions.com/#website',
    url: 'https://gexoptions.com/',
    name: 'GEX Options',
    description: homeDescription.value,
    inLanguage: 'en-US',
  }).replace(/</g, '\\u003c')
})
const offerFeatures = Object.freeze([
  'GEX levels, dealer DEX, gamma regime, expiry pressure, skew, and volatility context',
  'Stored intraday Flow and Strikes views with source time and freshness',
  'Watchlist, Volume Scanner, Wall Scanner, Options Calculator, and AI Export',
])
</script>
