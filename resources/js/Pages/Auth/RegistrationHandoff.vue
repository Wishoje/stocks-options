<script setup>
import { Head } from '@inertiajs/vue3'
import { onMounted, ref } from 'vue'
import AuthenticationCard from '@/Components/AuthenticationCard.vue'
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue'
import { trackEventOnceAndWait } from '@/lib/ga'

const props = defineProps({
  next_url: { type: String, required: true },
  analytics_key: { type: String, required: true },
})

const continuing = ref(true)

function continueJourney() {
  continuing.value = true
  window.location.assign(props.next_url)
}

onMounted(async () => {
  await Promise.all([
    trackEventOnceAndWait('sign_up', props.analytics_key, { method: 'email' }),
    trackEventOnceAndWait('register_complete', props.analytics_key, { method: 'email' }),
  ])
  continueJourney()
})
</script>

<template>
  <Head title="Account created" />
  <AuthenticationCard
    title="Account created"
    description="Your account is ready. Continue to the billing choice you selected."
  >
    <template #logo>
      <AuthenticationCardLogo />
    </template>

    <div class="gex-registration-handoff" role="status" aria-live="polite">
      <span aria-hidden="true" />
      <p>{{ continuing ? 'Opening the next step…' : 'Ready to continue.' }}</p>
    </div>

    <button type="button" class="gex-registration-handoff__button" @click="continueJourney">
      Continue
    </button>
  </AuthenticationCard>
</template>

<style scoped>
.gex-registration-handoff { display: flex; align-items: center; gap: .7rem; border: 1px solid rgba(103, 232, 249, .22); border-radius: .75rem; background: rgba(8, 145, 178, .09); padding: .8rem; color: #bae6fd; font-size: .78rem; }
.gex-registration-handoff span { width: .7rem; height: .7rem; border: 2px solid rgba(103, 232, 249, .3); border-top-color: #67e8f9; border-radius: 999px; animation: handoff-spin .8s linear infinite; }
.gex-registration-handoff p { margin: 0; }
.gex-registration-handoff__button { width: 100%; margin-top: 1rem; border-radius: .7rem; background: linear-gradient(110deg, #22d3ee, #38bdf8); padding: .7rem 1rem; color: #082f49; font-size: .78rem; font-weight: 800; }
@keyframes handoff-spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .gex-registration-handoff span { animation: none; } }
</style>
