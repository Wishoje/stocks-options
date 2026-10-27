<template>
  <MarketingSeo v-bind="seoProps" />
  <MarketingLayout>
    <article class="mk-guide">
      <header class="mk-container mk-guide-hero mk-stack">
        <nav class="mk-guide-breadcrumb" aria-label="Breadcrumb"><Link href="/">Home</Link><span aria-hidden="true">/</span><Link href="/guides">Trading guides</Link></nav>
        <p class="mk-eyebrow">{{ guide.category }}</p><h1 class="mk-title">{{ guide.title }}</h1>
        <p class="mk-lede">{{ guide.description }}</p>
        <p class="mk-meta">By <Link href="/about">GEX Options</Link> · Published <time :datetime="guide.published">{{ displayDate(guide.published) }}</time> · {{ guide.readTime }}<span v-if="guide.updated !== guide.published"> · Updated <time :datetime="guide.updated">{{ displayDate(guide.updated) }}</time></span></p>
        <div class="mk-guide-takeaway"><strong>What to use it for</strong><p>{{ guide.takeaway }}</p></div>
      </header>
      <div class="mk-container mk-guide-image"><SwingIdeasPreview v-if="guide.visual === 'swing-ideas'" /><ProductMedia v-else :media="productMedia[guide.mediaKey]" priority /></div>
      <div class="mk-container mk-guide-layout">
        <aside><nav class="mk-guide-contents" aria-label="In this guide"><p class="mk-eyebrow">In this guide</p><a v-for="section in guide.sections" :key="section.id" :href="`#${section.id}`">{{ section.title }}</a></nav></aside>
        <div>
          <section v-for="section in guide.sections" :id="section.id" :key="section.id" class="mk-guide-prose">
            <h2>{{ section.title }}</h2>
            <p v-for="paragraph in section.paragraphs" :key="paragraph">{{ paragraph }}</p>
            <ol v-if="section.steps"><li v-for="step in section.steps" :key="step">{{ step }}</li></ol>
            <div v-if="section.rows" class="mk-guide-table" role="region" :aria-label="section.title" tabindex="0"><table><thead><tr><th v-for="cell in section.rows[0]" :key="cell" scope="col">{{ cell }}</th></tr></thead><tbody><tr v-for="(row, index) in section.rows.slice(1)" :key="index"><td v-for="(cell, column) in row" :key="column">{{ cell }}</td></tr></tbody></table></div>
          </section>
          <section class="mk-guide-prose mk-guide-checklist mk-card mk-card-pad"><h2>Your quick checklist</h2><ul><li v-for="item in guide.checklist" :key="item">{{ item }}</li></ul><Link :href="guide.featureHref" class="mk-text-link">{{ guide.featureLabel }} →</Link></section>
          <p class="mk-meta">Published by GEX Options. <Link href="/about">Read our methodology and editorial information.</Link></p>
          <section v-if="guide.sources.length" class="mk-guide-prose"><h2>Further reading</h2><ul><li v-for="source in guide.sources" :key="source.url"><a :href="source.url">{{ source.label }}</a></li></ul></section>
        </div>
      </div>
    </article>
    <section class="mk-section"><div class="mk-container mk-card mk-callout"><p class="mk-eyebrow">Put the guide into practice</p><h2 class="mk-heading">Try the workflow on the symbols you follow.</h2><p class="mk-lede">Use the same workspace to prepare levels, follow the session and inspect the evidence behind an assessment.</p><div class="mk-actions"><MarketingCta location="guide_final" source="guides" /><Link href="/features" class="mk-button mk-button--secondary">Explore all features</Link></div><p class="mk-meta">The trial begins after checkout. See Pricing for renewal and cancellation details.</p></div></section>
    <section class="mk-container mk-guide-related"><p class="mk-eyebrow">Keep learning</p><Link :href="`/guides/${related.slug}`" class="mk-subheading">{{ related.title }} →</Link><p class="mk-copy">{{ related.description }}</p><Link href="/guides" class="mk-text-link">All trading guides</Link></section>
  </MarketingLayout>
</template>
<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import MarketingLayout from '@/Layouts/MarketingLayout.vue'
import MarketingSeo from '@/Components/Marketing/MarketingSeo.vue'
import MarketingCta from '@/Components/Marketing/MarketingCta.vue'
import ProductMedia from '@/Components/Marketing/ProductMedia.vue'
import SwingIdeasPreview from '@/Components/Marketing/SwingIdeasPreview.vue'
import { productMedia } from '@/Support/marketing-content'
import guides from '../../../marketing/trading-guides.json'

const props = defineProps({ guide: { type: Object, required: true } })
const page = usePage()
const related = computed(() => guides.find(item => item.slug !== props.guide.slug))
const seoProps = computed(() => ({ title: page.props.seo?.title, description: page.props.seo?.description, canonical: page.props.seo?.canonical, type: page.props.seo?.type || 'website', image: page.props.seo?.image, imageAlt: page.props.seo?.image_alt, imageWidth: page.props.seo?.image_width, imageHeight: page.props.seo?.image_height, structuredData: page.props.seo?.structured_data }))
function displayDate(value) {
  return new Intl.DateTimeFormat('en-US', { month: 'long', day: 'numeric', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`))
}
</script>
