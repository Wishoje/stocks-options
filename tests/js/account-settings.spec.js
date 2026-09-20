import { mount } from '@vue/test-utils'
import { nextTick, reactive } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const state = vi.hoisted(() => ({
  forms: [],
  page: { props: { flash: {}, auth: { user: {} }, jetstream: {} } },
}))

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<div><slot /></div>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { post: vi.fn(), delete: vi.fn(), put: vi.fn() },
  usePage: () => state.page,
  useForm: initial => {
    const form = reactive({
      ...initial,
      errors: {},
      hasErrors: false,
      processing: false,
      recentlySuccessful: false,
      post: vi.fn(),
      put: vi.fn(),
      delete: vi.fn(),
      reset: vi.fn(),
      clearErrors: vi.fn(),
    })
    state.forms.push(form)
    return form
  },
}))

import ManageSubscriptionForm from '@/Pages/Profile/Partials/ManageSubscriptionForm.vue'
import DeleteUserForm from '@/Pages/Profile/Partials/DeleteUserForm.vue'
import Show from '@/Pages/Profile/Show.vue'
import UpdatePasswordForm from '@/Pages/Profile/Partials/UpdatePasswordForm.vue'

const dialogStub = {
  props: ['show', 'closeable', 'labelledby'],
  template: '<div v-if="show" role="dialog" :data-closeable="String(closeable)" :aria-labelledby="labelledby"><slot name="title" /><slot name="content" /><slot name="footer" /></div>',
}

const noSubscription = {
  exists: false,
  plan_name: null,
  billing_interval: null,
  state: 'none',
  status: null,
  status_label: 'No subscription',
  active: false,
  has_access: false,
  needs_checkout: true,
  on_trial: false,
  on_generic_trial: false,
  on_grace_period: false,
  trial_ends_at: null,
  ends_at: null,
  next_charge_at: null,
  can_open_portal: false,
  can_cancel: false,
  can_resume: false,
}

const activeSubscription = {
  ...noSubscription,
  exists: true,
  plan_name: 'Early Bird',
  billing_interval: 'Monthly',
  state: 'active',
  status: 'active',
  status_label: 'Active',
  active: true,
  has_access: true,
  needs_checkout: false,
  can_open_portal: true,
  can_cancel: true,
}

describe('account settings', () => {
  beforeEach(() => {
    state.forms.length = 0
    state.page.props = reactive({ flash: {}, auth: { user: {} }, jetstream: {} })
    vi.stubGlobal('route', vi.fn(name => `/${name}`))
  })

  afterEach(() => vi.unstubAllGlobals())

  it('shows a truthful empty-plan state without billing actions that require a Stripe customer', () => {
    const wrapper = mount(ManageSubscriptionForm, {
      props: { subscription: noSubscription },
      global: {
        mocks: { route: globalThis.route },
        stubs: { DialogModal: dialogStub },
      },
    })

    expect(wrapper.text()).toContain('No subscription')
    expect(wrapper.text()).toContain('View plans')
    expect(wrapper.text()).not.toContain('Open billing portal')
    expect(wrapper.text()).not.toContain('Cancel subscription')
    wrapper.unmount()
  })

  it('requires an explicit dialog confirmation before posting subscription cancellation', async () => {
    const wrapper = mount(ManageSubscriptionForm, {
      props: { subscription: activeSubscription },
      global: {
        mocks: { route: globalThis.route },
        stubs: { DialogModal: dialogStub },
      },
    })
    const form = state.forms[0]

    expect(wrapper.text()).toContain('Open billing portal')
    await wrapper.get('button.account-button--danger-quiet').trigger('click')
    expect(wrapper.get('[role="dialog"]').text()).toContain('Cancel subscription?')
    expect(form.post).not.toHaveBeenCalled()

    await wrapper.get('[role="dialog"] .account-button--danger').trigger('click')
    expect(form.post).toHaveBeenCalledWith('/billing.cancel', expect.objectContaining({ preserveScroll: true }))
    wrapper.unmount()
  })

  it('keeps account deletion locked while processing and presents each error once', async () => {
    const wrapper = mount(DeleteUserForm, {
      global: {
        mocks: { route: globalThis.route },
        stubs: { DialogModal: dialogStub },
      },
    })
    const form = state.forms[0]

    await wrapper.get('button.account-button--danger').trigger('click')
    form.errors.account = 'The saved subscription has not ended.'
    form.errors.password = 'The password is incorrect.'
    form.processing = true
    await nextTick()

    const dialog = wrapper.get('[role="dialog"]')
    expect(dialog.attributes('data-closeable')).toBe('false')
    expect(wrapper.findAll('#delete-account-dialog-error')).toHaveLength(1)
    expect(wrapper.find('#delete-account-error').exists()).toBe(false)
    expect(wrapper.get('#delete-account-password').attributes('disabled')).toBeDefined()

    const buttons = dialog.findAll('button')
    expect(buttons.every(button => button.attributes('disabled') !== undefined)).toBe(true)
    await buttons[0].trigger('click')
    await buttons[1].trigger('click')
    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
    expect(form.delete).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('renders only enabled settings and associates password errors with their fields', async () => {
    state.page.props = reactive({
      flash: {},
      auth: { user: { name: 'Ada Trader', email: 'ada@example.test' } },
      jetstream: {
        canUpdateProfileInformation: true,
        canUpdatePassword: true,
        canManageTwoFactorAuthentication: false,
        hasAccountDeletionFeatures: false,
      },
    })

    const show = mount(Show, {
      props: { subscription: activeSubscription },
      global: {
        mocks: { $page: state.page },
        stubs: {
          AppLayout: { template: '<div><slot name="header" /><slot /></div>' },
          ManageSubscriptionForm: { template: '<section data-section="plan" />' },
          UpdateProfileInformationForm: { template: '<section data-section="profile" />' },
          UpdatePasswordForm: { template: '<section data-section="password" />' },
          TwoFactorAuthenticationForm: { template: '<section data-section="two-factor" />' },
          LogoutOtherBrowserSessionsForm: { template: '<section data-section="sessions" />' },
          DeleteUserForm: { template: '<section data-section="delete" />' },
        },
      },
    })

    expect(show.text()).toContain('Dashboard access active')
    expect(show.find('[data-section="profile"]').exists()).toBe(true)
    expect(show.find('[data-section="password"]').exists()).toBe(true)
    expect(show.find('[data-section="two-factor"]').exists()).toBe(false)
    expect(show.find('[data-section="delete"]').exists()).toBe(false)
    show.unmount()

    const password = mount(UpdatePasswordForm)
    const form = state.forms.at(-1)
    form.errors.current_password = 'Current password is incorrect.'
    form.errors.password = 'Use at least eight characters.'
    await nextTick()

    expect(password.get('#current_password').attributes()).toMatchObject({
      'aria-invalid': 'true',
      'aria-describedby': 'current-password-error',
    })
    expect(password.get('#password').attributes('aria-invalid')).toBe('true')
    expect(password.get('#new-password-error').attributes('role')).toBe('alert')
    password.unmount()
  })
})
