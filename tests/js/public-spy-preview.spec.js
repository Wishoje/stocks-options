import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import content from '../../resources/marketing/spy-preview.json'
const state = vi.hoisted(() => ({ page: { url: '/spy-gex-levels', props: { auth: { user: null }, billing: {}, offer: { trial_days: 7 } } } }))
vi.mock('@inertiajs/vue3', () => ({
  usePage: () => state.page,
  Head: { template: '<div><slot /></div>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}))
import SpyPreview from '@/Pages/Marketing/SpyPreview.vue'
import { sanitizeEventParams } from '@/lib/ga'
const seo = { title: 'SPY GEX Levels - Free Gamma Exposure Chart | GEX Options', description: 'Dated EOD SPY preview', canonical: 'https://gexoptions.com/spy-gex-levels' }
const preview = {
  symbol: 'SPY', timeframe: '14d', data_date: '2026-10-23', is_latest_session: true,
  expiration_dates: ['2026-10-23','2026-10-26','2026-10-30'], total_label: '-70M', strike_count: 3,
  put_wall: { strike_label: '595', exposure_label: '-100M' }, call_wall: { strike_label: '605', exposure_label: '+50M' },
  chart: { zero: 208, y_ticks: [{ y: 42, label: '+110M' },{ y: 208,label:'0' },{ y:374,label:'-110M' }], x_ticks: [{ x:180,label:'595' },{ x:595,label:'600' },{ x:1010,label:'605' }],
    bars: [{ strike:595,x:180,y:208,width:12,height:150,positive:false,title:'Strike 595: -100M USD per 1% move' },{ strike:600,x:595,y:208,width:12,height:30,positive:false,title:'Strike 600: -20M USD per 1% move' },{ strike:605,x:1010,y:133,width:12,height:75,positive:true,title:'Strike 605: +50M USD per 1% move' }] },
}
const options = value => ({ props: { preview: value, content, seo }, global: { stubs: { MarketingLayout: { template: '<main><slot /></main>' } } } })

describe('free SPY EOD preview', () => {
  beforeEach(() => { state.page.props.offer.trial_days = 7 })
  it('shows the actual EOD date, walls and exposure scale with one accessible chart', () => {
    const wrapper = mount(SpyPreview, options(preview))
    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe('SPY GEX levels')
    expect(wrapper.get('time').attributes('datetime')).toBe('2026-10-23')
    expect(wrapper.get('.mk-spy-metrics').text()).toContain('595')
    expect(wrapper.get('.mk-spy-metrics').text()).toContain('605')
    expect(wrapper.get('.mk-spy-metrics').text()).toContain('-70M')
    expect(wrapper.text()).toContain('USD per 1% underlying move')
    expect(wrapper.get('svg[role="img"] title').text()).toContain('2026-10-23')
    expect(wrapper.findAll('svg rect')).toHaveLength(3)
    expect(wrapper.findAll('svg rect.mk-spy-chart__positive')).toHaveLength(1)
    expect(wrapper.findAll('svg rect.mk-spy-chart__negative')).toHaveLength(2)
    expect(wrapper.get('link[rel="canonical"]').attributes('href')).toBe(seo.canonical)
    wrapper.unmount()
  })
  it('does not invent levels when a reading is being prepared', () => {
    const wrapper = mount(SpyPreview, options(null))
    expect(wrapper.text()).toContain('SPY EOD levels are being prepared.')
    expect(wrapper.find('svg').exists()).toBe(false)
    expect(wrapper.find('.mk-spy-metrics').exists()).toBe(false)
    expect(wrapper.text()).toContain('What should you do with these levels?')
    wrapper.unmount()
  })
  it('labels historical readings and keeps the full workspace trial connected to the existing checkout', () => {
    state.page.props.offer.trial_days = 14
    const wrapper = mount(SpyPreview, options({ ...preview,is_latest_session:false }))
    expect(wrapper.text()).toContain('Recorded EOD')
    expect(wrapper.text()).not.toContain('Latest completed EOD')
    expect(wrapper.text()).toContain('It does not show today’s market price.')
    expect(wrapper.get('a[data-marketing-destination="register"]').attributes('href')).toBe('/register?plan=earlybird&billing=monthly')
    expect(wrapper.text()).toContain('Start 14-day free trial')
    expect(wrapper.find('a[href="/guides/how-to-read-spy-gex-levels"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/features#intraday-wall-tracking"]').exists()).toBe(true)
    expect(wrapper.findAll('details.mk-faq')).toHaveLength(content.faqs.length)
    const schema = JSON.parse(wrapper.get('script[head-key="faq-json-ld"]').text())
    expect(schema.mainEntity.map(item=>item.name)).toEqual(content.faqs.map(item=>item.q))
    wrapper.unmount()
  })
  it('uses fixed attribution values without broadening analytics collection', () => {
    expect(sanitizeEventParams({ source:'spy_preview',location:'spy_preview_final',email:'private@example.com' })).toEqual({source:'spy_preview',location:'spy_preview_final'})
  })
})
