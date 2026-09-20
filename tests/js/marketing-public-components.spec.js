import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const state = vi.hoisted(() => ({
  page: {
    url: '/features?plan=earlybird&billing=yearly',
    props: { auth: { user: null }, billing: {}, offer: { trial_days: 7 } },
  },
}))

vi.mock('@inertiajs/vue3', () => ({
  usePage: () => state.page,
  Head: { template: '<div data-head><slot /></div>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { post: vi.fn() },
}))

import MarketingCta from '@/Components/Marketing/MarketingCta.vue'
import FeatureRow from '@/Components/Marketing/FeatureRow.vue'
import LegalDocument from '@/Components/Marketing/LegalDocument.vue'
import MarketingSeo from '@/Components/Marketing/MarketingSeo.vue'
import ProductMedia from '@/Components/Marketing/ProductMedia.vue'
import ProductPreview from '@/Components/Marketing/ProductPreview.vue'

const pendingMedia = {
  id: 'pending-product',
  title: 'Positioning',
  status: 'pending_capture',
  context: 'Recorded example',
  description: 'Visible scope and date.',
  caption: 'Verified capture pending.',
  desktop: null,
  mobile: null,
  alt: '',
}

const readyMedia = {
  ...pendingMedia,
  id: 'ready-product',
  status: 'ready',
  caption: 'SPY EOD example with its snapshot date.',
  alt: 'Positioning dashboard for a recorded SPY EOD example.',
  desktop: { src: '/marketing/current/positioning.webp', width: 1600, height: 900 },
  mobile: { src: '/marketing/current/positioning-mobile.webp', width: 780, height: 1200 },
}

describe('public marketing components', () => {
  beforeEach(() => {
    state.page.url = '/features?plan=earlybird&billing=yearly'
    state.page.props = { auth: { user: null }, billing: {}, offer: { trial_days: 7 } }
    window.gtag = vi.fn()
  })

  it('routes the primary CTA for guest, checkout, and active access states', async () => {
    const guest = mount(MarketingCta, { props: { location: 'spec' } })
    expect(guest.get('a').attributes('href')).toBe('/register?plan=earlybird&billing=yearly')
    expect(guest.text()).toContain('Start 7-day free trial')
    guest.unmount()

    state.page.props = { auth: { user: { id: 1 } }, billing: { needs_checkout: true }, offer: { trial_days: 7 } }
    const checkout = mount(MarketingCta, { props: { location: 'spec' } })
    expect(checkout.get('a').attributes('href')).toBe('/checkout?plan=earlybird&billing=yearly')
    expect(checkout.text()).toContain('Continue to checkout')
    checkout.unmount()

    state.page.props = { auth: { user: { id: 1 } }, billing: { needs_checkout: false }, offer: { trial_days: 7 } }
    const dashboard = mount(MarketingCta, { props: { location: 'spec' } })
    expect(dashboard.get('a').attributes('href')).toBe('/dashboard')
    expect(dashboard.text()).toContain('Open dashboard')
  })

  it('labels pending captures without rendering a legacy or synthetic image', () => {
    const wrapper = mount(ProductMedia, { props: { media: pendingMedia } })
    expect(wrapper.get('figure').attributes('data-capture-status')).toBe('pending_capture')
    expect(wrapper.text()).toContain('Verified capture pending')
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('button').exists()).toBe(false)
  })

  it('loads a verified image responsively and provides an accessible expand dialog', async () => {
    document.body.style.overflow = 'auto'
    const wrapper = mount(ProductMedia, { attachTo: document.body, props: { media: readyMedia } })
    expect(wrapper.get('img').attributes()).toMatchObject({ loading: 'lazy', width: '1600', height: '900' })
    expect(wrapper.get('source').attributes('srcset')).toBe(readyMedia.mobile.src)

    const trigger = wrapper.get('button')
    const dialogId = trigger.attributes('aria-controls')
    expect(trigger.attributes()).toMatchObject({
      'aria-haspopup': 'dialog',
      'aria-expanded': 'false',
    })
    trigger.element.focus()
    await trigger.trigger('click')
    expect(trigger.attributes('aria-expanded')).toBe('true')
    expect(document.getElementById(dialogId).getAttribute('aria-modal')).toBe('true')
    expect(document.getElementById(dialogId).getAttribute('aria-labelledby')).toBe(`${dialogId}-title`)
    expect(document.body.style.overflow).toBe('hidden')

    document.querySelector('[role="dialog"]').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(document.body.style.overflow).toBe('auto')
    expect(document.activeElement).toBe(trigger.element)
    wrapper.unmount()
  })

  it('uses user-controlled tabs with arrow, Home, and End navigation', async () => {
    const items = [
      { id: 'first', label: 'First', title: 'First view', summary: 'First summary', media: pendingMedia },
      { id: 'second', label: 'Second', title: 'Second view', summary: 'Second summary', media: { ...pendingMedia, id: 'second-media' } },
    ]
    const wrapper = mount(ProductPreview, { props: { id: 'spec-preview', items } })
    const tabs = wrapper.findAll('[role="tab"]')
    const panels = wrapper.findAll('[role="tabpanel"]')
    expect(panels).toHaveLength(items.length)
    expect(tabs.map(tab => tab.attributes('aria-controls'))).toEqual(panels.map(panel => panel.attributes('id')))
    expect(tabs[0].attributes('aria-selected')).toBe('true')
    expect(panels[0].attributes('hidden')).toBeUndefined()
    expect(panels[1].attributes('hidden')).toBeDefined()
    expect(wrapper.findAll('.mk-media')).toHaveLength(1)
    await tabs[0].trigger('keydown', { key: 'End' })
    expect(tabs[1].attributes('aria-selected')).toBe('true')
    expect(panels[0].attributes('hidden')).toBeDefined()
    expect(panels[1].attributes('hidden')).toBeUndefined()
    expect(wrapper.text()).toContain('Second view')
    await tabs[1].trigger('keydown', { key: 'Home' })
    expect(tabs[0].attributes('aria-selected')).toBe('true')
  })

  it('renders feature screenshot galleries without loading every screenshot at once', () => {
    const feature = {
      id: 'positioning',
      title: 'Positioning',
      description: 'Dealer positioning context.',
      bullets: ['Signed DEX by expiry'],
      media: readyMedia,
      previews: [
        { id: 'dex', label: 'Dealer DEX', title: 'DEX', summary: 'DEX summary', media: readyMedia },
        { id: 'pressure', label: 'Expiry pressure', title: 'Pressure', summary: 'Pressure summary', media: { ...readyMedia, id: 'pressure-media' } },
      ],
    }
    const wrapper = mount(FeatureRow, { props: { feature, eyebrow: 'After-close context' } })

    expect(wrapper.findComponent(ProductPreview).exists()).toBe(true)
    expect(wrapper.findAll('[role="tab"]')).toHaveLength(2)
    expect(wrapper.findAll('.mk-media')).toHaveLength(1)
  })

  it('uses unique dialog and heading ids when the same media appears more than once', async () => {
    const items = [
      { id: 'first', label: 'First', title: 'First view', summary: 'First summary', media: readyMedia },
      { id: 'second', label: 'Second', title: 'Second view', summary: 'Second summary', media: readyMedia },
    ]
    const wrapper = mount(ProductPreview, { props: { id: 'reused-media', items } })
    const dialogIds = []
    const headingIds = []

    for (let index = 0; index < items.length; index += 1) {
      await wrapper.findAll('[role="tab"]')[index].trigger('click')
      const trigger = wrapper.get('.mk-media__expand')
      await trigger.trigger('click')
      const dialogId = trigger.attributes('aria-controls')
      const dialog = document.getElementById(dialogId)
      const headingId = dialog.getAttribute('aria-labelledby')
      dialogIds.push(dialogId)
      headingIds.push(headingId)
      expect(document.getElementById(headingId).textContent).toBe(readyMedia.title)
      dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
      await flushPromises()
    }

    expect(new Set(dialogIds).size).toBe(2)
    expect(new Set(headingIds).size).toBe(2)
    expect(dialogIds.every(id => id.startsWith(`${readyMedia.id}-`) && id.endsWith('-dialog'))).toBe(true)
    wrapper.unmount()
  })

  it('renders one h1 when legal markdown already supplies the document heading', () => {
    const layoutStub = { template: '<main><slot /></main>' }
    const markdownHeading = mount(LegalDocument, {
      props: { title: 'Terms of Service', content: '<h1>Terms of Service</h1><p>Terms body.</p>' },
      global: { stubs: { MarketingLayout: layoutStub } },
    })
    expect(markdownHeading.findAll('h1')).toHaveLength(1)
    expect(markdownHeading.get('h1').text()).toBe('Terms of Service')

    const generatedHeading = mount(LegalDocument, {
      props: { title: 'Privacy Policy', content: '<h2>Information we collect</h2>' },
      global: { stubs: { MarketingLayout: layoutStub } },
    })
    expect(generatedHeading.findAll('h1')).toHaveLength(1)
    expect(generatedHeading.get('h1').text()).toBe('Privacy Policy')
  })

  it('generates FAQ structured data from the visible FAQ source and omits an unverified social image', () => {
    const faqs = [{ q: 'When was this recorded?', a: 'Use the source date shown in the view.' }]
    const wrapper = mount(MarketingSeo, {
      props: {
        title: 'Feature title',
        description: 'Feature description',
        canonical: 'https://gexoptions.com/features',
        faqs,
      },
    })
    const jsonLd = JSON.parse(wrapper.get('script[type="application/ld+json"]').text())
    expect(jsonLd.mainEntity[0].name).toBe(faqs[0].q)
    expect(jsonLd.mainEntity[0].acceptedAnswer.text).toBe(faqs[0].a)
    expect(wrapper.find('meta[property="og:image"]').exists()).toBe(false)
    expect(wrapper.get('meta[name="twitter:card"]').attributes('content')).toBe('summary')
  })

  it('publishes the complete social preview metadata when the reviewed image is supplied', () => {
    const wrapper = mount(MarketingSeo, {
      props: {
        title: 'Feature title',
        description: 'Feature description',
        canonical: 'https://gexoptions.com/features',
        image: 'https://gexoptions.com/marketing/current/social-preview.webp',
        imageAlt: 'GEX Options dashboard preview.',
        imageWidth: 1200,
        imageHeight: 630,
      },
    })

    expect(wrapper.get('meta[property="og:image"]').attributes('content')).toBe('https://gexoptions.com/marketing/current/social-preview.webp')
    expect(wrapper.get('meta[property="og:image:width"]').attributes('content')).toBe('1200')
    expect(wrapper.get('meta[property="og:image:height"]').attributes('content')).toBe('630')
    expect(wrapper.get('meta[property="og:image:alt"]').attributes('content')).toBe('GEX Options dashboard preview.')
    expect(wrapper.get('meta[name="twitter:card"]').attributes('content')).toBe('summary_large_image')
    expect(wrapper.get('meta[name="twitter:image"]').attributes('content')).toBe('https://gexoptions.com/marketing/current/social-preview.webp')
    expect(wrapper.get('meta[name="twitter:image:alt"]').attributes('content')).toBe('GEX Options dashboard preview.')
  })
})
