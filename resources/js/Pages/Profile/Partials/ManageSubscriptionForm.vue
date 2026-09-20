<script setup>
import { computed, ref } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import DialogModal from '@/Components/DialogModal.vue'

const page = usePage()

const props = defineProps({
  subscription: { type: Object, required: true },
})

const confirmingCancellation = ref(false)
const cancelForm = useForm({})
const resumeForm = useForm({})

const flashStatus = computed(() => page.props.flash?.status)

const statusTone = computed(() => {
  if (['active', 'trialing', 'generic_trial'].includes(props.subscription.state)) return 'positive'
  if (['grace_period', 'past_due', 'incomplete', 'paused', 'status_conflict'].includes(props.subscription.state)) return 'warning'
  if (['ended', 'canceled', 'unpaid', 'incomplete_expired'].includes(props.subscription.state)) return 'negative'
  return 'neutral'
})

const formattedProviderStatus = computed(() => {
  const status = props.subscription.status
  if (!status) return null
  return status.replaceAll('_', ' ').replace(/\b\w/g, character => character.toUpperCase())
})

const formatDate = value => {
  if (!value) return null
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return null
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(date)
}

const trialEnd = computed(() => formatDate(props.subscription.trial_ends_at))
const accessEnd = computed(() => formatDate(props.subscription.ends_at))
const nextCharge = computed(() => formatDate(props.subscription.next_charge_at))

const closeCancellation = () => {
  if (cancelForm.processing) return
  confirmingCancellation.value = false
  cancelForm.clearErrors()
}

const cancel = () => {
  cancelForm.post(route('billing.cancel'), {
    preserveScroll: true,
    onSuccess: () => { confirmingCancellation.value = false },
  })
}

const resume = () => {
  resumeForm.post(route('billing.resume'), { preserveScroll: true })
}
</script>

<template>
  <section class="account-settings-card account-settings-card--featured" aria-labelledby="plan-settings-heading">
    <header class="account-settings-card__header">
      <div>
        <p class="account-settings-card__eyebrow">Membership</p>
        <h2 id="plan-settings-heading">Plan and billing</h2>
        <p>Review the subscription state saved for this account and manage billing securely through Stripe.</p>
      </div>
      <span class="account-status-pill" :data-tone="statusTone">
        <span aria-hidden="true" />
        {{ subscription.status_label }}
      </span>
    </header>

    <div class="account-settings-card__body">
      <div class="account-plan-hero">
        <div>
          <span class="account-plan-hero__label">Current plan</span>
          <strong>{{ subscription.plan_name || 'No subscription' }}</strong>
          <span v-if="subscription.billing_interval">{{ subscription.billing_interval }} billing</span>
          <span v-else-if="subscription.on_generic_trial">Trial without a saved Stripe subscription</span>
          <span v-else-if="!subscription.exists">Choose a plan to begin checkout</span>
        </div>

        <div class="account-plan-actions">
          <a
            v-if="subscription.can_open_portal"
            :href="route('billing.portal')"
            class="account-button account-button--secondary"
          >
            Open billing portal
            <span class="sr-only"> in Stripe</span>
          </a>
          <Link
            v-else-if="subscription.needs_checkout"
            :href="route('pricing')"
            class="account-button account-button--primary"
          >
            View plans
          </Link>

          <button
            v-if="subscription.can_cancel"
            type="button"
            class="account-button account-button--danger-quiet"
            @click="confirmingCancellation = true"
          >
            Cancel subscription
          </button>
          <button
            v-else-if="subscription.can_resume"
            type="button"
            class="account-button account-button--primary"
            :disabled="resumeForm.processing"
            @click="resume"
          >
            {{ resumeForm.processing ? 'Resuming…' : 'Resume subscription' }}
          </button>
        </div>
      </div>

      <dl class="account-plan-facts">
        <div>
          <dt>Product access</dt>
          <dd>{{ subscription.has_access ? 'Available' : 'Unavailable' }}</dd>
        </div>
        <div v-if="trialEnd">
          <dt>Trial ends</dt>
          <dd><time :datetime="subscription.trial_ends_at">{{ trialEnd }}</time></dd>
        </div>
        <div v-if="accessEnd">
          <dt>Access ends</dt>
          <dd><time :datetime="subscription.ends_at">{{ accessEnd }}</time></dd>
        </div>
        <div v-if="nextCharge">
          <dt>Next billing date</dt>
          <dd><time :datetime="subscription.next_charge_at">{{ nextCharge }}</time></dd>
        </div>
        <div v-if="formattedProviderStatus">
          <dt>Provider state</dt>
          <dd>{{ formattedProviderStatus }}</dd>
        </div>
      </dl>

      <div v-if="subscription.state === 'grace_period'" class="account-inline-notice" data-tone="warning">
        <p>
          Cancellation is scheduled. Product access remains available through
          <strong>{{ accessEnd || 'the saved period end' }}</strong>.
        </p>
      </div>
      <div v-else-if="['past_due', 'incomplete'].includes(subscription.state)" class="account-inline-notice" data-tone="warning">
        <p>Billing needs attention. Open the billing portal to review the latest provider details.</p>
      </div>
      <div v-else-if="subscription.state === 'paused'" class="account-inline-notice" data-tone="warning">
        <p>Billing is paused. Open the billing portal to review the saved provider state.</p>
      </div>
      <div v-else-if="['unpaid', 'incomplete_expired'].includes(subscription.state)" class="account-inline-notice" data-tone="negative">
        <p>This subscription cannot renew in its current state. Open the billing portal or choose a new plan.</p>
      </div>
      <div v-else-if="subscription.state === 'canceled'" class="account-inline-notice" data-tone="warning">
        <p>The provider reports cancellation, but a confirmed local end date has not been saved yet.</p>
      </div>
      <div v-else-if="subscription.state === 'unknown'" class="account-inline-notice" data-tone="warning">
        <p>The saved provider status is not recognized. Open the billing portal before changing this account.</p>
      </div>
      <div v-else-if="subscription.state === 'status_conflict'" class="account-inline-notice" data-tone="warning">
        <p>The provider status and saved end date conflict. Review billing before changing this account.</p>
      </div>
      <div v-else-if="subscription.can_open_portal && !nextCharge" class="account-inline-notice">
        <p>The next invoice date is available in Stripe's billing portal; it is not guessed from the plan cadence.</p>
      </div>

      <div aria-live="polite">
        <p v-if="flashStatus === 'subscription-canceled'" class="account-save-status" role="status">
          Cancellation scheduled for the end of the current billing period.
        </p>
        <p v-if="flashStatus === 'subscription-resumed'" class="account-save-status" role="status">
          Subscription resumed.
        </p>
      </div>
    </div>

    <DialogModal
      :show="confirmingCancellation"
      max-width="md"
      labelledby="cancel-subscription-dialog-title"
      :closeable="!cancelForm.processing"
      @close="closeCancellation"
    >
      <template #title>
        <span id="cancel-subscription-dialog-title">Cancel subscription?</span>
      </template>
      <template #content>
        <p>
          Your plan will stop renewing. You will keep access until the end of the current billing period.
        </p>
        <p v-if="accessEnd" class="account-dialog-note">
          The saved access end date is <strong>{{ accessEnd }}</strong>.
        </p>
        <p v-if="cancelForm.hasErrors" class="account-form-error" role="alert">
          We could not schedule the cancellation. Review the page and try again.
        </p>
      </template>
      <template #footer>
        <button
          type="button"
          class="account-button account-button--quiet"
          :disabled="cancelForm.processing"
          @click="closeCancellation"
        >
          Keep subscription
        </button>
        <button
          type="button"
          class="account-button account-button--danger"
          :disabled="cancelForm.processing"
          @click="cancel"
        >
          {{ cancelForm.processing ? 'Scheduling…' : 'Confirm cancellation' }}
        </button>
      </template>
    </DialogModal>
  </section>
</template>
