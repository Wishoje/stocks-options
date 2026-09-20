<script setup>
import { computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import DeleteUserForm from '@/Pages/Profile/Partials/DeleteUserForm.vue'
import LogoutOtherBrowserSessionsForm from '@/Pages/Profile/Partials/LogoutOtherBrowserSessionsForm.vue'
import ManageSubscriptionForm from '@/Pages/Profile/Partials/ManageSubscriptionForm.vue'
import TwoFactorAuthenticationForm from '@/Pages/Profile/Partials/TwoFactorAuthenticationForm.vue'
import UpdatePasswordForm from '@/Pages/Profile/Partials/UpdatePasswordForm.vue'
import UpdateProfileInformationForm from '@/Pages/Profile/Partials/UpdateProfileInformationForm.vue'

const props = defineProps({
  confirmsTwoFactorAuthentication: { type: Boolean, default: false },
  sessions: { type: Array, default: () => [] },
  sessionsSupported: { type: Boolean, default: false },
  subscription: { type: Object, required: true },
})

const accessLabel = computed(() => {
  if (props.subscription.has_access) return 'Dashboard access active'
  if (props.subscription.needs_checkout) return 'Checkout required'
  return 'Dashboard access inactive'
})

const accessTone = computed(() => {
  if (props.subscription.has_access) return 'positive'
  if (props.subscription.needs_checkout) return 'warning'
  return 'neutral'
})
</script>

<template>
  <AppLayout title="Account settings">
    <template #header>
      <div class="account-page-heading">
        <p>Account</p>
        <h1 id="account-page-title">Settings and billing</h1>
        <span>Keep your identity, access, and security details current.</span>
      </div>
    </template>

    <div
      class="gex-ui account-settings-page"
      data-theme="dark"
      aria-labelledby="account-page-title"
    >
      <div class="account-settings-page__frame">
        <aside class="account-settings-nav-wrap">
          <nav class="account-settings-nav" aria-label="Account settings sections">
            <p class="account-settings-nav__label">On this page</p>
            <a href="#plan-settings">Plan and billing</a>
            <a v-if="$page.props.jetstream.canUpdateProfileInformation" href="#profile-settings">
              Profile information
            </a>
            <a v-if="$page.props.jetstream.canUpdatePassword" href="#password-settings">
              Password
            </a>
            <a
              v-if="$page.props.jetstream.canManageTwoFactorAuthentication"
              href="#two-factor-settings"
            >
              Two-factor authentication
            </a>
            <a href="#session-settings">Browser sessions</a>
            <a
              v-if="$page.props.jetstream.hasAccountDeletionFeatures"
              href="#delete-account-settings"
            >
              Delete account
            </a>
          </nav>
        </aside>

        <div class="account-settings-content">
          <section class="account-account-summary" aria-label="Account overview">
            <div>
              <span class="account-account-summary__label">Signed in as</span>
              <strong>{{ $page.props.auth.user.name }}</strong>
              <span>{{ $page.props.auth.user.email }}</span>
            </div>
            <div>
              <span class="account-account-summary__label">Plan status</span>
              <strong>{{ subscription.status_label }}</strong>
              <span>{{ subscription.plan_name || 'No plan selected' }}</span>
            </div>
            <div>
              <span class="account-account-summary__label">Product access</span>
              <strong class="account-status-pill" :data-tone="accessTone">
                <span aria-hidden="true" />
                {{ accessLabel }}
              </strong>
              <span>Status reflects the latest saved billing event.</span>
            </div>
          </section>

          <ManageSubscriptionForm
            id="plan-settings"
            :subscription="subscription"
          />

          <UpdateProfileInformationForm
            v-if="$page.props.jetstream.canUpdateProfileInformation"
            id="profile-settings"
            :user="$page.props.auth.user"
          />

          <UpdatePasswordForm
            v-if="$page.props.jetstream.canUpdatePassword"
            id="password-settings"
          />

          <TwoFactorAuthenticationForm
            v-if="$page.props.jetstream.canManageTwoFactorAuthentication"
            id="two-factor-settings"
            :requires-confirmation="confirmsTwoFactorAuthentication"
          />

          <LogoutOtherBrowserSessionsForm
            id="session-settings"
            :sessions="sessions"
            :sessions-supported="sessionsSupported"
          />

          <DeleteUserForm
            v-if="$page.props.jetstream.hasAccountDeletionFeatures"
            id="delete-account-settings"
          />
        </div>
      </div>
    </div>
  </AppLayout>
</template>
