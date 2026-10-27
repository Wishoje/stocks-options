<template>
  <MarketingSeo
    :title="featuresTitle"
    :description="featuresDescription"
    :canonical="featuresCanonical"
    :image="page.props.seo?.image"
    :image-alt="page.props.seo?.image_alt"
    :image-width="page.props.seo?.image_width"
    :image-height="page.props.seo?.image_height"
    :faqs="featureFaqs"
    :structured-data="page.props.seo?.structured_data"
  />

  <MarketingLayout>
    <div ref="motionRoot" class="mk-page-shell mk-features-page">
    <section class="mk-hero">
      <div class="mk-container mk-hero-grid">
        <div class="mk-hero-copy" data-reveal data-reveal-order="0">
          <p class="mk-eyebrow">{{ intro.eyebrow }}</p>
          <h1 class="mk-title">{{ intro.heading }}</h1>
          <p class="mk-lede">{{ intro.description }}</p>
          <div class="mk-actions">
            <MarketingCta location="features_hero" source="features" />
            <a href="#feature-directory" class="mk-button mk-button--secondary">Browse all features</a>
          </div>
        </div>
        <div class="mk-hero-visual" data-reveal data-reveal-order="1">
          <ProductMedia :media="productMedia.wallTracking" priority />
        </div>
      </div>
    </section>

    <div class="mk-feature-nav-shell">
      <div class="mk-container">
        <nav class="mk-feature-nav" aria-label="Feature sections">
          <a v-for="group in featureGroups" :key="group.id" :href="`#${group.id}`">{{ group.navLabel }}</a>
        </nav>
      </div>
    </div>

    <section id="feature-directory" class="mk-section">
      <div class="mk-container mk-stack">
        <div class="mk-section-intro" data-reveal>
          <p class="mk-eyebrow">Everything in your subscription</p>
          <h2 class="mk-heading">Find the tool you need.</h2>
          <p class="mk-lede">Choose a feature to see what it does and its product screenshots.</p>
        </div>
        <nav class="mk-feature-directory" aria-label="All product features">
          <div v-for="group in featureGroups" :key="group.id" class="mk-card mk-feature-directory__group" data-reveal>
            <h3>{{ group.navLabel }}</h3>
            <ul><li v-for="feature in group.features" :key="feature.id"><a :href="`#${feature.id}`">{{ feature.title }} <span aria-hidden="true">↗</span></a></li></ul>
          </div>
        </nav>
      </div>
    </section>

    <section
      v-for="group in featureGroups"
      :id="group.id"
      :key="group.id"
      class="mk-section mk-feature-group"
    >
      <div class="mk-container mk-stack">
        <div class="mk-section-intro" data-reveal>
          <p class="mk-eyebrow">{{ group.eyebrow }}</p>
          <h2 class="mk-heading">{{ group.title }}</h2>
          <p class="mk-lede">{{ group.description }}</p>
        </div>

        <FeatureRow
          v-for="(feature, index) in group.features"
          :key="feature.id"
          :feature="feature"
          :eyebrow="group.eyebrow"
          :flip="index % 2 === 1"
          data-reveal
        />
      </div>
    </section>

    <GuideCards />

    <section class="mk-section">
      <div class="mk-container mk-grid-2 mk-page-split">
        <div class="mk-section-intro" data-reveal>
          <p class="mk-eyebrow">Feature questions</p>
          <h2 class="mk-heading">Get more from each view.</h2>
          <p class="mk-copy">Start with the key metrics, compare the charts, and inspect a reading when you want more detail.</p>
        </div>
        <div class="mk-faqs">
          <details v-for="(item, index) in featureFaqs" :key="item.q" class="mk-faq" data-reveal :data-reveal-order="index % 2">
            <summary>{{ item.q }}</summary>
            <p>{{ item.a }}</p>
          </details>
        </div>
      </div>
    </section>

    <section class="mk-section">
      <div class="mk-container">
        <div class="mk-card mk-callout" data-reveal>
          <p class="mk-eyebrow">Everything included in one subscription</p>
          <h2 class="mk-heading">Put the full workflow to work.</h2>
          <p class="mk-lede">Start with the symbols you trade. Explore Swing ideas, intraday setup alerts, Scanner and the complete analytics toolkit during your trial.</p>
          <div class="mk-actions">
            <MarketingCta location="features_final" source="features" />
            <Link href="/pricing" class="mk-button mk-button--secondary">View pricing</Link>
          </div>
        </div>
      </div>
    </section>
    </div>
  </MarketingLayout>
</template>

<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import FeatureRow from '@/Components/Marketing/FeatureRow.vue'
import MarketingCta from '@/Components/Marketing/MarketingCta.vue'
import GuideCards from '@/Components/Marketing/GuideCards.vue'
import MarketingSeo from '@/Components/Marketing/MarketingSeo.vue'
import ProductMedia from '@/Components/Marketing/ProductMedia.vue'
import MarketingLayout from '@/Layouts/MarketingLayout.vue'
import { featureFaqs, featureGroups, productMedia, marketingIntros } from '@/Support/marketing-content'
import { useMarketingMotion } from '@/Support/marketing-motion'

const intro = marketingIntros.features
const motionRoot = useMarketingMotion()
const page = usePage()
const featuresTitle = computed(() => page.props.seo?.title || 'GexOptions Features - Swing Ideas, Alerts & Options Analytics')
const featuresDescription = computed(() => page.props.seo?.description || 'Explore Swing ideas, intraday setup alerts, Scanner, wall tracking, GEX and DEX analysis, volatility tools, the options calculator and AI Export.')
const featuresCanonical = computed(() => page.props.seo?.canonical || 'https://gexoptions.com/features')
</script>
