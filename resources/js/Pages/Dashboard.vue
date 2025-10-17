<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import AppShell  from '@/Components/AppShell.vue'
import Dashboard from '@/Components/Dashboard.vue'
</script>

<template>
  <AppLayout title="Dashboard">
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
        Dashboard
      </h2>
    </template>

    <!-- Full-width canvas for shell + aside -->
    <div class="py-0">
      <AppShell>
        <!-- This goes into <main> of AppShell -->
        <Dashboard />
      </AppShell>
    </div>
  </AppLayout>
</template>
