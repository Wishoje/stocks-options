<script setup>
defineProps({
  sizeClass: { type: String, default: 'h-24 sm:h-28 md:h-32' },
})
</script>

<template>
  <img
    src="/marketing/gexoptions_logo.svg"
    alt="GEX Options"
    class="w-auto"
    :class="sizeClass"
  />
</template>
