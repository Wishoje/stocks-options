<script setup>
import { nextTick, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import DialogModal from '@/Components/DialogModal.vue'
import InputError from '@/Components/InputError.vue'

defineProps({
  sessions: { type: Array, default: () => [] },
  sessionsSupported: { type: Boolean, default: false },
})

const confirmingLogout = ref(false)
const passwordInput = ref(null)

const form = useForm({ password: '' })

const confirmLogout = async () => {
  confirmingLogout.value = true
  await nextTick()
  passwordInput.value?.focus()
}

const closeModal = () => {
  confirmingLogout.value = false
  form.reset()
  form.clearErrors()
}

const logoutOtherBrowserSessions = () => {
  form.delete(route('other-browser-sessions.destroy'), {
    preserveScroll: true,
    onSuccess: closeModal,
    onError: () => passwordInput.value?.focus(),
    onFinish: () => form.reset(),
  })
}
</script>

<template>
  <section class="account-settings-card" aria-labelledby="session-settings-heading">
    <header class="account-settings-card__header">
      <div>
        <p class="account-settings-card__eyebrow">Security</p>
        <h2 id="session-settings-heading">Browser sessions</h2>
        <p>Review saved sessions and sign out browsers you are no longer using.</p>
      </div>
    </header>

    <div class="account-settings-card__body">
      <div v-if="sessionsSupported && sessions.length" class="account-session-list" role="list">
        <article
          v-for="(session, index) in sessions"
          :key="`${session.ip_address || 'unknown'}-${index}`"
          class="account-session"
          role="listitem"
        >
          <span class="account-session__icon" aria-hidden="true">
            <svg v-if="session.agent.is_desktop" viewBox="0 0 24 24" fill="none" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M4 5.75h16v10.5H4zM8 20h8M10 16.25V20m4-3.75V20" />
            </svg>
            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor">
              <rect x="7" y="2.5" width="10" height="19" rx="2" stroke-width="1.6" />
              <path stroke-linecap="round" stroke-width="1.6" d="M10.5 5h3M11 18.5h2" />
            </svg>
          </span>
          <div class="account-session__details">
            <strong>
              {{ session.agent.platform || 'Unknown platform' }} ·
              {{ session.agent.browser || 'Unknown browser' }}
            </strong>
            <span>{{ session.ip_address || 'IP address unavailable' }}</span>
          </div>
          <div class="account-session__activity">
            <span v-if="session.is_current_device" class="account-status-pill" data-tone="positive">
              <span aria-hidden="true" />This device
            </span>
            <span v-else>Last active {{ session.last_active }}</span>
          </div>
        </article>
      </div>

      <div v-else class="account-empty-state">
        <strong>{{ sessionsSupported ? 'No saved browser sessions found' : 'Session details unavailable' }}</strong>
        <p v-if="sessionsSupported">
          This session may be using a browser state that is not stored in the session table.
        </p>
        <p v-else>
          The current session storage does not expose a device list. You can still sign out every other browser session.
        </p>
      </div>

      <div class="account-inline-notice">
        <p>
          If you do not recognize a session, sign out other browsers and then update your password.
        </p>
      </div>

      <div class="account-form-footer">
        <p v-if="form.recentlySuccessful" class="account-save-status" role="status" aria-live="polite">
          Other browser sessions signed out.
        </p>
        <button type="button" class="account-button account-button--secondary" @click="confirmLogout">
          Sign out other browsers
        </button>
      </div>
    </div>

    <DialogModal
      :show="confirmingLogout"
      max-width="md"
      labelledby="logout-sessions-dialog-title"
      @close="closeModal"
    >
      <template #title>
        <span id="logout-sessions-dialog-title">Sign out other browser sessions?</span>
      </template>

      <template #content>
        <p>
          Your current browser will stay signed in. Enter your password to close your other active sessions.
        </p>
        <div class="account-form-field account-dialog-field">
          <label for="logout-sessions-password">Current password</label>
          <input
            id="logout-sessions-password"
            ref="passwordInput"
            v-model="form.password"
            type="password"
            required
            autocomplete="current-password"
            :aria-invalid="form.errors.password ? 'true' : 'false'"
            :aria-describedby="form.errors.password ? 'logout-sessions-password-error' : undefined"
            @keyup.enter="logoutOtherBrowserSessions"
          >
          <InputError id="logout-sessions-password-error" :message="form.errors.password" />
        </div>
      </template>

      <template #footer>
        <button type="button" class="account-button account-button--quiet" @click="closeModal">
          Keep sessions
        </button>
        <button
          type="button"
          class="account-button account-button--primary"
          :disabled="form.processing"
          @click="logoutOtherBrowserSessions"
        >
          {{ form.processing ? 'Signing out…' : 'Sign out other browsers' }}
        </button>
      </template>
    </DialogModal>
  </section>
</template>
