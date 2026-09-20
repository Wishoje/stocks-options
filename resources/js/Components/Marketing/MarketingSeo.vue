<template>
  <Head>
    <title>{{ title }}</title>
    <meta head-key="seo-description" name="description" :content="description" />
    <link head-key="seo-canonical" rel="canonical" :href="canonical" />
    <meta head-key="seo-og-type" property="og:type" content="website" />
    <meta head-key="seo-og-site-name" property="og:site_name" content="GEX Options" />
    <meta head-key="seo-og-title" property="og:title" :content="title" />
    <meta head-key="seo-og-description" property="og:description" :content="description" />
    <meta head-key="seo-og-url" property="og:url" :content="canonical" />
    <meta v-if="image" head-key="seo-og-image" property="og:image" :content="image" />
    <meta v-if="image && imageWidth" head-key="seo-og-image-width" property="og:image:width" :content="imageWidth" />
    <meta v-if="image && imageHeight" head-key="seo-og-image-height" property="og:image:height" :content="imageHeight" />
    <meta v-if="image && imageAlt" head-key="seo-og-image-alt" property="og:image:alt" :content="imageAlt" />
    <meta head-key="seo-twitter-card" name="twitter:card" :content="image ? 'summary_large_image' : 'summary'" />
    <meta head-key="seo-twitter-title" name="twitter:title" :content="title" />
    <meta head-key="seo-twitter-description" name="twitter:description" :content="description" />
    <meta v-if="image" head-key="seo-twitter-image" name="twitter:image" :content="image" />
    <meta v-if="image && imageAlt" head-key="seo-twitter-image-alt" name="twitter:image:alt" :content="imageAlt" />
    <component
      v-if="faqJsonLdJson"
      :is="'script'"
      type="application/ld+json"
      head-key="faq-json-ld"
    >{{ faqJsonLdJson }}</component>
  </Head>
</template>

<script setup>
import { computed } from 'vue'
import { Head } from '@inertiajs/vue3'

const props = defineProps({
  title: { type: String, required: true },
  description: { type: String, required: true },
  canonical: { type: String, required: true },
  image: { type: String, default: '' },
  imageAlt: { type: String, default: '' },
  imageWidth: { type: Number, default: 0 },
  imageHeight: { type: Number, default: 0 },
  faqs: { type: Array, default: () => [] },
})

const faqJsonLdJson = computed(() => {
  if (!props.faqs.length) return ''

  return JSON.stringify({
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: props.faqs.map(({ q, a }) => ({
      '@type': 'Question',
      name: q,
      acceptedAnswer: { '@type': 'Answer', text: a },
    })),
  }).replace(/</g, '\\u003c')
})
</script>
