<template>
  <MarketingSeo :title="homeTitle" :description="homeDescription" :canonical="homeCanonical"
    :image="page.props.seo?.image" :image-alt="page.props.seo?.image_alt" :image-width="page.props.seo?.image_width"
    :image-height="page.props.seo?.image_height" :faqs="homeFaqs" :structured-data="page.props.seo?.structured_data" />
  <Head><component :is="'script'" type="application/ld+json" head-key="website-json-ld">{{ homeStructuredDataJson }}</component></Head>
  <MarketingLayout>
    <div ref="motionRoot" class="mk-page-shell mk-home-page mk-conversion-page">
      <section class="mk-hero">
        <div class="mk-container mk-hero-grid">
          <div class="mk-hero-copy" data-reveal>
            <p class="mk-eyebrow">{{ intro.eyebrow }}</p>
            <h1 class="mk-title">{{ intro.heading }}<br><span class="mk-accent">{{ intro.accent }}</span></h1>
            <p class="mk-lede">{{ intro.description }}</p>
            <div class="mk-actions">
              <MarketingCta location="home_hero" source="home" />
              <a href="#how-it-works" class="mk-button mk-button--secondary">See how it works <span aria-hidden="true">↓</span></a>
            </div>
            <div class="mk-home-offer" aria-label="Trial and pricing">
              <p v-if="monthlyPrice && yearlyPrice" class="mk-home-offer__prices"><strong>{{ monthlyPrice }}/month</strong> or <strong>{{ yearlyPrice }}/year</strong> after your {{ trialDays }}-day trial.</p>
              <p class="mk-meta">Trial starts after checkout. Cancel before it ends to avoid a subscription charge. <Link href="/pricing">Billing details</Link>.</p>
            </div>
            <Link href="/spy-gex-levels" class="mk-text-link">Explore the free SPY chart →</Link>
            <p class="mk-hero-note">Full workspace included · Browser-based · Intraday prices delayed 15 min</p>
          </div>
          <div class="mk-hero-visual mk-gex-banner" data-reveal data-reveal-order="1">
            <div class="mk-product-window">
              <div class="mk-product-window__bar"><span class="mk-window-dots" aria-hidden="true">● ● ●</span><span>GEX Options / Net GEX by strike</span><span class="mk-window-tag">SPY · EOD</span></div>
              <ProductMedia :media="productMedia.eodStrikes" priority chart-detail />
            </div>
            <ul class="mk-chart-key" aria-label="How to read the GEX chart">
              <li><span><i class="mk-key-dot mk-key-dot--positive" aria-hidden="true"></i>Green bars</span><strong class="mk-positive">Positive exposure</strong></li>
              <li><span><i class="mk-key-dot mk-key-dot--negative" aria-hidden="true"></i>Coral bars</span><strong class="mk-negative">Negative exposure</strong></li>
              <li><span>Greater bar height</span><strong>More exposure</strong></li>
            </ul>
            <p class="mk-chart-explanation">Each bar marks a strike price. Its height shows modeled gamma exposure (GEX). The largest concentrations highlight price areas to watch.</p>
          </div>
        </div>
      </section>

      <section id="how-it-works" class="mk-section">
        <div class="mk-container mk-stack">
          <div class="mk-section-intro" data-reveal>
            <p class="mk-eyebrow">For the way you trade</p>
            <h2 class="mk-heading">Intraday or swing trading. A workflow for both.</h2>
            <p class="mk-lede">Use Scanner and setup alerts during the session. Open Swing ideas for a 2–5 day or 1–3 week plan. See the price condition, target and where the idea ends.</p>
          </div>
          <div class="mk-grid-2 mk-trading-workflows">
            <article v-for="workflow in tradingWorkflows" :id="workflow.id" :key="workflow.id" class="mk-card mk-trading-workflow" data-reveal>
              <div class="mk-trading-workflow__copy">
                <p class="mk-eyebrow">{{ workflow.label }}</p>
                <h3 class="mk-subheading">{{ workflow.title }}</h3>
                <p class="mk-copy">{{ workflow.description }}</p>
                <ol class="mk-trading-steps"><li v-for="step in workflow.steps" :key="step">{{ step }}</li></ol>
                <Link :href="workflow.href" class="mk-button mk-button--quiet">{{ workflow.link }} <span aria-hidden="true">→</span></Link>
                <p class="mk-meta">{{ workflow.note }}</p>
              </div>
              <SwingIdeasPreview v-if="workflow.visual === 'swing-ideas'" />
              <ProductMedia v-else :media="workflow.media" />
            </article>
          </div>
        </div>
      </section>

      <section v-for="(story, index) in homeStories" :id="story.id" :key="story.id" class="mk-section mk-home-story">
        <div :class="['mk-container', 'mk-story-grid', { 'mk-story-grid--flip': index % 2 === 1, 'mk-story-grid--portrait': story.portrait }]">
          <div class="mk-story-copy" data-reveal>
            <p class="mk-eyebrow">{{ story.eyebrow }}</p><h2 class="mk-heading">{{ story.title }}</h2><p class="mk-lede">{{ story.description }}</p>
            <ul class="mk-feature-list"><li v-for="item in story.bullets" :key="item">{{ item }}</li></ul>
            <Link :href="story.href" class="mk-button mk-button--quiet">{{ story.link }} <span aria-hidden="true">→</span></Link>
          </div>
          <div class="mk-story-media" data-reveal data-reveal-order="1"><ProductMedia :media="story.media" /></div>
        </div>
      </section>

      <section class="mk-section mk-toolkit-section">
        <div class="mk-container mk-stack">
          <div class="mk-section-intro" data-reveal><p class="mk-eyebrow">More context for every decision</p><h2 class="mk-heading">The tools behind the plan, included.</h2><p class="mk-lede">Prepare before the open, compare pricing during the session, and model a contract before committing capital.</p></div>
          <div class="mk-grid-3 mk-toolkit-grid">
            <Link v-for="(tool, index) in homeToolkit" :key="tool.title" :href="tool.href" class="mk-card mk-toolkit-card" data-reveal :data-reveal-order="index % 3">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="tool.icon" /></svg>
              <h3 class="mk-subheading">{{ tool.title }}</h3><p class="mk-copy">{{ tool.description }}</p><span class="mk-toolkit-link">Explore <span aria-hidden="true">→</span></span>
            </Link>
          </div>
          <Link href="/features" class="mk-button mk-button--secondary mk-self-start">Explore the complete feature set →</Link>
        </div>
      </section>

      <GuideCards />

      <section class="mk-section" id="membership">
        <div class="mk-container mk-membership mk-card" data-reveal>
          <div class="mk-membership-copy"><p class="mk-eyebrow">One subscription. The complete workspace.</p><h2 class="mk-heading">Build your process around the symbols you trade.</h2><p class="mk-lede">Your trial includes Swing ideas, intraday setup alerts, Scanner, wall analysis, volatility tools, the options calculator and AI Export.</p><ul class="mk-feature-list"><li>Save your watchlist and follow it across the platform.</li><li>Review the chart and the evidence behind each assessment.</li><li>Use the same complete feature set on monthly or yearly billing.</li></ul></div>
          <div class="mk-membership-offer"><p class="mk-eyebrow">{{ trialDays }} days to explore</p><p v-if="monthlyPrice" class="mk-membership-price">{{ monthlyPrice }}<span>/month</span></p><p v-if="yearlyPrice" class="mk-copy">or {{ yearlyPrice }}/year after your trial</p><MarketingCta location="home_offer" source="home" /><p class="mk-meta">Trial begins after checkout. Your selected plan renews automatically unless canceled. Stripe shows the final total and any tax before you confirm.</p><Link href="/pricing" class="mk-text-link">Compare billing options</Link></div>
        </div>
      </section>

      <section class="mk-section">
        <div class="mk-container mk-grid-2 mk-page-split">
          <div class="mk-section-intro" data-reveal><p class="mk-eyebrow">Before you start</p><h2 class="mk-heading">A few clear answers.</h2><p class="mk-copy">What you get, how the alerts work, and what happens after the trial.</p></div>
          <div class="mk-faqs"><details v-for="item in homeFaqs" :key="item.q" class="mk-faq" data-reveal><summary>{{ item.q }}</summary><p>{{ item.a }}</p></details></div>
        </div>
      </section>
      <section class="mk-section"><div class="mk-container"><div class="mk-card mk-callout mk-final-offer" data-reveal><p class="mk-eyebrow">Start with your own watchlist</p><h2 class="mk-heading">Give your next session a clearer plan.</h2><p class="mk-lede">Explore the full workspace during your {{ trialDays }}-day trial. Find your levels, follow the reaction and review the risk.</p><div class="mk-actions"><MarketingCta location="home_final" source="home" /><Link href="/features" class="mk-button mk-button--secondary">See all features</Link></div><p class="mk-meta">Analytics and modeled scenarios. A wall or setup does not guarantee a market outcome.</p></div></div></section>
    </div>
  </MarketingLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import MarketingLayout from '@/Layouts/MarketingLayout.vue'
import MarketingCta from '@/Components/Marketing/MarketingCta.vue'
import GuideCards from '@/Components/Marketing/GuideCards.vue'
import MarketingSeo from '@/Components/Marketing/MarketingSeo.vue'
import ProductMedia from '@/Components/Marketing/ProductMedia.vue'
import SwingIdeasPreview from '@/Components/Marketing/SwingIdeasPreview.vue'
import { buildHomeFaqs, homeStories, homeToolkit, productMedia, tradingWorkflows, marketingIntros } from '@/Support/marketing-content'
import { useMarketingMotion } from '@/Support/marketing-motion'

const intro = marketingIntros.home
const page = usePage()
const motionRoot = useMarketingMotion()
const homeTitle = computed(() => page.props.seo?.title || 'GexOptions - GEX Levels, Swing Ideas & Intraday Alerts')
const homeDescription = computed(() => page.props.seo?.description || 'Find key price levels, follow intraday setup alerts and explore Swing ideas for 2–5 day and 1–3 week plans with entry conditions, targets and invalidation.')
const homeCanonical = computed(() => page.props.seo?.canonical || 'https://gexoptions.com/')
const trialDays = computed(() => Number(page.props.offer?.trial_days) || 7)
function offerPrice(billing) {
  const display = page.props.offer?.display, amount = display?.[billing]?.amount_minor
  if (!Number.isInteger(amount) || amount < 0 || !display?.currency) return null
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: display.currency, minimumFractionDigits: amount % 100 === 0 ? 0 : 2, maximumFractionDigits: 2 }).format(amount / 100)
}
const monthlyPrice = computed(() => offerPrice('monthly'))
const yearlyPrice = computed(() => offerPrice('yearly'))
const homeFaqs = computed(() => buildHomeFaqs(trialDays.value))
const homeStructuredDataJson = computed(() => JSON.stringify({ '@context': 'https://schema.org', '@type': 'WebSite', '@id': 'https://gexoptions.com/#website', url: 'https://gexoptions.com/', name: 'GEX Options', description: homeDescription.value, inLanguage: 'en-US' }).replace(/</g, '\\u003c'))
</script>
