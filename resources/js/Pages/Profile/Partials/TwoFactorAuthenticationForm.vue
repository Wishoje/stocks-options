<script setup>
import { computed, ref, watch } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import ConfirmsPassword from '@/Components/ConfirmsPassword.vue'
import InputError from '@/Components/InputError.vue'

const props = defineProps({
  requiresConfirmation: { type: Boolean, default: false },
})

const page = usePage()
const enabling = ref(false)
const confirming = ref(false)
const disabling = ref(false)
const recoveryMessage = ref('')
const qrCode = ref(null)
const setupKey = ref(null)
const recoveryCodes = ref([])

const confirmationForm = useForm({ code: '' })

const twoFactorEnabled = computed(
  () => !enabling.value && page.props.auth.user?.two_factor_enabled,
)

watch(twoFactorEnabled, enabled => {
  if (!enabled) {
    confirmationForm.reset()
    confirmationForm.clearErrors()
  }
})

const showQrCode = () => axios.get(route('two-factor.qr-code')).then(response => {
  qrCode.value = response.data.svg
})

const showSetupKey = () => axios.get(route('two-factor.secret-key')).then(response => {
  setupKey.value = response.data.secretKey
})

const showRecoveryCodes = () => axios.get(route('two-factor.recovery-codes')).then(response => {
  recoveryCodes.value = response.data
})

const enableTwoFactorAuthentication = () => {
  enabling.value = true
  recoveryMessage.value = ''

  router.post(route('two-factor.enable'), {}, {
    preserveScroll: true,
    onSuccess: async () => {
      await Promise.all([showQrCode(), showSetupKey(), showRecoveryCodes()])
      confirming.value = props.requiresConfirmation
    },
    onFinish: () => { enabling.value = false },
  })
}

const confirmTwoFactorAuthentication = () => {
  confirmationForm.post(route('two-factor.confirm'), {
    errorBag: 'confirmTwoFactorAuthentication',
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => {
      confirming.value = false
      qrCode.value = null
      setupKey.value = null
    },
  })
}

const regenerateRecoveryCodes = () => {
  recoveryMessage.value = ''
  axios.post(route('two-factor.recovery-codes')).then(async () => {
    await showRecoveryCodes()
    recoveryMessage.value = 'New recovery codes generated.'
  })
}

const disableTwoFactorAuthentication = () => {
  disabling.value = true
  recoveryMessage.value = ''

  router.delete(route('two-factor.disable'), {
    preserveScroll: true,
    onSuccess: () => {
      confirming.value = false
      qrCode.value = null
      setupKey.value = null
      recoveryCodes.value = []
    },
    onFinish: () => { disabling.value = false },
  })
}
</script>

<template>
  <section class="account-settings-card" aria-labelledby="two-factor-settings-heading">
    <header class="account-settings-card__header">
      <div>
        <p class="account-settings-card__eyebrow">Security</p>
        <h2 id="two-factor-settings-heading">Two-factor authentication</h2>
        <p>Require a time-based code from an authenticator app when you sign in.</p>
      </div>
      <span class="account-status-pill" :data-tone="twoFactorEnabled ? 'positive' : 'neutral'">
        <span aria-hidden="true" />
        {{ twoFactorEnabled ? (confirming ? 'Setup pending' : 'Enabled') : 'Not enabled' }}
      </span>
    </header>

    <div class="account-settings-card__body">
      <div v-if="twoFactorEnabled && qrCode" class="account-two-factor-setup">
        <div class="account-inline-notice" data-tone="warning">
          <p v-if="confirming">
            Scan the QR code or enter the setup key, then enter the six-digit code to finish setup.
          </p>
          <p v-else>
            Scan the QR code or enter the setup key in your authenticator app.
          </p>
        </div>

        <div class="account-two-factor-qr" aria-label="Authenticator setup QR code" v-html="qrCode" />

        <p v-if="setupKey" class="account-setup-key">
          <span>Setup key</span>
          <code>{{ setupKey }}</code>
        </p>

        <form v-if="confirming" class="account-settings-form account-two-factor-confirm" @submit.prevent="confirmTwoFactorAuthentication">
          <div class="account-form-field">
            <label for="two-factor-code">Authentication code</label>
            <input
              id="two-factor-code"
              v-model="confirmationForm.code"
              type="text"
              name="code"
              inputmode="numeric"
              autocomplete="one-time-code"
              autofocus
              required
              :aria-invalid="confirmationForm.errors.code ? 'true' : 'false'"
              :aria-describedby="confirmationForm.errors.code ? 'two-factor-code-error' : undefined"
            >
            <InputError id="two-factor-code-error" :message="confirmationForm.errors.code" />
          </div>
          <button
            type="submit"
            class="account-button account-button--primary"
            :disabled="confirmationForm.processing"
          >
            {{ confirmationForm.processing ? 'Confirming…' : 'Confirm setup' }}
          </button>
        </form>
      </div>

      <div v-if="recoveryCodes.length && !confirming" class="account-recovery-codes">
        <div>
          <h3>Recovery codes</h3>
          <p>Store these codes in a password manager. Each code can recover account access once.</p>
        </div>
        <ul aria-label="Two-factor recovery codes">
          <li v-for="code in recoveryCodes" :key="code"><code>{{ code }}</code></li>
        </ul>
      </div>

      <p v-if="recoveryMessage" class="account-save-status" role="status" aria-live="polite">
        {{ recoveryMessage }}
      </p>

      <div class="account-form-footer account-form-footer--wrap">
        <ConfirmsPassword v-if="!twoFactorEnabled" @confirmed="enableTwoFactorAuthentication">
          <button
            type="button"
            class="account-button account-button--primary"
            :disabled="enabling"
          >
            {{ enabling ? 'Enabling…' : 'Enable two-factor authentication' }}
          </button>
        </ConfirmsPassword>

        <template v-else>
          <ConfirmsPassword v-if="recoveryCodes.length && !confirming" @confirmed="regenerateRecoveryCodes">
            <button type="button" class="account-button account-button--secondary">
              Generate new recovery codes
            </button>
          </ConfirmsPassword>

          <ConfirmsPassword v-if="!recoveryCodes.length && !confirming" @confirmed="showRecoveryCodes">
            <button type="button" class="account-button account-button--secondary">
              Show recovery codes
            </button>
          </ConfirmsPassword>

          <ConfirmsPassword @confirmed="disableTwoFactorAuthentication">
            <button
              type="button"
              :class="['account-button', confirming ? 'account-button--quiet' : 'account-button--danger-quiet']"
              :disabled="disabling"
            >
              {{ disabling ? 'Updating…' : (confirming ? 'Cancel setup' : 'Disable two-factor authentication') }}
            </button>
          </ConfirmsPassword>
        </template>
      </div>
    </div>
  </section>
</template>
