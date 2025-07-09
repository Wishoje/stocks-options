<template>
  <div class="bg-gray-700 rounded p-4 text-center">
    <h3 class="font-semibold">{{ title }}</h3>
    <p class="text-xl">{{ value }}</p>
  </div>
</template>

<script setup>
defineProps({
  title: String,
  value: [String, Number]
})
</script>
