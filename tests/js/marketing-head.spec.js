import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

const page = vi.hoisted(() => ({
  url: '/',
  props: {
    auth: { user: null },
    billing: {},
    offer: { plan: 'earlybird', label: 'Early Bird', trial_days: 7 },
  },
}))

vi.mock('@inertiajs/vue3', async () => {
  const actual = await vi.importActual('@inertiajs/vue3')
  return {
    ...actual,
    usePage: () => page,
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: vi.fn() },
  }
})

import Home from '@/Pages/Marketing/Home.vue'
import MarketingSeo from '@/Components/Marketing/MarketingSeo.vue'

function jsonFromScript(serialized) {
  const match = serialized.match(/^<script[^>]*>([\s\S]*)<\/script>$/)
  expect(match).not.toBeNull()
  return JSON.parse(match[1])
}

function captureHead() {
  const updates = []
  return {
    updates,
    manager: {
      createProvider: () => ({
        update: elements => updates.push(elements),
        disconnect: vi.fn(),
      }),
    },
  }
}

describe('marketing head serialization', () => {
  it('assigns stable unique Inertia keys to every SEO metadata entry', () => {
    const head = captureHead()

    const wrapper = mount(MarketingSeo, {
      props: {
        title: 'GexOptions metadata test',
        description: 'Metadata key test',
        canonical: 'https://gexoptions.com/metadata-test',
        image: 'https://gexoptions.com/marketing/current/social-preview.webp',
        imageAlt: 'GEX Options dashboard preview.',
        imageWidth: 1200,
        imageHeight: 630,
      },
      global: { mocks: { $headManager: head.manager } },
    })

    const serialized = head.updates.flat()
    const expectedKeys = [
      'seo-description',
      'seo-canonical',
      'seo-og-type',
      'seo-og-site-name',
      'seo-og-title',
      'seo-og-description',
      'seo-og-url',
      'seo-og-image',
      'seo-og-image-width',
      'seo-og-image-height',
      'seo-og-image-alt',
      'seo-twitter-card',
      'seo-twitter-title',
      'seo-twitter-description',
      'seo-twitter-image',
      'seo-twitter-image-alt',
    ]

    for (const key of expectedKeys) {
      expect(serialized.some(element => element.includes(`inertia="${key}"`))).toBe(true)
    }

    wrapper.unmount()
  })

  it('serializes FAQ and WebSite JSON-LD as script bodies through the real Inertia Head', () => {
    const head = captureHead()

    const wrapper = mount(Home, {
      global: {
        mocks: { $headManager: head.manager },
        stubs: {
          MarketingLayout: { template: '<main><slot /></main>' },
          MarketingCta: true,
          ProductMedia: true,
          ProductPreview: true,
        },
      },
    })

    const serialized = head.updates.flat()
    const faqScript = serialized.find(element => element.includes('inertia="faq-json-ld"'))
    const websiteScript = serialized.find(element => element.includes('inertia="website-json-ld"'))
    expect(faqScript).toBeDefined()
    expect(websiteScript).toBeDefined()
    expect(faqScript).not.toContain('textContent=')
    expect(websiteScript).not.toContain('textContent=')

    const faq = jsonFromScript(faqScript)
    const website = jsonFromScript(websiteScript)
    expect(faq['@type']).toBe('FAQPage')
    expect(faq.mainEntity).not.toHaveLength(0)
    expect(website).toMatchObject({
      '@type': 'WebSite',
      name: 'GEX Options',
      url: 'https://gexoptions.com/',
    })

    wrapper.unmount()
  })

  it('escapes tag delimiters while preserving the FAQ values in serialized JSON-LD', () => {
    const head = captureHead()
    const faqs = [{ q: 'Can text contain <tags>?', a: 'Yes, including </script> text.' }]
    const wrapper = mount(MarketingSeo, {
      props: {
        title: 'GexOptions test',
        description: 'Serialization test',
        canonical: 'https://gexoptions.com/test',
        faqs,
      },
      global: { mocks: { $headManager: head.manager } },
    })

    const faqScript = head.updates.flat().find(element => element.includes('inertia="faq-json-ld"'))
    expect(faqScript).toContain('\\u003c')
    expect(faqScript).not.toContain('</script> text')
    const faq = jsonFromScript(faqScript)
    expect(faq.mainEntity[0].name).toBe(faqs[0].q)
    expect(faq.mainEntity[0].acceptedAnswer.text).toBe(faqs[0].a)

    wrapper.unmount()
  })
})
