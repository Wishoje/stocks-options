import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import guides from '../../resources/marketing/trading-guides.json'

vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ url: '/guides', props: { seo: { title: 'Guide title', description: 'Guide description', canonical: 'https://gexoptions.com/guides', type: 'article' }, offer: { trial_days: 7 } } }),
  Head: { template: '<div><slot /></div>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}))
import Guide from '@/Pages/Marketing/Guide.vue'
import Guides from '@/Pages/Marketing/Guides.vue'
import { productMedia } from '@/Support/marketing-content'
import { sanitizeEventParams } from '@/lib/ga'
const stubs = { MarketingLayout: { template: '<main><slot /></main>' }, ProductMedia: true, SwingIdeasPreview: true, MarketingCta: true }

describe('public trading guide navigation', () => {
  it('links the collection to each published article', () => {
    const wrapper = mount(Guides, { props: { guides }, global: { stubs } })
    expect(wrapper.findAll('h1')).toHaveLength(1)
    for (const guide of guides) expect(wrapper.find(`a[href="/guides/${guide.slug}"]`).exists()).toBe(true)
  })
  it.each(guides)('renders every section, evidence table, checklist and related guide for $slug', guide => {
    const wrapper = mount(Guide, { props: { guide }, global: { stubs } })
    expect(wrapper.get('h1').text()).toBe(guide.title)
    expect(wrapper.get('time').attributes('datetime')).toBe(guide.published)
    expect(wrapper.get('meta[property="og:type"]').attributes('content')).toBe('article')
    for (const section of guide.sections) {
      expect(wrapper.get(`nav[aria-label="In this guide"] a[href="#${section.id}"]`).text()).toBe(section.title)
      expect(wrapper.get(`#${section.id} h2`).text()).toBe(section.title)
    }
    if (guide.visual === 'swing-ideas') expect(wrapper.findComponent({ name: 'SwingIdeasPreview' }).exists()).toBe(true)
    else expect(wrapper.getComponent({ name: 'ProductMedia' }).props('media')).toBe(productMedia[guide.mediaKey])
    const other = guides.find(item => item.slug !== guide.slug)
    expect(wrapper.find(`a[href="/guides/${other.slug}"]`).exists()).toBe(true)
    for (const item of guide.checklist) expect(wrapper.get('.mk-guide-checklist').text()).toContain(item)
    expect(wrapper.findAll('table')).toHaveLength(guide.sections.filter(section => section.rows).length)
  })
  it('keeps trial attribution useful without admitting arbitrary analytics values', () => {
    expect(sanitizeEventParams({ source: 'guides', location: 'guide_final', email: 'private@example.com' })).toEqual({ source: 'guides', location: 'guide_final' })
    expect(sanitizeEventParams({ source: 'untrusted', location: 'untrusted' })).toEqual({})
  })
})
