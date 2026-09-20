<script setup>
defineProps({ tone: { type:String, default:'neutral' } })
</script>
<template><span class="gex-badge" :data-tone="tone"><slot /></span></template>
