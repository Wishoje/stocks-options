<script setup>
import { Link } from '@inertiajs/vue3'

defineProps({
  title: { type: String, default: '' },
  description: { type: String, default: '' },
  wide: Boolean,
})
</script>

<template>
  <main class="gex-auth-shell">
    <div class="gex-auth-shell__glow" aria-hidden="true" />
    <header class="gex-auth-brand">
      <slot name="logo" />
    </header>

    <section class="gex-auth-card" :class="{ 'gex-auth-card--wide': wide }">
      <header v-if="title || description" class="gex-auth-card__heading">
        <p>GexOptions account</p>
        <h1 v-if="title">{{ title }}</h1>
        <span v-if="description">{{ description }}</span>
      </header>
      <slot />
    </section>

    <div v-if="$slots.footer" class="gex-auth-context">
      <slot name="footer" />
    </div>

    <nav class="gex-auth-links" aria-label="Account support links">
      <Link :href="route('home')">Home</Link>
      <Link :href="route('pricing')">Pricing</Link>
      <Link :href="route('contact')">Support</Link>
      <Link :href="route('policy.show')">Privacy</Link>
    </nav>
  </main>
</template>
