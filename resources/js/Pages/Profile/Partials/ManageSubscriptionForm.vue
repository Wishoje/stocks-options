<script setup>
import { computed } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import FormSection from '@/Components/FormSection.vue'
import ActionMessage from '@/Components/ActionMessage.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'

const page = usePage()

const props = defineProps({
  subscription: { type: Object, default: null },
})

const cancelForm = useForm({})
const resumeForm = useForm({})

const flashStatus = computed(() => page.props.flash?.status)

const onGracePeriod = computed(() => !!props.subscription?.on_grace_period)
const isActive = computed(() => !!props.subscription?.active)

const cancel = () => {
  if (!confirm('Cancel your subscription at period end?')) return

  cancelForm.post(route('billing.cancel'), {
    preserveScroll: true,
  })
}

const resume = () => {
  resumeForm.post(route('billing.resume'), {
    preserveScroll: true,
  })
}

const statusLabel = computed(() => {
  if (!props.subscription) return 'None'
  if (onGracePeriod.value) return 'Canceling (grace period)'
  if (isActive.value) return 'Active'
  return props.subscription.status || 'Unknown'
})
</script>

<template>
  <FormSection>
    <template #title>Subscription</template>
    <template #description>
      Manage your plan, billing status, and cancellation.
    </template>

    <template #form>
      <div class="col-span-6 sm:col-span-4">
        <div class="rounded-2xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 p-4">
          <div class="flex items-start justify-between gap-4">
            <div class="space-y-1">
              <div class="text-sm font-semibold text-gray-900 dark:text-white">
                {{ props.subscription?.plan_name ?? 'Early Bird' }}
              </div>

              <div class="text-sm text-gray-600 dark:text-white/70">
                Status:
                <span
                  class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                  :class="onGracePeriod
                    ? 'bg-amber-100 text-amber-900 dark:bg-amber-400/20 dark:text-amber-200'
                    : isActive
                      ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-400/20 dark:text-emerald-200'
                      : 'bg-gray-100 text-gray-800 dark:bg-white/10 dark:text-white/70'"
                >
                  {{ statusLabel }}
                </span>
              </div>

              <div v-if="props.subscription?.next_charge_at" class="text-sm text-gray-600 dark:text-white/70">
                Next charge: <span class="font-medium text-gray-900 dark:text-white">{{ props.subscription.next_charge_at }}</span>
              </div>

              <div v-else class="text-sm text-gray-500 dark:text-white/50">
                Next charge: —
              </div>
            </div>

            <div class="flex items-center gap-2">
              <SecondaryButton
                type="button"
                @click="() => window.location.assign(route('billing.portal'))"
              >
                Billing Portal
              </SecondaryButton>

              <PrimaryButton
                v-if="props.subscription && !onGracePeriod"
                type="button"
                class="bg-red-600 hover:bg-red-700 focus:ring-red-500"
                :disabled="cancelForm.processing"
                @click.prevent.stop="cancel"
              >
                <span v-if="!cancelForm.processing">Cancel</span>
                <span v-else>Cancelling…</span>
              </PrimaryButton>

              <PrimaryButton
                v-else-if="props.subscription && onGracePeriod"
                type="button"
                :disabled="resumeForm.processing"
                @click.prevent.stop="resume"
              >
                <span v-if="!resumeForm.processing">Resume</span>
                <span v-else>Resuming…</span>
              </PrimaryButton>
            </div>
          </div>

          <div class="mt-3 text-xs text-gray-500 dark:text-white/50">
            Cancellation takes effect at the end of the current billing period.
          </div>
        </div>
      </div>
    </template>

    <template #actions>
      <ActionMessage :on="flashStatus === 'subscription-canceled'" class="me-3">
        Subscription will cancel at period end.
      </ActionMessage>

      <ActionMessage :on="flashStatus === 'subscription-resumed'" class="me-3">
        Subscription resumed.
      </ActionMessage>
    </template>
  </FormSection>
</template>
