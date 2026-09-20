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
          <h1 class="mk-title">Read GEX levels, dealer positioning, and options flow together.</h1>
          <p class="mk-lede">
            Prepare key levels before the session, understand dealer and volatility positioning, monitor stored intraday activity, find candidates, and model contract risk. Every view keeps its date, scope, units, and freshness visible.
          </p>
          <div class="mk-actions">
            <MarketingCta location="home_hero" source="home" />
            <Link href="/features" class="mk-button mk-button--secondary">Explore every view</Link>
          </div>
          <ul class="mk-proof-list" aria-label="Product principles">
            <li>Spot the largest positive and negative GEX levels by strike.</li>
            <li>Compare dealer DEX, volatility pricing, and current-session activity.</li>
            <li>Keep dates, units, filters, and source scope visible.</li>
          </ul>
        </div>

        <div class="mk-hero-visual" data-reveal data-reveal-order="1">
          <ProductMedia :media="productMedia.eodStrikes" priority />
          <div class="mk-metric-grid mk-hero-metrics" aria-label="Example reading types">
            <div class="mk-metric"><span>GEX levels</span><strong class="mk-positive">By strike</strong></div>
            <div class="mk-metric"><span>Dealer DEX</span><strong class="mk-accent">By expiry</strong></div>
            <div class="mk-metric"><span>Options flow</span><strong>By session</strong></div>
          </div>
        </div>
      </div>
    </section>

    <section class="mk-section">
      <div class="mk-container mk-stack">
        <div class="mk-section-intro" data-reveal>
          <p class="mk-eyebrow">Five focused views</p>
          <h2 class="mk-heading">See what matters, then open the evidence.</h2>
          <p class="mk-lede">Each preview answers a practical market question and keeps the date, scope, and units close to the reading.</p>
        </div>
        <div class="mk-home-product-stories" data-reveal data-reveal-order="1">
          <ProductPreview id="home_product_stories" :items="homePreviews" aria-label="GEX Options product stories" />
        </div>
      </div>
    </section>

    <section class="mk-section mk-home-outcomes">
      <div class="mk-container mk-stack">
        <div class="mk-section-intro" data-reveal>
          <p class="mk-eyebrow">From reading to decision</p>
          <h2 class="mk-heading">Answer the next question without rebuilding context.</h2>
        </div>
        <div class="mk-workflow">
          <article class="mk-card" data-reveal data-reveal-order="0">
            <h3 class="mk-subheading">Where can price react?</h3>
            <p class="mk-copy">Start with Net GEX by strike, then compare dealer exposure, the gamma regime, and nearby expiry pressure.</p>
            <Link href="/features#eod-strikes" class="mk-button mk-button--quiet">Explore GEX levels <span aria-hidden="true">&rarr;</span></Link>
          </article>
          <article class="mk-card" data-reveal data-reveal-order="1">
            <h3 class="mk-subheading">Which side is expensive or active?</h3>
            <p class="mk-copy">Compare put-versus-call IV pricing, then check stored session volume, premium, and strike activity.</p>
            <Link href="/features#eod-positioning" class="mk-button mk-button--quiet">Compare pricing and flow <span aria-hidden="true">&rarr;</span></Link>
          </article>
          <article class="mk-card" data-reveal data-reveal-order="2">
            <h3 class="mk-subheading">What deserves a closer look?</h3>
            <p class="mk-copy">Use volume and wall scanners to narrow the list, open the symbol in context, and test a contract scenario.</p>
            <Link href="/features#volume-scanner" class="mk-button mk-button--quiet">See scanners and calculator <span aria-hidden="true">&rarr;</span></Link>
          </article>
        </div>
      </div>
    </section>

    <section class="mk-section">
      <div class="mk-container mk-grid-2 mk-page-split mk-page-split--center">
        <div class="mk-section-intro" data-reveal>
          <p class="mk-eyebrow">Why subscribe</p>
          <h2 class="mk-heading">One workflow from market structure to contract risk.</h2>
          <p class="mk-lede">Use GEX Options to prepare important levels, understand positioning, check the current session, narrow the market, and test a contract without piecing together separate tools.</p>
          <div class="mk-actions">
            <MarketingCta location="home_offer" source="home" />
            <Link href="/pricing" class="mk-button mk-button--secondary">View current pricing</Link>
          </div>
          <p class="mk-meta">The current {{ trialDays }}-day trial begins after checkout. Pricing shows the available billing choices and terms before you continue.</p>
        </div>
        <div class="mk-card mk-card-pad" data-reveal data-reveal-order="1">
          <h3 class="mk-subheading">What every plan includes</h3>
          <ul class="mk-feature-list">
            <li v-for="item in offerFeatures" :key="item">{{ item }}</li>
          </ul>
          <p class="mk-meta">Dates, units, source scope, unavailable values, and exact returned data stay visible throughout the workflow.</p>
        </div>
      </div>
    </section>

    <section class="mk-section">
      <div class="mk-container mk-grid-2 mk-page-split">
        <div class="mk-section-intro" data-reveal>
          <p class="mk-eyebrow">Questions before subscribing</p>
          <h2 class="mk-heading">Clear about data timing and coverage.</h2>
          <p class="mk-copy">These answers also generate the page's FAQ structured data, so search copy matches what visitors can read.</p>
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
          <p class="mk-lede">Start the current trial or inspect every dashboard, scanner, and workflow first.</p>
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
