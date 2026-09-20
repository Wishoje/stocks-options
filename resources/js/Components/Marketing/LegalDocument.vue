<template>
  <MarketingLayout>
    <div class="legal-page">
      <header>
        <p>GexOptions</p>
        <h1 v-if="!contentHasHeading">{{ title }}</h1>
        <span>Document text</span>
      </header>
      <article class="legal-page__document" v-html="content" />
      <p class="legal-page__support">
        Questions about this document?
        <a href="mailto:support@gexoptions.com">Contact support.</a>
      </p>
    </div>
  </MarketingLayout>
</template>

<script setup>
import { computed } from 'vue'
import MarketingLayout from '@/Layouts/MarketingLayout.vue'

const props = defineProps({
  title: { type: String, required: true },
  content: { type: String, required: true },
})

const contentHasHeading = computed(() => /<h1(?:\s[^>]*)?>/i.test(props.content))
</script>

<style scoped>
.legal-page { width: min(100%, 900px); margin-inline: auto; padding: clamp(4rem, 9vw, 7rem) 1rem 5rem; color: #f8fafc; }
.legal-page header { border-bottom: 1px solid rgba(148, 163, 184, .16); padding-bottom: 1.5rem; }
.legal-page header p { margin: 0 0 .65rem; color: #67e8f9; font-size: .7rem; font-weight: 800; letter-spacing: .18em; text-transform: uppercase; }
.legal-page header h1 { margin: 0; font-size: clamp(2.2rem, 6vw, 3.8rem); letter-spacing: -.04em; }
.legal-page header span { display: block; margin-top: .7rem; color: #8290a4; font-size: .78rem; }
.legal-page__document { margin-top: 2rem; border: 1px solid rgba(148, 163, 184, .15); border-radius: 1.2rem; background: rgba(8, 15, 29, .82); padding: clamp(1.25rem, 4vw, 2.5rem); color: #b8c4d5; font-size: .9rem; line-height: 1.75; }
.legal-page__document :deep(h1) { margin: 0 0 1.3rem; color: #f1f5f9; font-size: 1.65rem; }
.legal-page__document :deep(h2) { margin: 1.7rem 0 .7rem; color: #e2e8f0; font-size: 1.2rem; }
.legal-page__document :deep(a), .legal-page__support a { color: #67e8f9; text-decoration: underline; text-underline-offset: 3px; }
.legal-page__document :deep(ul), .legal-page__document :deep(ol) { padding-left: 1.3rem; }
.legal-page__support { margin-top: 1rem; color: #8290a4; font-size: .75rem; }
</style>
