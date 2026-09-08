import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const axiosMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const chartMock = vi.hoisted(() => vi.fn(() => ({ destroy: vi.fn() })))
vi.mock('axios', () => ({ default: axiosMock }))
vi.mock('chart.js/auto', () => ({ default: chartMock }))
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<main><slot /></main>' } }))
vi.mock('@/Components/AppShell.vue', () => ({ default: { template: '<section><slot /></section>' } }))

import Calculator from '@/Pages/Options/Calculator.vue'

const response = (underlying = { price: 100, status: 'live', usable_for_calculation: true }) => ({
    status: 'ok',
    underlying: { symbol: 'SPY', source: 'provider', ...underlying },
    resolved_expiry: '2026-09-18',
    expirations: [{ value: '2026-09-18', label: 'Sep 18', dte: 7 }],
    chain: ['call', 'put'].map((type) => ({
        contract_symbol: `O:SPY260918${type === 'call' ? 'C' : 'P'}00100000`,
        type, expiry: '2026-09-18', strike: 100, bid: 4.8, ask: 5.2, iv: 0.2, dte: 7,
    })),
})

const unavailable = { price: null, status: 'unavailable', usable_for_calculation: false }

describe('calculator payoff trust and rendering', () => {
    let wrapper
    let frames
    let nextFrame
    let requestFrame
    let cancelFrame

    beforeEach(() => {
        window.localStorage.clear()
        axiosMock.get.mockReset()
        axiosMock.post.mockReset()
        chartMock.mockClear()
        frames = new Map()
        nextFrame = 0
        requestFrame = vi.fn((callback) => { frames.set(++nextFrame, callback); return nextFrame })
        cancelFrame = vi.fn((id) => frames.delete(id))
        vi.stubGlobal('requestAnimationFrame', requestFrame)
        vi.stubGlobal('cancelAnimationFrame', cancelFrame)
    })

    afterEach(() => {
        wrapper?.unmount()
        wrapper = undefined
        vi.unstubAllGlobals()
    })

    const mountResponse = async (data = response()) => {
        axiosMock.get.mockResolvedValue({ data })
        wrapper = mount(Calculator)
        await flushPromises()
        // With no accepted quote there is deliberately no automatic nearest
        // strike selection. The user selects a priced contract from the chain.
        if (!data.underlying.usable_for_calculation) {
            await wrapper.find(`[data-contract-symbol="${data.chain[0].contract_symbol}"]`).trigger('click')
        }
    }
    const renderFrame = async () => {
        const callbacks = [...frames.values()]
        frames.clear()
        callbacks.forEach((callback) => callback(0))
        await nextTick()
    }
    const payoffRows = () => wrapper.findAll('[data-testid="calculator-payoff-rows"] tr')
    const decayRows = () => wrapper.findAll('[data-testid="calculator-time-decay-rows"] tr')
    const entryInput = () => wrapper.find('input[step="0.01"]')
    const underlyingInput = () => wrapper.find('[data-testid="calculator-underlying-price"]')

    it('shows exact expiration payoff without fabricating an unavailable spot or enabling time decay', async () => {
        await mountResponse(response(unavailable))

        expect(wrapper.text()).toContain('SPY @ Quote unavailable')
        expect(wrapper.find('[data-testid="calculator-hypothetical-payoff"]').text()).toContain('selected strike ($100.00), not a current stock quote')
        expect(payoffRows()).toHaveLength(51)
        expect(payoffRows()[25].findAll('td').map((cell) => cell.text())).toEqual(['$100.00', '$-500', '-100.0%'])
        expect(decayRows()).toHaveLength(0)
        expect(frames.size).toBe(1)
        expect(chartMock).not.toHaveBeenCalled()

        await renderFrame()
        expect(chartMock).toHaveBeenCalledTimes(1)
        expect(chartMock.mock.calls[0][1].data.datasets[0].data).toHaveLength(51)
        expect(chartMock.mock.calls[0][1].data.datasets[0].data).toEqual(
            Array.from({ length: 51 }, (_, i) => (Math.max(60 + i * 80 / 50 - 100, 0) - 5) * 100),
        )
    })

    it('a manual OPTION premium enables payoff but does not pretend to be the underlying stock price', async () => {
        await mountResponse(response(unavailable))
        await renderFrame()
        await entryInput().setValue('3')
        await renderFrame()

        expect(wrapper.text()).toContain('Cost$300')
        expect(wrapper.text()).toContain('SPY @ Quote unavailable')
        expect(payoffRows()[25].findAll('td')[1].text()).toBe('$-300')
        expect(decayRows()).toHaveLength(0)
        expect(underlyingInput().element.value).toBe('')
        expect(chartMock).toHaveBeenCalledTimes(2)
    })

    it('uses an explicit manual UNDERLYING scenario for both tables, with a clear non-live label', async () => {
        await mountResponse(response(unavailable))
        await underlyingInput().setValue('105')
        await renderFrame()

        expect(wrapper.find('[data-testid="calculator-manual-underlying"]').text()).toContain('hypothetical input, not a live quote')
        expect(wrapper.text()).toContain('SPY @ $105.00(manual scenario)')
        expect(payoffRows()[25].findAll('td').map((cell) => cell.text())).toEqual(['$105.00', '$0', '0.0%'])
        expect(decayRows()).toHaveLength(8)
        expect(chartMock).toHaveBeenCalledTimes(2)
        expect(wrapper.find('[data-testid="calculator-charts-paused"]').exists()).toBe(false)

        await wrapper.find('[data-testid="calculator-use-quoted-underlying"]').trigger('click')
        await renderFrame()
        expect(underlyingInput().element.value).toBe('')
        expect(decayRows()).toHaveLength(0)
        expect(payoffRows()).toHaveLength(51)
        expect(wrapper.find('[data-testid="calculator-charts-paused"]').exists()).toBe(true)
    })

    it.each(['', '0', '-1', 'Infinity', '100oops'])('does not fall back to an automatic price after invalid manual underlying %s', async (value) => {
        await mountResponse()
        await renderFrame()
        const initialCharts = chartMock.mock.results.map((result) => result.value)
        await underlyingInput().setValue(value)
        await renderFrame()

        expect(wrapper.find('[data-testid="calculator-underlying-invalid"]').exists()).toBe(true)
        expect(wrapper.text()).toContain('SPY @ Invalid manual scenario')
        expect(decayRows()).toHaveLength(0)
        expect(payoffRows()).toHaveLength(51)
        expect(initialCharts[1].destroy).toHaveBeenCalledTimes(1)
        expect(wrapper.text()).not.toContain('$NaN')
        expect(wrapper.text()).not.toContain('$Infinity')
    })

    it.each(['', '0', '-1'])('rejects invalid manual option premium %s even with a valid underlying', async (value) => {
        await mountResponse()
        await renderFrame()
        const initialCharts = chartMock.mock.results.map((result) => result.value)
        await entryInput().setValue(value)
        await renderFrame()

        expect(payoffRows()).toHaveLength(0)
        expect(decayRows()).toHaveLength(0)
        expect(chartMock).toHaveBeenCalledTimes(2)
        initialCharts.forEach((chart) => expect(chart.destroy).toHaveBeenCalledTimes(1))
    })

    it('does not trust rejected stale automatic prices, while labeling explicitly accepted stale quotes', async () => {
        await mountResponse(response({ price: 110, status: 'stale', usable_for_calculation: false }))
        expect(underlyingInput().element.value).toBe('')
        expect(decayRows()).toHaveLength(0)
        expect(payoffRows()[25].findAll('td')[0].text()).toBe('$100.00')
        await underlyingInput().setValue('105')
        expect(decayRows()).toHaveLength(8)
        wrapper.unmount()

        await mountResponse(response({ price: 110, status: 'stale', usable_for_calculation: true }))
        expect(wrapper.text()).toContain('Using a stale quote from provider')
        expect(underlyingInput().element.value).toBe('110')
        expect(decayRows()).toHaveLength(8)
    })

    it('resets the manual underlying when switching symbols', async () => {
        await mountResponse()
        await underlyingInput().setValue('105')
        const next = response({ symbol: 'AAPL', price: 225, status: 'live', usable_for_calculation: true })
        next.chain = next.chain.map((contract) => ({ ...contract, contract_symbol: contract.contract_symbol.replace('SPY', 'AAPL'), strike: 220 }))
        axiosMock.get.mockResolvedValue({ data: next })
        window.dispatchEvent(new CustomEvent('select-symbol', { detail: { symbol: 'AAPL' } }))
        await flushPromises()

        expect(wrapper.text()).toContain('AAPL @ $225.00')
        expect(wrapper.find('[data-testid="calculator-manual-underlying"]').exists()).toBe(false)
        expect(underlyingInput().element.value).toBe('225')
    })

    it('keeps a manual stock scenario across expiry responses but resets the option entry premium', async () => {
        const initial = response()
        initial.expirations.push({ value: '2026-09-25', label: 'Sep 25', dte: 14 })
        await mountResponse(initial)
        await underlyingInput().setValue('105')
        await entryInput().setValue('3')
        const next = response({ price: 110, status: 'live', usable_for_calculation: true })
        next.expirations = initial.expirations
        next.resolved_expiry = '2026-09-25'
        next.chain = next.chain.map((contract) => ({
            ...contract, expiry: '2026-09-25', dte: 14,
            contract_symbol: contract.contract_symbol.replace('260918', '260925'),
        }))
        axiosMock.get.mockResolvedValue({ data: next })
        await wrapper.findAll('button').find((button) => button.text().includes('Sep 25')).trigger('click')
        await flushPromises()

        expect(underlyingInput().element.value).toBe('105')
        expect(entryInput().element.value).toBe('5')
        expect(wrapper.find('[data-testid="calculator-manual-underlying"]').exists()).toBe(true)
        expect(decayRows()).toHaveLength(15)
        await wrapper.find('[data-testid="calculator-use-quoted-underlying"]').trigger('click')
        expect(underlyingInput().element.value).toBe('110')
    })

    it.each([0, null])('keeps expiration payoff available when time-decay DTE is %s', async (dte) => {
        const data = response()
        data.expirations[0].dte = dte
        data.chain = data.chain.map((contract) => ({ ...contract, dte }))
        await mountResponse(data)
        await renderFrame()
        expect(payoffRows()).toHaveLength(51)
        expect(decayRows()).toHaveLength(0)
        expect(chartMock).toHaveBeenCalledTimes(1)
    })

    it('coalesces initial publication and multiple input changes into one render pair per frame', async () => {
        await mountResponse()
        expect(requestFrame).toHaveBeenCalledTimes(1)
        await renderFrame()
        expect(chartMock).toHaveBeenCalledTimes(2)
        const initialCharts = chartMock.mock.results.map((result) => result.value)

        await wrapper.find('input[min="1"]').setValue('2')
        await entryInput().setValue('3')
        await underlyingInput().setValue('105')
        expect(requestFrame).toHaveBeenCalledTimes(2)
        expect(chartMock).toHaveBeenCalledTimes(2)
        await renderFrame()

        expect(chartMock).toHaveBeenCalledTimes(4)
        initialCharts.forEach((chart) => expect(chart.destroy).toHaveBeenCalledTimes(1))
        expect(chartMock.mock.calls[2][1].data.datasets[0].data[25]).toBe(400)
        expect(chartMock.mock.calls[3][1].data.datasets[0].data).toHaveLength(8)
        await flushPromises()
        expect(frames.size).toBe(0)
        expect(chartMock).toHaveBeenCalledTimes(4)
    })

    it('renders put payoff using the current selection without a duplicate frame', async () => {
        await mountResponse(response(unavailable))
        await renderFrame()
        await wrapper.find('[data-contract-symbol="O:SPY260918P00100000"]').trigger('click')
        await renderFrame()

        expect(chartMock).toHaveBeenCalledTimes(2)
        expect(chartMock.mock.calls[1][1].data.datasets[0].data[0]).toBe(3500)
        expect(chartMock.mock.calls[1][1].data.datasets[0].data[50]).toBe(-500)
        expect(payoffRows()[25].findAll('td')[1].text()).toBe('$-500')
    })

    it('does not silently substitute spot for a missing or invalid selected target', async () => {
        await mountResponse()
        await wrapper.findAll('button').find((button) => button.text() === 'Flat @ Target').trigger('click')
        expect(decayRows()).toHaveLength(0)
        const target = wrapper.find('input[placeholder="Target"]')
        await target.setValue('105')
        expect(decayRows()).toHaveLength(8)
        await target.setValue('0')
        expect(decayRows()).toHaveLength(0)
        expect(payoffRows()).toHaveLength(51)
    })

    it('cancels pending frames and destroys each existing chart exactly once on unmount', async () => {
        await mountResponse()
        await renderFrame()
        const charts = chartMock.mock.results.map((result) => result.value)
        await entryInput().setValue('3')
        const lateFrame = [...frames.values()][0]
        wrapper.unmount()
        wrapper = undefined
        lateFrame(0)

        expect(cancelFrame).toHaveBeenCalledTimes(1)
        expect(frames.size).toBe(0)
        expect(chartMock).toHaveBeenCalledTimes(2)
        charts.forEach((chart) => expect(chart.destroy).toHaveBeenCalledTimes(1))
    })

    it('does not instantiate a chart if unmounted before the first scheduled frame', async () => {
        await mountResponse()
        const lateFrame = [...frames.values()][0]
        wrapper.unmount()
        wrapper = undefined
        lateFrame(0)
        expect(chartMock).not.toHaveBeenCalled()
        expect(frames.size).toBe(0)
    })

    it('starts a remounted calculator with fresh price state and a fresh scheduler', async () => {
        await mountResponse()
        await underlyingInput().setValue('105')
        await renderFrame()
        wrapper.unmount()
        wrapper = undefined
        await mountResponse()
        expect(underlyingInput().element.value).toBe('100')
        expect(wrapper.find('[data-testid="calculator-manual-underlying"]').exists()).toBe(false)
        expect(frames.size).toBe(1)
        await renderFrame()
        expect(chartMock).toHaveBeenCalledTimes(4)
    })
})
