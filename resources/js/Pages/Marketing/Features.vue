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
  />

  <MarketingLayout>
    <div ref="motionRoot" class="mk-page-shell mk-features-page">
    <section class="mk-hero">
      <div class="mk-container mk-hero-grid">
        <div class="mk-hero-copy" data-reveal data-reveal-order="0">
          <p class="mk-eyebrow">Complete feature map</p>
          <h1 class="mk-title">Explore GEX, DEX, options flow, scanners, and volatility.</h1>
          <p class="mk-lede">Move through EOD context, stored intraday activity, scanners, watchlist, calculator, and export without losing the dates, units, or filters behind a reading.</p>
          <div class="mk-actions">
            <MarketingCta location="features_hero" source="features" />
            <Link href="/pricing" class="mk-button mk-button--secondary">See current pricing</Link>
          </div>
        </div>
        <div class="mk-hero-visual" data-reveal data-reveal-order="1">
          <ProductMedia :media="productMedia.skewPricing" priority />
        </div>
      </div>
    </section>

    <div class="mk-feature-nav-shell">
      <div class="mk-container">
        <nav class="mk-feature-nav" aria-label="Feature sections">
          <a v-for="group in featureGroups" :key="group.id" :href="`#${group.id}`">{{ group.eyebrow }}</a>
        </nav>
      </div>
    </div>

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

    <section class="mk-section">
      <div class="mk-container mk-grid-2 mk-page-split">
        <div class="mk-section-intro" data-reveal>
          <p class="mk-eyebrow">Feature questions</p>
          <h2 class="mk-heading">How the refreshed views handle detail.</h2>
          <p class="mk-copy">The product keeps exact returned data available while using summary cards and charts for faster scanning.</p>
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
          <p class="mk-eyebrow">Choose your next step</p>
          <h2 class="mk-heading">Open the product or review the current offer.</h2>
          <p class="mk-lede">Your primary action updates for guest, checkout, and active-access states.</p>
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
import MarketingSeo from '@/Components/Marketing/MarketingSeo.vue'
import ProductMedia from '@/Components/Marketing/ProductMedia.vue'
import MarketingLayout from '@/Layouts/MarketingLayout.vue'
import { featureFaqs, featureGroups, productMedia } from '@/Support/marketing-content'
import { useMarketingMotion } from '@/Support/marketing-motion'

const motionRoot = useMarketingMotion()
const page = usePage()
const featuresTitle = computed(() => page.props.seo?.title || 'GexOptions Features - Flow, GEX Levels, DEX, Scanners, VRP & Term Structure')
const featuresDescription = computed(() => page.props.seo?.description || 'Explore GEX Options EOD and intraday analytics, watchlist, scanners, options calculator, and structured export with timing and coverage explained.')
const featuresCanonical = computed(() => page.props.seo?.canonical || 'https://gexoptions.com/features')
</script>
