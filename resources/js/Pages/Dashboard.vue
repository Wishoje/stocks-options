<script setup>
import { onMounted } from 'vue'
import { usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import AppShell  from '@/Components/AppShell.vue'
import Dashboard from '@/Components/Dashboard.vue'
import { trackEventOnce } from '@/lib/ga'

const page = usePage()

onMounted(() => {
  if (!page.props.flash?.activation_confirmed) return

  trackEventOnce(
    'subscription_activation_confirmed',
    'confirmed',
    { surface: 'dashboard', state: 'confirmed' },
  )
})
</script>

<template>
  <AppLayout title="Dashboard">
    <div class="py-0">
      <AppShell>
        <Dashboard :account-id="page.props.auth?.user?.id ?? null" />
      </AppShell>
    </div>
  </AppLayout>
</template>
