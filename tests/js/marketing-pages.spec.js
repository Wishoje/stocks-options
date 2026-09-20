import { mount, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const page = vi.hoisted(() => ({
  url: '/',
  props: {
    auth: { user: null },
    billing: {},
    seo: {
      image: 'https://gexoptions.com/marketing/current/social-preview.webp',
      image_alt: 'GEX Options dashboard preview.',
      image_width: 1200,
      image_height: 630,
    },
    offer: {
      plan: 'earlybird',
      label: 'Early Bird',
      trial_days: 7,
      display: {
        currency: 'USD',
        monthly: { amount_minor: 2999, interval: 'month' },
        yearly: { amount_minor: 29900, interval: 'year' },
      },
      features: ['EOD dashboard views'],
    },
  },
}))

vi.mock('@inertiajs/vue3', () => ({
  usePage: () => page,
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  Head: { template: '<div><slot /></div>' },
  router: { post: vi.fn() },
}))

import Features from '@/Pages/Marketing/Features.vue'
import Home from '@/Pages/Marketing/Home.vue'
import { buildHomeFaqs, featureGroups } from '@/Support/marketing-content'

describe('public marketing pages', () => {
  beforeEach(() => {
    page.props = {
      auth: { user: null },
      billing: {},
      seo: {
        image: 'https://gexoptions.com/marketing/current/social-preview.webp',
        image_alt: 'GEX Options dashboard preview.',
        image_width: 1200,
        image_height: 630,
      },
      offer: {
        plan: 'earlybird',
        label: 'Early Bird',
        trial_days: 7,
        display: {
          currency: 'USD',
          monthly: { amount_minor: 2999, interval: 'month' },
          yearly: { amount_minor: 29900, interval: 'year' },
        },
        features: ['EOD dashboard views'],
      },
    }
  })

  const publicPageStubs = {
    MarketingLayout: { template: '<main><slot /></main>' },
    MarketingCta: true,
    ProductMedia: true,
    ProductPreview: true,
  }

  it('renders Home from shared offer and FAQ content without hard-coded price amounts', () => {
    const wrapper = shallowMount(Home, { global: { renderStubDefaultSlot: true } })
    expect(wrapper.text()).toContain('Plan your session with GEX levels and dealer positioning.')
    expect(wrapper.text()).toContain('Why subscribe')
    expect(wrapper.text()).toContain('Put the full workspace to work during your trial.')
    expect(wrapper.text()).toContain('current 7-day trial')
    expect(wrapper.findAll('details')).toHaveLength(buildHomeFaqs(7).length)
    expect(wrapper.text()).toContain('$29.99/month')
    expect(wrapper.text()).toContain('$299/year')
    wrapper.unmount()
    page.props.offer.display.monthly.amount_minor = 3599
    page.props.offer.display.yearly.amount_minor = 35000
    const changedOffer = shallowMount(Home, { global: { renderStubDefaultSlot: true } })
    expect(changedOffer.text()).toContain('$35.99/month')
    expect(changedOffer.text()).toContain('$350/year')
    expect(changedOffer.text()).not.toContain('$29.99')
  })

  it('uses the configured trial length in both the visible FAQ and its structured data', () => {
    page.props.offer.trial_days = 14
    const wrapper = mount(Home, {
      global: {
        stubs: {
          MarketingLayout: { template: '<main><slot /></main>' },
          MarketingCta: true,
          ProductMedia: true,
          ProductPreview: true,
        },
      },
    })

    const trialAnswer = wrapper.findAll('details').find(item => item.text().includes('When does the trial begin?'))
    expect(trialAnswer.text()).toContain('current 14-day trial')

    const jsonLd = JSON.parse(wrapper.get('script[type="application/ld+json"]').text())
    const structuredAnswer = jsonLd.mainEntity.find(item => item.name === 'When does the trial begin?')
    expect(structuredAnswer.acceptedAnswer.text).toContain('current 14-day trial')
  })

  it('preserves the indexed Home metadata, one crawlable h1, and WebSite structured data', () => {
    const wrapper = mount(Home, { global: { stubs: publicPageStubs } })

    const expectedTitle = 'GexOptions - Premarket Levels, Positioning, and Options Flow'
    const expectedDescription = 'Map dated GEX levels and dealer positioning, inspect put-versus-call pricing and stored intraday flow, and scan optionable stocks with source scope visible.'
    expect(wrapper.get('title').text()).toBe(expectedTitle)
    expect(wrapper.get('link[rel="canonical"]').attributes('href')).toBe('https://gexoptions.com/')
    expect(wrapper.get('meta[name="description"]').attributes('content')).toBe(expectedDescription)
    expect(wrapper.get('meta[property="og:title"]').attributes('content')).toBe(expectedTitle)
    expect(wrapper.get('meta[property="og:description"]').attributes('content')).toBe(expectedDescription)
    expect(wrapper.get('meta[property="og:url"]').attributes('content')).toBe('https://gexoptions.com/')
    expect(wrapper.get('meta[property="og:image"]').attributes('content')).toBe(page.props.seo.image)
    expect(wrapper.get('meta[property="og:image:width"]').attributes('content')).toBe('1200')
    expect(wrapper.get('meta[property="og:image:height"]').attributes('content')).toBe('630')
    expect(wrapper.get('meta[property="og:image:alt"]').attributes('content')).toBe(page.props.seo.image_alt)
    expect(wrapper.get('meta[name="twitter:card"]').attributes('content')).toBe('summary_large_image')
    expect(wrapper.get('meta[name="twitter:image"]').attributes('content')).toBe(page.props.seo.image)
    expect(wrapper.get('meta[name="twitter:image:alt"]').attributes('content')).toBe(page.props.seo.image_alt)
    expect(wrapper.get('meta[name="twitter:title"]').attributes('content')).toBe(expectedTitle)
    expect(wrapper.get('meta[name="twitter:description"]').attributes('content')).toBe(expectedDescription)
    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe('Plan your session with GEX levels and dealer positioning.')

    const structuredData = wrapper.findAll('script[type="application/ld+json"]')
      .map(script => JSON.parse(script.text()))
      .find(value => value['@type'] === 'WebSite')
    expect(structuredData).toMatchObject({
      name: 'GEX Options',
      url: 'https://gexoptions.com/',
      inLanguage: 'en-US',
    })
    expect(structuredData).not.toHaveProperty('offers')
    expect(structuredData).not.toHaveProperty('aggregateRating')
    expect(structuredData).not.toHaveProperty('review')

    const faq = wrapper.findAll('script[type="application/ld+json"]')
      .map(script => JSON.parse(script.text()))
      .find(value => value['@type'] === 'FAQPage')
    expect(faq.mainEntity.map(item => item.name)).toEqual(
      wrapper.findAll('details').map(item => item.get('summary').text()),
    )
  })

  it('renders every shared Features section and feature row', () => {
    const wrapper = shallowMount(Features, { global: { renderStubDefaultSlot: true } })
    expect(wrapper.text()).toContain('Explore GEX, DEX, options flow, scanners, and volatility.')
    expect(wrapper.findAllComponents({ name: 'FeatureRow' })).toHaveLength(
      featureGroups.reduce((count, group) => count + group.features.length, 0),
    )
    expect(wrapper.findAll('.mk-feature-group')).toHaveLength(featureGroups.length)
  })

  it('preserves the indexed Features metadata, one h1, and FAQ parity', () => {
    const wrapper = mount(Features, { global: { stubs: publicPageStubs } })

    const expectedTitle = 'GexOptions Features - Flow, GEX Levels, DEX, Scanners, VRP & Term Structure'
    const expectedDescription = 'Explore GEX Options EOD and intraday analytics, watchlist, scanners, options calculator, and structured export with timing and coverage explained.'
    expect(wrapper.get('title').text()).toBe(expectedTitle)
    expect(wrapper.get('link[rel="canonical"]').attributes('href')).toBe('https://gexoptions.com/features')
    expect(wrapper.get('meta[name="description"]').attributes('content')).toBe(expectedDescription)
    expect(wrapper.get('meta[property="og:title"]').attributes('content')).toBe(expectedTitle)
    expect(wrapper.get('meta[property="og:description"]').attributes('content')).toBe(expectedDescription)
    expect(wrapper.get('meta[property="og:url"]').attributes('content')).toBe('https://gexoptions.com/features')
    expect(wrapper.get('meta[property="og:image"]').attributes('content')).toBe(page.props.seo.image)
    expect(wrapper.get('meta[property="og:image:width"]').attributes('content')).toBe('1200')
    expect(wrapper.get('meta[property="og:image:height"]').attributes('content')).toBe('630')
    expect(wrapper.get('meta[property="og:image:alt"]').attributes('content')).toBe(page.props.seo.image_alt)
    expect(wrapper.get('meta[name="twitter:card"]').attributes('content')).toBe('summary_large_image')
    expect(wrapper.get('meta[name="twitter:image"]').attributes('content')).toBe(page.props.seo.image)
    expect(wrapper.get('meta[name="twitter:image:alt"]').attributes('content')).toBe(page.props.seo.image_alt)
    expect(wrapper.get('meta[name="twitter:title"]').attributes('content')).toBe(expectedTitle)
    expect(wrapper.get('meta[name="twitter:description"]').attributes('content')).toBe(expectedDescription)
    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe('Explore GEX, DEX, options flow, scanners, and volatility.')

    const faq = wrapper.findAll('script[type="application/ld+json"]')
      .map(script => JSON.parse(script.text()))
      .find(value => value['@type'] === 'FAQPage')
    const visibleQuestions = wrapper.findAll('details').map(item => item.get('summary').text())
    expect(faq.mainEntity.map(item => item.name)).toEqual(visibleQuestions)
  })
})
