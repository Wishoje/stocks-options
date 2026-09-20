<template>
  <article :id="feature.id" class="mk-card mk-feature-row" :class="{ 'mk-feature-row--flip': flip }">
    <div class="mk-feature-row__grid">
      <div class="mk-feature-row__copy">
        <p class="mk-eyebrow">{{ eyebrow }}</p>
        <h3 class="mk-subheading">{{ feature.title }}</h3>
        <p class="mk-copy">{{ feature.description }}</p>
        <ul class="mk-feature-list">
          <li v-for="bullet in feature.bullets" :key="bullet">{{ bullet }}</li>
        </ul>
      </div>
      <div class="mk-feature-row__media">
        <ProductPreview
          v-if="feature.previews?.length"
          :id="`feature-${feature.id}-preview`"
          :items="feature.previews"
          :aria-label="`${feature.title} screenshots`"
        />
        <ProductMedia v-else :media="feature.media" />
      </div>
    </div>
  </article>
</template>

<script setup>
import ProductMedia from './ProductMedia.vue'
import ProductPreview from './ProductPreview.vue'

defineProps({
  feature: { type: Object, required: true },
  eyebrow: { type: String, required: true },
  flip: { type: Boolean, default: false },
})
</script>
