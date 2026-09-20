import { flushPromises, mount } from '@vue/test-utils'
import { nextTick, reactive } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const state = vi.hoisted(() => ({
  forms: [],
  page: {
    url: '/login',
    props: {
      billing: {},
      errorBags: {},
      jetstream: { hasTermsAndPrivacyPolicyFeature: true },
    },
  },
}))

vi.mock('@inertiajs/vue3', async () => {
  const { reactive: makeReactive } = await import('vue')

  return {
    Head: { template: '<div><slot /></div>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => state.page,
    useForm: initial => {
      const form = makeReactive({
        ...initial,
        errors: {},
        processing: false,
        post: vi.fn(),
        reset: vi.fn(),
        transform: vi.fn(function transform() {
          return this
        }),
      })
      state.forms.push(form)
      return form
    },
  }
})

import ConfirmsPassword from '@/Components/ConfirmsPassword.vue'
import ConfirmPassword from '@/Pages/Auth/ConfirmPassword.vue'
import ForgotPassword from '@/Pages/Auth/ForgotPassword.vue'
import Login from '@/Pages/Auth/Login.vue'
import Register from '@/Pages/Auth/Register.vue'
import ResetPassword from '@/Pages/Auth/ResetPassword.vue'
import TwoFactorChallenge from '@/Pages/Auth/TwoFactorChallenge.vue'

let wrappers = []

function mountAuthPage(component, props = {}) {
  state.forms.length = 0
  const wrapper = mount(component, {
    props,
    global: {
      mocks: {
        $page: state.page,
        route: name => `/${name}`,
      },
    },
  })
  wrappers.push(wrapper)
  return { wrapper, form: state.forms.at(-1) }
}

async function showErrors(form, fields) {
  Object.assign(form.errors, Object.fromEntries(fields.map(field => [field, `${field} is invalid.`])))
  await nextTick()
}

function expectAssociation(wrapper, inputId, errorId, message) {
  const input = wrapper.get(`#${inputId}`)
  const error = wrapper.get(`#${errorId}`)

  expect(input.attributes()).toMatchObject({
    'aria-invalid': 'true',
    'aria-describedby': errorId,
  })
  expect(error.attributes('role')).toBe('alert')
  expect(error.text()).toBe(message)
}

describe('auth validation error accessibility', () => {
  beforeEach(() => {
    state.forms.length = 0
    state.page.url = '/login'
    state.page.props = reactive({
      billing: {},
      errorBags: {},
      jetstream: { hasTermsAndPrivacyPolicyFeature: true },
    })
    window.gtag = vi.fn()
    vi.stubGlobal('route', vi.fn(name => `/${name}`))
  })

  afterEach(() => {
    wrappers.forEach(wrapper => wrapper.unmount())
    wrappers = []
    vi.unstubAllGlobals()
  })

  it('associates login and registration errors with every rendered control', async () => {
    const login = mountAuthPage(Login, { canResetPassword: true })
    await showErrors(login.form, ['email', 'password'])
    expectAssociation(login.wrapper, 'email', 'email-error', 'email is invalid.')
    expectAssociation(login.wrapper, 'password', 'password-error', 'password is invalid.')

    const registration = mountAuthPage(Register)
    await showErrors(registration.form, ['name', 'email', 'password', 'password_confirmation', 'terms'])
    expectAssociation(registration.wrapper, 'name', 'name-error', 'name is invalid.')
    expectAssociation(registration.wrapper, 'email', 'email-error', 'email is invalid.')
    expectAssociation(registration.wrapper, 'password', 'password-error', 'password is invalid.')
    expectAssociation(
      registration.wrapper,
      'password_confirmation',
      'password-confirmation-error',
      'password_confirmation is invalid.',
    )
    expectAssociation(registration.wrapper, 'terms', 'terms-error', 'terms is invalid.')
  })

  it('associates recovery and confirmation errors with their fields', async () => {
    const forgot = mountAuthPage(ForgotPassword)
    await showErrors(forgot.form, ['email'])
    expectAssociation(forgot.wrapper, 'email', 'email-error', 'email is invalid.')

    const reset = mountAuthPage(ResetPassword, { email: 'trader@example.com', token: 'safe-test-token' })
    await showErrors(reset.form, ['email', 'password', 'password_confirmation'])
    expectAssociation(reset.wrapper, 'email', 'email-error', 'email is invalid.')
    expectAssociation(reset.wrapper, 'password', 'password-error', 'password is invalid.')
    expectAssociation(
      reset.wrapper,
      'password_confirmation',
      'password-confirmation-error',
      'password_confirmation is invalid.',
    )

    const confirmation = mountAuthPage(ConfirmPassword)
    await showErrors(confirmation.form, ['password'])
    expectAssociation(confirmation.wrapper, 'password', 'password-error', 'password is invalid.')
  })

  it('keeps both two-factor modes associated with their own error messages', async () => {
    const challenge = mountAuthPage(TwoFactorChallenge)
    await showErrors(challenge.form, ['code', 'recovery_code'])
    expectAssociation(challenge.wrapper, 'code', 'code-error', 'code is invalid.')

    await challenge.wrapper.get('button[type="button"]').trigger('click')
    expectAssociation(
      challenge.wrapper,
      'recovery_code',
      'recovery-code-error',
      'recovery_code is invalid.',
    )
  })

  it('associates an asynchronous password-confirmation error in the reusable dialog', async () => {
    vi.useFakeTimers()
    const axios = {
      get: vi.fn().mockResolvedValue({ data: { confirmed: false } }),
      post: vi.fn().mockRejectedValue({
        response: { data: { errors: { password: ['Incorrect password.'] } } },
      }),
    }
    vi.stubGlobal('axios', axios)

    const wrapper = mount(ConfirmsPassword, {
      slots: { default: '<button type="button" data-test="open-confirmation">Continue</button>' },
      global: {
        stubs: {
          DialogModal: {
            props: ['show'],
            template: '<div v-if="show"><slot name="content" /><slot name="footer" /></div>',
          },
        },
      },
    })
    wrappers.push(wrapper)

    await wrapper.get('[data-test="open-confirmation"]').trigger('click')
    await flushPromises()
    vi.runOnlyPendingTimers()

    const password = wrapper.get('#confirm-password')
    expect(password.attributes('aria-invalid')).toBe('false')
    expect(password.attributes('aria-describedby')).toBeUndefined()

    await password.trigger('keyup', { key: 'Enter' })
    await flushPromises()
    expectAssociation(wrapper, 'confirm-password', 'confirm-password-error', 'Incorrect password.')
  })
})
