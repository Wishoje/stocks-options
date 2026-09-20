import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const state = vi.hoisted(() => ({
  page: { url: '/features', props: { auth: { user: null }, billing: {}, offer: { trial_days: 7 } } },
  routerPost: vi.fn(),
}))

vi.mock('@inertiajs/vue3', () => ({
  usePage: () => state.page,
  Link: { inheritAttrs: false, props: ['href'], template: '<a :href="href" v-bind="$attrs"><slot /></a>' },
  router: { post: (...args) => state.routerPost(...args) },
}))

import MarketingLayout from '@/Layouts/MarketingLayout.vue'

describe('marketing navigation shell', () => {
  beforeEach(() => {
    state.page.url = '/features'
    state.page.props = { auth: { user: null }, billing: {}, offer: { trial_days: 7 } }
    state.routerPost.mockReset()
  })

  it('keeps all public routes visible, marks the active route, and uses the correct support address', () => {
    const wrapper = mount(MarketingLayout, { slots: { default: '<h1>Page</h1>' } })
    const primary = wrapper.get('[aria-label="Primary navigation"]')
    for (const label of ['Home', 'Features', 'Pricing', 'Contact', 'Log in', 'Start free trial']) {
      expect(primary.text()).toContain(label)
    }
    expect(primary.find('a[href="/features"]').attributes('aria-current')).toBe('page')
    expect(wrapper.get('a[href="/terms-of-service"]').text()).toBe('Terms')
    expect(wrapper.get('a[href="/privacy-policy"]').text()).toBe('Privacy')
    expect(wrapper.get('a[href="mailto:support@gexoptions.com"]').text()).toBe('support@gexoptions.com')
    expect(wrapper.get('.mk-footer').text()).toContain('GEX Options.')
    expect(wrapper.get('.mk-footer').text()).not.toContain('GEX Options, Inc.')
    expect(wrapper.get('.mk-skip-link').attributes('href')).toBe('#marketing-main')
    const logos = wrapper.findAll('img[src="/marketing/gexoptions_logo.svg"]')
    expect(logos).toHaveLength(2)
    expect(logos[0].classes()).toContain('mk-brand__logo')
    expect(logos[0].attributes()).toMatchObject({ alt: 'GEX Options', width: '798', height: '316' })
    expect(wrapper.get('.mk-brand--footer img').attributes('loading')).toBe('lazy')
    wrapper.unmount()
  })

  it('opens the mobile navigation with focus and restores focus on Escape', async () => {
    const wrapper = mount(MarketingLayout, { attachTo: document.body })
    const trigger = wrapper.get('[aria-controls="marketing-mobile-navigation"]')
    await trigger.trigger('click')
    expect(trigger.attributes('aria-expanded')).toBe('true')
    expect(document.activeElement).toBe(wrapper.get('#marketing-mobile-navigation a').element)

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()
    expect(trigger.attributes('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(trigger.element)
    wrapper.unmount()
  })

  it('shows account navigation and logs out with POST for authenticated users', async () => {
    state.page.props = { auth: { user: { id: 7 } }, billing: { needs_checkout: false }, offer: { trial_days: 7 } }
    const wrapper = mount(MarketingLayout)
    const primary = wrapper.get('[aria-label="Primary navigation"]')
    expect(primary.text()).toContain('Profile')
    expect(primary.text()).toContain('Open dashboard')
    expect(primary.text()).not.toContain('Log in')
    await primary.findAll('button').find(button => button.text() === 'Log out').trigger('click')
    expect(state.routerPost).toHaveBeenCalledWith('/logout')
    wrapper.unmount()
  })
})
