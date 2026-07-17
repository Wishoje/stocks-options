import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const axiosMock = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
}))

vi.mock('axios', () => ({ default: axiosMock }))
vi.mock('chart.js/auto', () => ({
    default: vi.fn(() => ({ destroy: vi.fn() })),
}))
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: {
        template: '<main><slot name="header" /><slot /></main>',
    },
}))
vi.mock('@/Components/AppShell.vue', () => ({
    default: {
        template: '<section><slot /></section>',
    },
}))

import Calculator from '@/Pages/Options/Calculator.vue'

const completeResponse = {
    status: 'ok',
    snapshot_at: '2026-07-16T14:35:00Z',
    underlying: { price: 595.25 },
    expirations: [{ value: '2026-07-17', label: 'Jul 17' }],
    chain: [
        {
            expiry: '2026-07-17',
            strike: 595,
            type: 'call',
            bid: 4.8,
            ask: 5.2,
        },
    ],
}

describe('calculator API response states', () => {
    let wrapper

    beforeEach(() => {
        window.localStorage.clear()
        axiosMock.get.mockReset()
        axiosMock.post.mockReset()
        vi.spyOn(console, 'log').mockImplementation(() => {})
        vi.spyOn(console, 'warn').mockImplementation(() => {})
    })

    afterEach(() => {
        wrapper?.unmount()
        wrapper = undefined
    })

    it('shows a preparation state while the initial prime request is pending', () => {
        axiosMock.post.mockReturnValue(new Promise(() => {}))

        wrapper = mount(Calculator)

        expect(wrapper.text()).toContain('Preparing SPY calculator')
        expect(axiosMock.get).not.toHaveBeenCalled()
    })

    it('shows a stable error state when the chain request fails', async () => {
        axiosMock.post.mockResolvedValue({ data: { queued: true } })
        axiosMock.get.mockRejectedValue(new Error('network unavailable'))
        vi.spyOn(console, 'error').mockImplementation(() => {})

        wrapper = mount(Calculator)
        await flushPromises()

        expect(wrapper.text()).toContain('Failed to load chain')
        expect(wrapper.text()).not.toContain('Preparing SPY calculator')
    })

    it('does not publish a partial response and retries until the chain is complete', async () => {
        vi.useFakeTimers()
        axiosMock.post.mockResolvedValue({ data: { queued: true } })
        axiosMock.get
            .mockResolvedValueOnce({
                data: {
                    ...completeResponse,
                    status: 'partial',
                    chain: [],
                },
            })
            .mockResolvedValueOnce({ data: completeResponse })

        wrapper = mount(Calculator)
        await flushPromises()

        expect(wrapper.text()).toContain('Preparing SPY calculator')
        expect(wrapper.text()).not.toContain('Live Chain')
        expect(axiosMock.get).toHaveBeenCalledTimes(1)

        await vi.advanceTimersByTimeAsync(1200)
        await flushPromises()

        expect(axiosMock.get).toHaveBeenCalledTimes(2)
        expect(wrapper.text()).toContain('Live Chain')
        expect(wrapper.text()).not.toContain('Preparing SPY calculator')
    })

    it('publishes the calculator only after a complete chain response arrives', async () => {
        axiosMock.post.mockResolvedValue({ data: { queued: true } })
        axiosMock.get.mockResolvedValue({ data: completeResponse })

        wrapper = mount(Calculator)
        await flushPromises()

        expect(wrapper.text()).toContain('Live Chain')
        expect(wrapper.text()).toContain('Jul 17')
        expect(wrapper.text()).toContain('SPY @ $595.25')
        expect(wrapper.text()).not.toContain('Preparing SPY calculator')
        expect(wrapper.text()).not.toContain('Failed to load chain')
    })
})
