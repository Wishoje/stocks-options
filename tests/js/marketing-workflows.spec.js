import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import workflows from '../../resources/marketing/public-workflows.json'
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: { seo: { title: 'Workflow', description: 'Description', canonical: 'https://gexoptions.com/intraday-alerts' } } }),
  Head: { template: '<div><slot /></div>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}))
import Workflow from '@/Pages/Marketing/Workflow.vue'
describe('public workflow explanations', () => {
  it.each(Object.values(workflows))('shows the explanation, examples and guide links for $heading', content => {
    const wrapper = mount(Workflow, { props: { content }, global: { stubs: { MarketingLayout: { template: '<main><slot /></main>' }, MarketingCta: true, ProductMedia: true, SwingIdeasPreview: true } } })
    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe(content.heading)
    for (const section of content.sections) {
      expect(wrapper.get(`nav a[href="#${section.id}"]`).text()).toBe(section.title)
      for (const paragraph of section.paragraphs) expect(wrapper.get(`#${section.id}`).text()).toContain(paragraph)
    }
    for (const slug of content.guides) expect(wrapper.find(`a[href="/guides/${slug}"]`).exists()).toBe(true)
    expect(wrapper.find('a[href="/pricing"]').exists()).toBe(true)
    if (content.visual) expect(wrapper.findComponent({ name: 'SwingIdeasPreview' }).exists()).toBe(true)
    if (content.mediaKey) expect(wrapper.findComponent({ name: 'ProductMedia' }).exists()).toBe(true)
    wrapper.unmount()
  })
})
