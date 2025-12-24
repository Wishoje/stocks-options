<script setup>
import { Link } from '@inertiajs/vue3'
</script>

<template>
  <Link :href="route('home')" class="inline-flex items-center justify-center">
    <img
      src="/marketing/gexoptions_logo.svg"
      alt="GEX Options"
      class="h-48 w-auto"
    />
  </Link>
</template>