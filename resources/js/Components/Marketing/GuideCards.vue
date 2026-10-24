<template>
  <section class="mk-section">
    <div class="mk-container mk-stack">
      <div class="mk-section-intro"><p class="mk-eyebrow">Practical trading guides</p><h2 class="mk-heading">Learn how to use the tools.</h2><p class="mk-lede">Start with the chart, understand the reading, then decide what to watch next.</p></div>
      <div class="mk-grid-2">
        <article v-for="guide in guides" :key="guide.slug" class="mk-card mk-card-pad mk-stack mk-guide-card">
          <p class="mk-eyebrow">{{ guide.category }}</p>
          <h3 class="mk-subheading"><Link :href="`/guides/${guide.slug}`">{{ guide.title }}</Link></h3>
          <p class="mk-copy">{{ guide.description }}</p>
          <Link :href="`/guides/${guide.slug}`" class="mk-text-link">Read guide <span aria-hidden="true">→</span></Link>
        </article>
      </div>
    </div>
  </section>
</template>
<script setup>
import { Link } from '@inertiajs/vue3'
import guides from '../../../marketing/trading-guides.json'
</script>
