<script setup>
defineProps({
  variant: { type: String, default: 'secondary' },
  type: { type: String, default: 'button' },
})
</script>
<template><button :type="type" class="gex-button" :data-variant="variant"><slot /></button></template>
