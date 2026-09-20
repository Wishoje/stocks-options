<script setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import InputError from '@/Components/InputError.vue'

const passwordInput = ref(null)
const currentPasswordInput = ref(null)

const form = useForm({
  current_password: '',
  password: '',
  password_confirmation: '',
})

const updatePassword = () => {
  form.put(route('user-password.update'), {
    errorBag: 'updatePassword',
    preserveScroll: true,
    onSuccess: () => form.reset(),
    onError: () => {
      if (form.errors.password) {
        form.reset('password', 'password_confirmation')
        passwordInput.value?.focus()
      }

      if (form.errors.current_password) {
        form.reset('current_password')
        currentPasswordInput.value?.focus()
      }
    },
  })
}
</script>

<template>
  <section class="account-settings-card" aria-labelledby="password-settings-heading">
    <header class="account-settings-card__header">
      <div>
        <p class="account-settings-card__eyebrow">Security</p>
        <h2 id="password-settings-heading">Password</h2>
        <p>Use a long, unique password that you do not use on another site.</p>
      </div>
    </header>

    <form class="account-settings-form" novalidate @submit.prevent="updatePassword">
      <div class="account-form-field account-form-field--wide">
        <label for="current_password">Current password</label>
        <input
          id="current_password"
          ref="currentPasswordInput"
          v-model="form.current_password"
          type="password"
          required
          autocomplete="current-password"
          :aria-invalid="form.errors.current_password ? 'true' : 'false'"
          :aria-describedby="form.errors.current_password ? 'current-password-error' : undefined"
        >
        <InputError id="current-password-error" :message="form.errors.current_password" />
      </div>

      <div class="account-form-field">
        <label for="password">New password</label>
        <input
          id="password"
          ref="passwordInput"
          v-model="form.password"
          type="password"
          required
          autocomplete="new-password"
          :aria-invalid="form.errors.password ? 'true' : 'false'"
          :aria-describedby="form.errors.password ? 'new-password-hint new-password-error' : 'new-password-hint'"
        >
        <span id="new-password-hint" class="account-field-hint">At least 8 characters is required.</span>
        <InputError id="new-password-error" :message="form.errors.password" />
      </div>

      <div class="account-form-field">
        <label for="password_confirmation">Confirm new password</label>
        <input
          id="password_confirmation"
          v-model="form.password_confirmation"
          type="password"
          required
          autocomplete="new-password"
          :aria-invalid="form.errors.password_confirmation ? 'true' : 'false'"
          :aria-describedby="form.errors.password_confirmation ? 'password-confirmation-error' : undefined"
        >
        <InputError id="password-confirmation-error" :message="form.errors.password_confirmation" />
      </div>

      <div class="account-form-footer account-form-field--wide">
        <p v-if="form.recentlySuccessful" class="account-save-status" role="status" aria-live="polite">
          Password updated.
        </p>
        <button
          type="submit"
          class="account-button account-button--primary"
          :disabled="form.processing"
        >
          {{ form.processing ? 'Updating…' : 'Update password' }}
        </button>
      </div>
    </form>
  </section>
</template>
