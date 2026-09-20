import { flushPromises, mount } from '@vue/test-utils'
import axios from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import AppLayout from '@/Layouts/AppLayout.vue'

const router = {
  post: vi.fn(),
  put: vi.fn(),
}

vi.mock('axios', () => ({
  default: {
    get: vi.fn(),
  },
}))

vi.mock('@inertiajs/vue3', () => ({
  Head: { name: 'Head', template: '<div data-head />' },
  Link: {
    name: 'Link',
    inheritAttrs: false,
    props: ['href'],
    template: '<a :href="href" v-bind="$attrs" @click.prevent><slot /></a>',
  },
  router: {
    post: (...args) => router.post(...args),
    put: (...args) => router.put(...args),
  },
}))

const componentStubs = {
  ApplicationMark: {
    template: '<img src="/marketing/gexoptions_logo.svg" alt="GEX Options" v-bind="$attrs">',
  },
  Banner: { template: '<div />' },
  Dropdown: {
    template: '<div><slot name="trigger" /><div><slot name="content" /></div></div>',
  },
  DropdownLink: {
    props: ['href', 'as'],
    emits: ['click'],
    template: '<button v-if="as === \'button\'" type="button" @click="$emit(\'click\')"><slot /></button><a v-else :href="href" @click.prevent="$emit(\'click\')"><slot /></a>',
  },
  NavLink: {
    props: ['href', 'active'],
    emits: ['click'],
    template: '<a :href="href" :data-active="active" @click.prevent="$emit(\'click\')"><slot /></a>',
  },
  ResponsiveNavLink: {
    props: ['href', 'active', 'as'],
    emits: ['click'],
    template: '<button v-if="as === \'button\'" type="button" @click="$emit(\'click\')"><slot /></button><a v-else :href="href" :data-active="active" @click.prevent="$emit(\'click\')"><slot /></a>',
  },
}

let routeMock

function pageProps(userId = 9) {
  return {
    auth: {
      user: {
        id: userId,
        name: 'Ada Trader',
        email: 'ada@example.test',
        profile_photo_url: '/avatar.png',
        current_team_id: 11,
        current_team: { id: 11, name: 'Gamma Desk' },
        all_teams: [{ id: 11, name: 'Gamma Desk' }],
      },
    },
    jetstream: {
      canCreateTeams: true,
      hasTeamFeatures: true,
      managesProfilePhotos: false,
    },
  }
}

function mountLayout(userId = 9) {
  return mount(AppLayout, {
    attachTo: document.body,
    props: { title: 'Dashboard' },
    slots: { default: '<div data-page-content>Page</div>' },
    global: {
      mocks: { $page: { props: pageProps(userId) }, route: routeMock },
      stubs: componentStubs,
    },
  })
}

describe('AppLayout navigation shell', () => {
  beforeEach(() => {
    router.post.mockReset()
    router.put.mockReset()
    axios.get.mockReset()
    routeMock = vi.fn((name) => name ? `/${name}` : {
      current: (candidate) => candidate === 'dashboard',
    })
    vi.stubGlobal('route', routeMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('retains every primary route and the existing EOD Health gate', () => {
    const allowed = mountLayout(3)
    expect(allowed.text()).toContain('Dashboard')
    expect(allowed.text()).toContain('Options Calculator')
    expect(allowed.text()).toContain('Scanner')
    expect(allowed.text()).toContain('AI Export')
    expect(allowed.text()).toContain('EOD Health')
    expect(allowed.text()).toContain('Team Settings')
    expect(allowed.text()).toContain('Profile')
    expect(allowed.text()).toContain('Log Out')
    const brand = allowed.get('a[aria-label="GEX Options dashboard"]')
    expect(brand.get('img').classes()).toContain('dashboard-topbar__mark')
    allowed.unmount()

    const blocked = mountLayout(9)
    expect(blocked.text()).not.toContain('EOD Health')
    expect(blocked.findAll('main')).toHaveLength(1)
    expect(blocked.get('#application-page-content').attributes('tabindex')).toBe('-1')
    blocked.unmount()
  })

  it('opens the mobile menu with focus and restores focus when Escape closes it', async () => {
    const wrapper = mountLayout()
    const toggle = wrapper.get('[aria-controls="mobile-primary-navigation"]')
    const menu = wrapper.get('#mobile-primary-navigation')

    expect(toggle.attributes('aria-expanded')).toBe('false')
    await toggle.trigger('click')
    expect(toggle.attributes('aria-expanded')).toBe('true')
    expect(document.activeElement).toBe(menu.find('a').element)

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(toggle.element)
    wrapper.unmount()
  })

  it('keeps the calculator symbol handoff without an extra watchlist request when a symbol is saved', async () => {
    localStorage.setItem('calculator_last_symbol', 'nvda')
    const dispatch = vi.spyOn(window, 'dispatchEvent')
    const wrapper = mountLayout()
    const calculatorLink = wrapper.findAll('a').find((link) => link.text() === 'Options Calculator')

    await calculatorLink.trigger('click')
    await flushPromises()

    expect(axios.get).not.toHaveBeenCalled()
    const event = dispatch.mock.calls.find(([item]) => item.type === 'select-symbol')?.[0]
    expect(event?.detail).toEqual({ symbol: 'NVDA' })
    wrapper.unmount()
  })
})
