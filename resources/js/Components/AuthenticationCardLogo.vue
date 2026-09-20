<script setup>
import { Link } from '@inertiajs/vue3'
</script>

<template>
  <Link :href="route('home')" class="gex-auth-logo" aria-label="GEX Options home">
    <img
      src="/marketing/gexoptions_logo.svg"
      alt="GEX Options"
      class="gex-auth-logo__image"
      width="798"
      height="316"
    />
  </Link>
</template>
