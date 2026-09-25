import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import UiLoading from '@/Components/UI/UiLoading.vue'
import UiStatus from '@/Components/UI/UiStatus.vue'

describe('Loading feedback', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())
  it('offers recovery for long requests without claiming preparation or showing fake numbers', async () => {
    const wrapper = mount(UiLoading, { props: { title: 'Loading QQQ', message: 'Fetching the selected data set.', retry: true } })
    expect(wrapper.attributes('aria-busy')).toBe('true')
    expect(wrapper.find('[aria-hidden="true"] .gex-loading__metrics').exists()).toBe(true)
    expect(wrapper.find('button').exists()).toBe(false)
    await vi.advanceTimersByTimeAsync(12000)
    expect(wrapper.text()).toContain('taking longer than usual')
    expect(wrapper.text()).not.toContain('being prepared')
    await wrapper.get('button').trigger('click')
    expect(wrapper.emitted('retry')).toHaveLength(1)
    await vi.advanceTimersByTimeAsync(48000)
    expect(wrapper.text()).toContain('Still waiting for data')
    expect(wrapper.classes()).toContain('is-prolonged')
    await wrapper.setProps({ title: 'Loading TSLA' })
    expect(wrapper.text()).not.toContain('Still waiting')
    wrapper.unmount()
    expect(vi.getTimerCount()).toBe(0)
  })
  it('only renders placeholders for loading/preparing, never errors or empty results', async () => {
    const wrapper = mount(UiStatus, { props: { state: 'preparing', title: 'Preparing SPY', layout: 'table' } })
    expect(wrapper.find('.gex-loading__rows').exists()).toBe(true)
    await wrapper.setProps({ state: 'error', title: 'Request failed', retry: true })
    expect(wrapper.find('.gex-loading').exists()).toBe(false)
    expect(wrapper.attributes('role')).toBe('alert')
    await wrapper.setProps({ state: 'sparse', title: 'No readings returned' })
    expect(wrapper.find('.gex-loading').exists()).toBe(false)
    wrapper.unmount()
    expect(vi.getTimerCount()).toBe(0)
  })
})
