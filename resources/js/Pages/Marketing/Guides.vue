<template>
  <MarketingSeo v-bind="seoProps" />
  <MarketingLayout>
    <header class="mk-container mk-guide-hero mk-stack">
      <p class="mk-eyebrow">Trading guides</p><h1 class="mk-title">Learn what to look for.<br><span class="mk-accent">Build a clearer plan.</span></h1>
      <p class="mk-lede">Practical walkthroughs of GEX levels, intraday alerts and multi-day swing plans.</p>
    </header>
    <section class="mk-container mk-grid-2 mk-guide-list" aria-label="Trading guides">
      <article v-for="(guide, index) in guides" :key="guide.slug" class="mk-card mk-card-pad mk-stack mk-guide-card">
        <p class="mk-eyebrow">0{{ index + 1 }} · {{ guide.category }}</p>
        <h2 class="mk-subheading"><Link :href="`/guides/${guide.slug}`">{{ guide.title }}</Link></h2>
        <p class="mk-copy">{{ guide.description }}</p>
        <p class="mk-meta">{{ guide.readTime }} · By GEX Options</p>
        <Link :href="`/guides/${guide.slug}`" class="mk-button mk-button--secondary">Read guide <span aria-hidden="true">→</span></Link>
      </article>
    </section>
    <section class="mk-section"><div class="mk-container mk-card mk-callout"><h2 class="mk-heading">Try the workflow on the symbols you follow.</h2><p class="mk-copy">Explore the charts, wall assessments and setup alerts in one workspace.</p><div class="mk-actions"><MarketingCta location="guides_index" source="guides" /><Link href="/features" class="mk-button mk-button--secondary">Explore the features</Link></div><p class="mk-meta">The trial begins after checkout. See Pricing for renewal and cancellation details.</p></div></section>
  </MarketingLayout>
</template>
<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import MarketingLayout from '@/Layouts/MarketingLayout.vue'
import MarketingSeo from '@/Components/Marketing/MarketingSeo.vue'
import MarketingCta from '@/Components/Marketing/MarketingCta.vue'

defineProps({ guides: { type: Array, required: true } })
const page = usePage()
const seoProps = computed(() => ({ title: page.props.seo?.title, description: page.props.seo?.description, canonical: page.props.seo?.canonical, type: page.props.seo?.type || 'website', image: page.props.seo?.image, imageAlt: page.props.seo?.image_alt, imageWidth: page.props.seo?.image_width, imageHeight: page.props.seo?.image_height, structuredData: page.props.seo?.structured_data }))
</script>
