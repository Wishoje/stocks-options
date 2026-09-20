import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@inertiajs/vue3', async importOriginal => ({
  ...await importOriginal(),
  usePage: () => ({ props: { errors: {}, flash: {} } }),
}))
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))
import SocialPosts from '@/Pages/Admin/SocialPosts.vue'

const post = {
  id: 1, symbol: 'SPY', session_date: '2026-09-21', slot: 'primary', status: 'draft',
  body: '$SPY GEX levels #GEX', alt_text: 'Chart description', image_path: 'social/1/card.png', image_sha256: 'hash',
  snapshot: { data_date: '2026-09-18', expiration_dates: ['2026-09-21'] }, updated_at: '2026-09-20T10:00:00Z',
}
const render = (overrides = {}) => mount(SocialPosts, { props: {
  posts: [post], settings: { second_symbol: 'QQQ', paused: false }, defaultDate: '2026-09-21',
  connectionConfigured: false, publishingEnabled: false, scheduleEnabled: false, ...overrides,
} })

describe('social publishing review', () => {
  it('offers PNG/source downloads while keeping publish unavailable in draft mode', () => {
    const wrapper = render()
    expect(wrapper.get('a[href*="download=1"]').text()).toBe('Download PNG')
    expect(wrapper.get('a[href$="/snapshot"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Draft mode · posting off')
    expect(wrapper.text()).not.toContain('Publish in time slot')
    wrapper.unmount()
  })
  it('requires edits to be saved before approval', async () => {
    const wrapper = render()
    const approve = wrapper.findAll('button').find(button => button.text() === 'Approve draft')
    expect(approve.attributes('disabled')).toBeUndefined()
    await wrapper.get('#post-body').setValue('Changed chart summary')
    expect(approve.attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })
  it('blocks approval for incomplete source images and labels historical review', () => {
    const wrapper = render({ posts: [{ ...post, status: 'blocked', issue: 'Missing source inputs.' }], localReview: true })
    const approve = wrapper.findAll('button').find(button => button.text() === 'Approve draft')
    expect(approve.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Historical drafts cannot be published')
    expect(wrapper.get('#post-body').attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })
})
