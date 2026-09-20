<script setup>
defineProps({
    message: String,
    id: String,
});
</script>

<template>
    <div v-show="message" :id="id" role="alert">
        <p class="text-sm text-red-600 dark:text-red-400">
            {{ message }}
        </p>
    </div>
</template>
