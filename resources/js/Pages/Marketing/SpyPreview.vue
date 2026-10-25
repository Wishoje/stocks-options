<template>
  <MarketingSeo :title="seo.title" :description="seo.description" :canonical="seo.canonical" :image="seo.image" :image-alt="seo.image_alt" :image-width="seo.image_width" :image-height="seo.image_height" :structured-data="seo.structured_data" :faqs="content.faqs" />
  <MarketingLayout>
    <header class="mk-container mk-spy-hero">
      <div class="mk-stack"><p class="mk-eyebrow">{{ content.intro.eyebrow }}</p><h1 class="mk-title">{{ content.intro.heading }}</h1><p class="mk-lede">{{ content.intro.description }}</p><p class="mk-copy">Free end-of-day chart. No account needed.</p></div>
      <div class="mk-spy-hero__action"><MarketingCta location="spy_preview_hero" source="spy_preview" /><Link href="/guides/how-to-read-spy-gex-levels" class="mk-text-link">How to read this chart →</Link></div>
    </header>
    <section class="mk-container mk-stack mk-spy-reading" aria-label="SPY end-of-day exposure">
      <template v-if="preview">
        <div class="mk-spy-datebar"><p><span class="mk-spy-status">{{ preview.is_latest_session ? 'Latest completed EOD' : 'Recorded EOD' }}</span> <time :datetime="preview.data_date">{{ date(preview.data_date) }}</time></p><p>2W expiry scope · {{ preview.expiration_dates.length }} expirations</p></div>
        <p class="mk-meta">{{ preview.is_latest_session ? 'End-of-day exposure. Use the displayed date when comparing levels.' : `This is a recorded view from ${date(preview.data_date)}. It does not show today’s market price.` }}</p>
        <div class="mk-grid-3 mk-spy-metrics">
          <article class="mk-card mk-card-pad mk-spy-metric mk-spy-metric--negative"><h2>Put wall</h2><p class="mk-spy-metric__value">{{ preview.put_wall?.strike_label || '—' }}</p><p>Largest negative concentration</p><p class="mk-meta">{{ preview.put_wall ? `${preview.put_wall.exposure_label} net GEX at this strike` : 'No negative net GEX in this view.' }}</p></article>
          <article class="mk-card mk-card-pad mk-spy-metric mk-spy-metric--positive"><h2>Call wall</h2><p class="mk-spy-metric__value">{{ preview.call_wall?.strike_label || '—' }}</p><p>Largest positive concentration</p><p class="mk-meta">{{ preview.call_wall ? `${preview.call_wall.exposure_label} net GEX at this strike` : 'No positive net GEX in this view.' }}</p></article>
          <article class="mk-card mk-card-pad mk-spy-metric"><h2>Total net GEX</h2><p class="mk-spy-metric__value">{{ preview.total_label }}</p><p>All selected expirations</p><p class="mk-meta">USD per 1% underlying move</p></article>
        </div>
        <div class="mk-card mk-card-pad mk-spy-plot">
          <div class="mk-spy-plot__heading"><div><h2 class="mk-subheading">{{ content.chartTitle }}</h2><p class="mk-meta">Net GEX · USD per 1% underlying move</p></div><div class="mk-spy-legend" aria-label="Chart legend"><span><i class="mk-spy-positive-dot"></i>Positive exposure</span><span><i class="mk-spy-negative-dot"></i>Negative exposure</span></div></div>
          <SpyGexChart :preview="preview" />
          <p class="mk-copy">{{ content.chartDescription }}</p><p class="mk-meta">The chart focuses on the main exposure range. Totals include all {{ preview.strike_count }} numeric strikes in this view.</p>
          <details class="mk-spy-expiries"><summary>Included expirations and calculation</summary><div class="mk-spy-expiry-list"><time v-for="expiry in preview.expiration_dates" :key="expiry" :datetime="expiry">{{ date(expiry) }}</time></div><p class="mk-meta">Net GEX uses the platform’s call-minus-put convention. Values here are normalized to USD per 1% underlying move, matching wall analysis. The legacy Net GEX chart uses a scale 100 times larger.</p></details>
        </div>
      </template>
      <div v-else class="mk-card mk-card-pad mk-stack mk-spy-preparing"><h2 class="mk-subheading">SPY EOD levels are being prepared.</h2><p class="mk-copy">This free view updates as the next EOD reading becomes available. See the walkthrough to learn what the chart shows.</p><Link href="/guides/how-to-read-spy-gex-levels" class="mk-text-link">Read the SPY guide →</Link></div>
    </section>
    <section class="mk-section"><div class="mk-container mk-stack"><div class="mk-section-intro"><p class="mk-eyebrow">From a level to a watch plan</p><h2 class="mk-heading">What should you do with these levels?</h2></div><div class="mk-grid-3"><article v-for="(step, index) in content.readSteps" :key="step.title" class="mk-card mk-card-pad mk-stack"><p class="mk-eyebrow">0{{ index + 1 }}</p><h3 class="mk-subheading">{{ step.title }}</h3><p class="mk-copy">{{ step.description }}</p></article></div></div></section>
    <section class="mk-section"><div class="mk-container mk-stack"><div class="mk-section-intro"><p class="mk-eyebrow">Continue in the full workspace</p><h2 class="mk-heading">Follow the reaction. Review the setup.</h2><p class="mk-lede">The free chart gives you an EOD view of SPY. Your subscription adds the tools to follow levels, compare symbols and inspect the evidence during the session.</p></div><div class="mk-grid-3"><article v-for="feature in content.included" :key="feature.title" class="mk-card mk-card-pad mk-stack"><h3 class="mk-subheading">{{ feature.title }}</h3><p class="mk-copy">{{ feature.description }}</p><Link :href="feature.href" class="mk-text-link">Explore the feature →</Link></article></div><div class="mk-card mk-callout mk-spy-upgrade"><div><h2 class="mk-heading">Build a plan around your own watchlist.</h2><p class="mk-copy">Explore the complete workspace during your {{ trialDays }}-day trial.</p><p class="mk-meta">Trial starts after checkout. Your selected plan renews automatically unless canceled. See Pricing for billing details.</p></div><div class="mk-stack"><MarketingCta location="spy_preview_final" source="spy_preview" /><Link href="/pricing" class="mk-text-link">View plans and pricing</Link></div></div><p class="mk-meta">Intraday prices are delayed at least 15 minutes. In-app alerts arrive while the site is open.</p></div></section>
    <section class="mk-section"><div class="mk-container mk-grid-2 mk-page-split"><div class="mk-section-intro"><p class="mk-eyebrow">About the free view</p><h2 class="mk-heading">A few clear answers.</h2><Link href="/guides/how-to-use-intraday-wall-tracking" class="mk-text-link">Learn how to follow walls intraday →</Link></div><div class="mk-faqs"><details v-for="faq in content.faqs" :key="faq.q" class="mk-faq"><summary>{{ faq.q }}</summary><p>{{ faq.a }}</p></details></div></div></section>
  </MarketingLayout>
</template>
<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import MarketingLayout from '@/Layouts/MarketingLayout.vue'
import MarketingCta from '@/Components/Marketing/MarketingCta.vue'
import MarketingSeo from '@/Components/Marketing/MarketingSeo.vue'
import SpyGexChart from '@/Components/Marketing/SpyGexChart.vue'
defineProps({ preview: { type: Object, default: null }, content: { type: Object, required: true }, seo: { type: Object, required: true } })
const page = usePage()
const trialDays = computed(() => Number(page.props.offer?.trial_days) || 7)
function date(value) { return new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`)) }
</script>
