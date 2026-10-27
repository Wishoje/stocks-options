<template>
  <MarketingSeo v-bind="seoProps" :faqs="content.faqs" />
  <MarketingLayout>
    <article>
      <header class="mk-container mk-workflow-hero" :class="{ 'mk-workflow-hero--visual': content.visual || content.mediaKey }">
        <div class="mk-stack">
          <p class="mk-eyebrow">{{ content.eyebrow }}</p><h1 class="mk-title">{{ content.heading }}</h1>
          <p class="mk-lede">{{ content.description }}</p>
          <div class="mk-actions"><MarketingCta location="workflow_hero" source="workflow" /><a href="#workflow-details" class="mk-button mk-button--secondary">See how it works</a></div>
          <p class="mk-meta">{{ content.note }}</p>
        </div>
        <div v-if="content.visual || content.mediaKey" class="mk-workflow-visual">
          <SwingIdeasPreview v-if="content.visual === 'swing-ideas'" />
          <ProductMedia v-else :media="productMedia[content.mediaKey]" priority />
        </div>
      </header>
      <div id="workflow-details" class="mk-container mk-guide-layout">
        <aside><nav class="mk-guide-contents" aria-label="On this page"><p class="mk-eyebrow">On this page</p><a v-for="section in content.sections" :key="section.id" :href="`#${section.id}`">{{ section.title }}</a></nav></aside>
        <div>
          <section v-for="section in content.sections" :id="section.id" :key="section.id" class="mk-guide-prose">
            <h2>{{ section.title }}</h2><p v-for="paragraph in section.paragraphs" :key="paragraph">{{ paragraph }}</p>
            <ol v-if="section.steps"><li v-for="step in section.steps" :key="step">{{ step }}</li></ol>
            <div v-if="section.rows" class="mk-guide-table" role="region" :aria-label="section.title" tabindex="0"><table><thead><tr><th v-for="cell in section.rows[0]" :key="cell" scope="col">{{ cell }}</th></tr></thead><tbody><tr v-for="(row,index) in section.rows.slice(1)" :key="index"><td v-for="(cell,column) in row" :key="column">{{ cell }}</td></tr></tbody></table></div>
          </section>
          <section v-if="content.faqs.length" class="mk-guide-prose"><h2>Questions before you start</h2><details v-for="faq in content.faqs" :key="faq.q" class="mk-faq"><summary>{{ faq.q }}</summary><p>{{ faq.a }}</p></details></section>
          <p class="mk-copy"><Link href="/about">Read our methodology</Link> · <Link href="/contact">Contact the team</Link></p>
        </div>
      </div>
    </article>
    <section class="mk-section"><div class="mk-container mk-grid-2"><article v-for="guide in relatedGuides" :key="guide.slug" class="mk-card mk-card-pad mk-stack"><p class="mk-eyebrow">Practical guide</p><h2 class="mk-subheading"><Link :href="`/guides/${guide.slug}`">{{ guide.title }}</Link></h2><p class="mk-copy">{{ guide.description }}</p></article></div></section>
    <section class="mk-section"><div class="mk-container mk-card mk-callout"><h2 class="mk-heading">Try the workflow on your own watchlist.</h2><p class="mk-lede">Swing ideas, intraday alerts, charts and the evidence behind the levels are included in one subscription.</p><div class="mk-actions"><MarketingCta location="workflow_final" source="workflow" /><Link href="/pricing" class="mk-button mk-button--secondary">View pricing</Link></div><p class="mk-meta">The trial begins after checkout. Your chosen plan renews unless canceled. Analytics and modeled scenarios do not guarantee an outcome.</p></div></section>
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
const props = defineProps({ content: { type: Object, required: true } })
const page = usePage()
const relatedGuides = computed(() => props.content.guides.map(slug => guides.find(guide => guide.slug === slug)).filter(Boolean))
const seoProps = computed(() => ({ title: page.props.seo?.title, description: page.props.seo?.description, canonical: page.props.seo?.canonical, image: page.props.seo?.image, imageAlt: page.props.seo?.image_alt, imageWidth: page.props.seo?.image_width, imageHeight: page.props.seo?.image_height, structuredData: page.props.seo?.structured_data }))
</script>
