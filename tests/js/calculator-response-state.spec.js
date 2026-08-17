import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const axiosMock = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
}))
const chartMock = vi.hoisted(() => vi.fn(() => ({ destroy: vi.fn() })))

vi.mock('axios', () => ({ default: axiosMock }))
vi.mock('chart.js/auto', () => ({
    default: chartMock,
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
    underlying: {
        symbol: 'SPY',
        price: 595.25,
        status: 'live',
        usable_for_calculation: true,
        source: 'massive',
        asof: '2026-07-16T14:35:00Z',
    },
    expirations: [{ value: '2026-07-17', label: 'Jul 17', dte: 7 }],
    chain: [
        {
            contract_symbol: 'O:SPY260717C00595000',
            expiry: '2026-07-17',
            strike: 595,
            type: 'call',
            bid: 4.8,
            ask: 5.2,
            iv: 0.2,
            dte: 7,
        },
        {
            contract_symbol: 'O:SPY260717P00595000',
            expiry: '2026-07-17',
            strike: 595,
            type: 'put',
            bid: 7.8,
            ask: 8.2,
            iv: 0.45,
            dte: 7,
        },
    ],
}

const coldResponse = {
    status: 'no_snapshot',
    underlying: {
        symbol: 'SPY',
        price: null,
        status: 'unavailable',
        usable_for_calculation: false,
    },
    expirations: [],
    chain: [],
}

const startResponse = (id = 'run-1', extra = {}) => ({
    status: 202,
    data: {
        run_id: id,
        status_url: `/api/work-runs/${id}`,
        status: 'pending',
        terminal: false,
        retry_after_seconds: 0,
        ...extra,
    },
})

const runResponse = (status = 'running', extra = {}) => ({
    status: 200,
    headers: { 'retry-after': '0' },
    data: {
        run_id: 'run-1',
        status_url: '/api/work-runs/run-1',
        status,
        terminal: ['completed', 'failed'].includes(status),
        retry_after_seconds: 0,
        ...extra,
    },
})

describe('calculator API response states', () => {
    let wrapper

    beforeEach(() => {
        window.localStorage.clear()
        axiosMock.get.mockReset()
        axiosMock.post.mockReset()
        chartMock.mockClear()
        vi.spyOn(console, 'log').mockImplementation(() => {})
        vi.spyOn(console, 'warn').mockImplementation(() => {})
    })

    afterEach(() => {
        wrapper?.unmount()
        wrapper = undefined
        vi.useRealTimers()
        vi.restoreAllMocks()
    })

    it('loads once, starts once, and shows preparation while the work start is pending', async () => {
        axiosMock.get.mockResolvedValue({ data: coldResponse })
        axiosMock.post.mockReturnValue(new Promise(() => {}))

        wrapper = mount(Calculator)
        await flushPromises()

        expect(wrapper.text()).toContain('Preparing SPY calculator')
        expect(axiosMock.get).toHaveBeenCalledTimes(1)
        expect(axiosMock.post).toHaveBeenCalledTimes(1)
    })

    it('adopts an active run from the initial read without sending another POST', async () => {
        axiosMock.get
            .mockResolvedValueOnce({
                data: {
                    ...coldResponse,
                    work_run: startResponse('existing-run').data,
                },
            })
            .mockReturnValueOnce(new Promise(() => {}))

        wrapper = mount(Calculator)
        await flushPromises()

        expect(axiosMock.post).not.toHaveBeenCalled()
        expect(axiosMock.get.mock.calls[1][0]).toBe('/api/work-runs/existing-run')
        expect(wrapper.text()).toContain('Preparing SPY calculator')
    })

    it('shows a stable error state when the chain request fails', async () => {
        axiosMock.get.mockRejectedValue(new Error('network unavailable'))
        vi.spyOn(console, 'error').mockImplementation(() => {})

        wrapper = mount(Calculator)
        await flushPromises()

        expect(wrapper.text()).toContain('Failed to load chain')
        expect(wrapper.text()).not.toContain('Preparing SPY calculator')
    })

    it('polls the lightweight status URL and fetches the chain only when terminal', async () => {
        vi.useFakeTimers()
        axiosMock.post.mockResolvedValue(startResponse())
        axiosMock.get
            .mockResolvedValueOnce({
                data: {
                    ...completeResponse,
                    status: 'partial',
                    chain: [],
                },
            })
            .mockResolvedValueOnce(runResponse('running'))
            .mockResolvedValueOnce(runResponse('completed'))
            .mockResolvedValueOnce({ data: completeResponse })

        wrapper = mount(Calculator)
        await flushPromises()

        expect(wrapper.find('[data-testid="calculator-refresh-running"]').exists()).toBe(true)
        expect(wrapper.text()).toContain('No chain rows yet')
        expect(axiosMock.get).toHaveBeenCalledTimes(2)

        expect(axiosMock.post).toHaveBeenCalledTimes(1)
        expect(axiosMock.get.mock.calls[1][0]).toBe('/api/work-runs/run-1')

        await vi.advanceTimersByTimeAsync(250)
        await flushPromises()

        expect(axiosMock.get).toHaveBeenCalledTimes(4)
        expect(axiosMock.post).toHaveBeenCalledTimes(1)
        expect(wrapper.text()).toContain('Live Chain')
        expect(wrapper.text()).not.toContain('Preparing SPY calculator')
    })

    it('publishes the calculator only after a complete chain response arrives', async () => {
        axiosMock.get.mockResolvedValue({ data: completeResponse })

        wrapper = mount(Calculator)
        await flushPromises()

        expect(wrapper.text()).toContain('Live Chain')
        expect(wrapper.text()).toContain('Jul 17')
        expect(wrapper.text()).toContain('SPY @ $595.25')
        expect(wrapper.text()).not.toContain('Preparing SPY calculator')
        expect(wrapper.text()).not.toContain('Failed to load chain')
        expect(wrapper.text()).toContain('DTE: 7 days')
    })

    it('switches to the exact counterpart and preserves a labeled manual entry', async () => {
        axiosMock.get.mockResolvedValue({ data: completeResponse })

        wrapper = mount(Calculator)
        await flushPromises()

        const entry = wrapper.find('input[step="0.01"]')
        expect(entry.element.value).toBe('5')
        await entry.setValue('3')
        expect(wrapper.text()).toContain('(manual)')

        const putButton = wrapper.findAll('button').find((button) => button.text().includes('Long Put'))
        await putButton.trigger('click')
        await flushPromises()

        expect(wrapper.text()).toContain('2026-07-17 595 PUT')
        expect(wrapper.text()).toContain('Mid: $8.00')
        expect(entry.element.value).toBe('3')
        expect(wrapper.text()).toContain('Breakeven$592.00')
    })

    it('renders every adjusted same-strike contract and switches within its provider family', async () => {
        const adjustedCallSymbol = 'O:SPY1260717C00595000'
        const adjustedPutSymbol = 'O:SPY1260717P00595000'
        axiosMock.get.mockResolvedValue({
            data: {
                ...completeResponse,
                chain: [
                    ...completeResponse.chain,
                    {
                        ...completeResponse.chain[0],
                        contract_symbol: adjustedCallSymbol,
                        bid: 2.8,
                        ask: 3.2,
                    },
                    {
                        ...completeResponse.chain[1],
                        contract_symbol: adjustedPutSymbol,
                        bid: 3.8,
                        ask: 4.2,
                    },
                ],
            },
        })

        wrapper = mount(Calculator)
        await flushPromises()

        expect(wrapper.findAll('[data-contract-symbol]')).toHaveLength(4)
        expect(wrapper.text()).toContain('SPY contract')
        expect(wrapper.text()).toContain('SPY1 contract')

        const adjustedCall = wrapper.find(`[data-contract-symbol="${adjustedCallSymbol}"]`)
        await adjustedCall.trigger('click')
        expect(wrapper.find('input[step="0.01"]').element.value).toBe('3')

        const putButton = wrapper.findAll('button').find((button) => button.text().includes('Long Put'))
        await putButton.trigger('click')
        await flushPromises()

        const adjustedPut = wrapper.find(`[data-contract-symbol="${adjustedPutSymbol}"]`)
        expect(adjustedPut.classes()).toContain('font-bold')
        expect(wrapper.find('input[step="0.01"]').element.value).toBe('4')
    })

    it('does not carry a manual premium into a different expiration', async () => {
        const catalog = [
            { value: '2026-07-17', label: 'Jul 17', dte: 7, readiness: 'ready' },
            { value: '2026-07-24', label: 'Jul 24', dte: 14, readiness: 'ready' },
        ]
        axiosMock.get
            .mockResolvedValueOnce({
                data: { ...completeResponse, resolved_expiry: '2026-07-17', expirations: catalog },
            })
            .mockResolvedValueOnce({
                data: {
                    ...completeResponse,
                    resolved_expiry: '2026-07-24',
                    expirations: catalog,
                    chain: completeResponse.chain.map((contract) => ({
                        ...contract,
                        contract_symbol: contract.contract_symbol.replace('260717', '260724'),
                        expiry: '2026-07-24',
                        expiration_date: '2026-07-24',
                        dte: 14,
                    })),
                },
            })

        wrapper = mount(Calculator)
        await flushPromises()
        const entry = wrapper.find('input[step="0.01"]')
        await entry.setValue('3')
        expect(wrapper.text()).toContain('(manual)')

        const july24 = wrapper.findAll('button').find((button) => button.text().includes('Jul 24'))
        await july24.trigger('click')
        await flushPromises()

        expect(wrapper.text()).toContain('(live mid)')
        expect(entry.element.value).toBe('5')
        expect(wrapper.text()).toContain('2026-07-24 595 CALL')
    })

    it('clears selection when the exact type counterpart does not exist', async () => {
        axiosMock.get.mockResolvedValue({
            data: { ...completeResponse, chain: [completeResponse.chain[0]] },
        })

        wrapper = mount(Calculator)
        await flushPromises()

        const putButton = wrapper.findAll('button').find((button) => button.text().includes('Long Put'))
        await putButton.trigger('click')
        await flushPromises()

        expect(wrapper.text()).toContain('No put contract exists at the selected strike and expiration')
        expect(wrapper.text()).toContain('Breakeven—')
    })

    it('does not show or calculate a fabricated spot when the quote is unavailable', async () => {
        axiosMock.get.mockResolvedValue({
            data: {
                ...completeResponse,
                underlying: {
                    symbol: 'SPY',
                    price: null,
                    status: 'unavailable',
                    usable_for_calculation: false,
                    reason: 'missing_quote',
                },
            },
        })

        wrapper = mount(Calculator)
        await flushPromises()

        const callCell = wrapper.find('tbody tr').findAll('td')[1]
        await callCell.trigger('click')
        await flushPromises()

        expect(wrapper.text()).toContain('SPY @ Quote unavailable')
        expect(wrapper.text()).toContain('spot-dependent payoff and time-decay charts are paused')
        expect(wrapper.text()).not.toContain('SPY @ $0.00')
        expect(wrapper.text()).not.toContain('SPY @ $100.00')
        expect(wrapper.text()).toContain('Breakeven$600.00')
        expect(wrapper.text()).toContain('Max Loss$500')
        expect(wrapper.text()).toContain('Cost$500')
        expect(wrapper.find('[data-testid="calculator-charts-paused"]').exists()).toBe(true)
        expect(chartMock).not.toHaveBeenCalled()
    })

    it('accepts an exact real $100 spot and chooses the closest requested-type contract', async () => {
        axiosMock.get.mockResolvedValue({
            data: {
                ...completeResponse,
                underlying: {
                    ...completeResponse.underlying,
                    price: 100,
                },
                chain: [
                    {
                        ...completeResponse.chain[0],
                        contract_symbol: 'CALL-150',
                        strike: 150,
                    },
                    {
                        ...completeResponse.chain[0],
                        contract_symbol: 'CALL-101',
                        strike: 101,
                    },
                ],
            },
        })

        wrapper = mount(Calculator)
        await flushPromises()

        expect(wrapper.text()).toContain('SPY @ $100.00')
        expect(wrapper.text()).toContain('2026-07-17 101 CALL')
        expect(wrapper.text()).not.toContain('2026-07-17 150 CALL')
    })

    it('uses the backend resolved expiry and displays per-expiry readiness', async () => {
        axiosMock.get.mockResolvedValue({
            data: {
                ...completeResponse,
                requested_expiry: '2026-07-17',
                resolved_expiry: '2026-07-24',
                expirations: [
                    { value: '2026-07-17', label: 'Jul 17', dte: 7, readiness: 'pending' },
                    { value: '2026-07-24', label: 'Jul 24', dte: 14, readiness: 'ready' },
                ],
                chain: completeResponse.chain.map((contract) => ({
                    ...contract,
                    expiry: '2026-07-24',
                    expiration_date: '2026-07-24',
                    dte: 14,
                })),
            },
        })

        wrapper = mount(Calculator)
        await flushPromises()

        expect(wrapper.find('[data-readiness="pending"]').exists()).toBe(true)
        expect(wrapper.find('[data-readiness="ready"]').exists()).toBe(true)
        expect(wrapper.text()).toContain('2026-07-24 595 CALL')
    })

    it('uses a resolved valid expiry for selected-expiry refresh work even when no chain is published', async () => {
        const catalog = [
            { value: '2026-07-17', label: 'Jul 17', dte: 7, readiness: 'ready' },
            { value: '2099-01-01', label: 'Invalid', dte: 0, readiness: 'preparing' },
        ]
        axiosMock.get
            .mockResolvedValueOnce({ data: { ...completeResponse, resolved_expiry: '2026-07-17', expirations: catalog } })
            .mockResolvedValueOnce({
                data: {
                    ...coldResponse,
                    status: 'no_expiry_snapshot',
                    requested_expiry: '2099-01-01',
                    resolved_expiry: '2026-07-17',
                    expirations: catalog,
                },
            })
        axiosMock.post.mockReturnValue(new Promise(() => {}))

        wrapper = mount(Calculator)
        await flushPromises()
        const invalid = wrapper.findAll('button').find((button) => button.text().includes('Invalid'))
        await invalid.trigger('click')
        await flushPromises()

        expect(axiosMock.post).toHaveBeenCalledTimes(1)
        expect(axiosMock.post.mock.calls[0][1]).toEqual({
            symbol: 'SPY',
            expiry: '2026-07-17',
        })
    })

    it('keeps an initial unresolved request as a full-catalog refresh after choosing the server default', async () => {
        axiosMock.get.mockResolvedValue({
            data: {
                ...coldResponse,
                status: 'no_expiry_snapshot',
                resolved_expiry: '2026-07-17',
                expirations: [{ value: '2026-07-17', label: 'Jul 17', readiness: 'preparing' }],
            },
        })
        axiosMock.post.mockReturnValue(new Promise(() => {}))

        wrapper = mount(Calculator)
        await flushPromises()

        expect(axiosMock.post).toHaveBeenCalledTimes(1)
        expect(axiosMock.post.mock.calls[0][1]).toEqual({ symbol: 'SPY' })
    })

    it('renders a canonical ready expiry while the wider catalog is partial', async () => {
        axiosMock.post.mockResolvedValue(startResponse('run-partial'))
        // Keep the active background poll pending after the canonical chain is rendered.
        axiosMock.get
            .mockResolvedValueOnce({
                data: {
                    ...completeResponse,
                    status: 'partial',
                    catalog_state: 'partial',
                    selected_chain_state: 'ready',
                    resolved_expiry: '2026-07-17',
                    publication: { state: 'ready', source: 'canonical', id: 44 },
                    expirations: [
                        { value: '2026-07-17', label: 'Jul 17', dte: 7, readiness: 'ready' },
                        { value: '2026-07-24', label: 'Jul 24', dte: 14, readiness: 'preparing' },
                    ],
                },
            })
            .mockReturnValueOnce(new Promise(() => {}))

        wrapper = mount(Calculator)
        await flushPromises()

        expect(wrapper.text()).toContain('Live Chain')
        expect(wrapper.text()).toContain('2026-07-17 595 CALL')
        expect(wrapper.find('[data-readiness="preparing"]').exists()).toBe(true)
        expect(axiosMock.post).toHaveBeenCalledTimes(1)
    })

    it('stops automatic polling after more than 90 seconds and resumes without another POST', async () => {
        vi.useFakeTimers()
        axiosMock.post.mockResolvedValue(startResponse())
        axiosMock.get.mockImplementation((url) => {
            if (url === '/api/option-chain') return Promise.resolve({ data: coldResponse })
            return Promise.resolve({
                ...runResponse('running'),
                headers: { 'retry-after': '2' },
                data: { ...runResponse('running').data, retry_after_seconds: 2 },
            })
        })

        wrapper = mount(Calculator)
        await flushPromises()
        await vi.runAllTimersAsync()
        await flushPromises()

        expect(wrapper.find('[data-testid="calculator-refresh-slow"]').exists()).toBe(true)
        expect(wrapper.text()).toContain('still running in the background')
        expect(axiosMock.post).toHaveBeenCalledTimes(1)
        expect(axiosMock.get.mock.calls.filter(([url]) => url === '/api/work-runs/run-1')).toHaveLength(50)

        const continueButton = wrapper.findAll('button').find((button) => button.text().includes('Continue checking'))
        await continueButton.trigger('click')
        await flushPromises()

        expect(axiosMock.post).toHaveBeenCalledTimes(1)
        expect(axiosMock.get.mock.calls.filter(([url]) => url === '/api/work-runs/run-1')).toHaveLength(51)
    })

    it('honors Retry-After between status requests', async () => {
        vi.useFakeTimers()
        axiosMock.post.mockResolvedValue(startResponse())
        axiosMock.get
            .mockResolvedValueOnce({ data: coldResponse })
            .mockResolvedValueOnce({
                ...runResponse('running'),
                headers: { 'retry-after': '3' },
                data: { ...runResponse('running').data, retry_after_seconds: 3 },
            })
            .mockResolvedValueOnce(runResponse('completed'))
            .mockResolvedValueOnce({ data: completeResponse })

        wrapper = mount(Calculator)
        await flushPromises()

        expect(axiosMock.get).toHaveBeenCalledTimes(2)
        await vi.advanceTimersByTimeAsync(2_999)
        expect(axiosMock.get).toHaveBeenCalledTimes(2)
        await vi.advanceTimersByTimeAsync(1)
        await flushPromises()
        expect(axiosMock.get).toHaveBeenCalledTimes(4)
    })

    it('does not reload the full chain when only an unrelated expiry becomes ready', async () => {
        axiosMock.post.mockResolvedValue(startResponse())
        axiosMock.get
            .mockResolvedValueOnce({
                data: {
                    ...coldResponse,
                    status: 'no_expiry_snapshot',
                    resolved_expiry: '2026-07-17',
                    expirations: [
                        { value: '2026-07-17', label: 'Jul 17', readiness: 'preparing' },
                        { value: '2026-07-24', label: 'Jul 24', readiness: 'preparing' },
                    ],
                },
            })
            .mockResolvedValueOnce(runResponse('running', {
                calculator: {
                    expected_count: 2,
                    completed_count: 1,
                    failed_count: 0,
                    expirations: [
                        { expiration: '2026-07-17', readiness: 'pending' },
                        { expiration: '2026-07-24', readiness: 'ready', publication_id: 10 },
                    ],
                },
            }))

        wrapper = mount(Calculator)
        await flushPromises()

        expect(axiosMock.get).toHaveBeenCalledTimes(2)
        expect(axiosMock.get.mock.calls.filter(([url]) => url === '/api/option-chain')).toHaveLength(1)
        expect(wrapper.find('[data-readiness="ready"]').exists()).toBe(true)
    })

    it('shows a newly ready selected expiry while the catalog run continues', async () => {
        vi.useFakeTimers()
        axiosMock.post.mockResolvedValue(startResponse())
        axiosMock.get
            .mockResolvedValueOnce({ data: coldResponse })
            .mockResolvedValueOnce(runResponse('running', {
                calculator: {
                    status: 'partial',
                    expected_count: 2,
                    completed_count: 1,
                    failed_count: 0,
                    expirations: [
                        { expiration: '2026-07-17', readiness: 'ready', publication_id: 10 },
                        { expiration: '2026-07-24', readiness: 'pending' },
                    ],
                },
            }))
            .mockResolvedValueOnce({ data: completeResponse })

        wrapper = mount(Calculator)
        await flushPromises()

        expect(wrapper.text()).toContain('2026-07-17 595 CALL')
        expect(wrapper.find('[data-testid="calculator-refresh-running"]').exists()).toBe(true)
        expect(axiosMock.get.mock.calls.filter(([url]) => url === '/api/option-chain')).toHaveLength(2)
    })

    it('uses one chain read when the selected expiry becomes ready at terminal status', async () => {
        axiosMock.post.mockResolvedValue(startResponse())
        axiosMock.get
            .mockResolvedValueOnce({
                data: {
                    ...coldResponse,
                    status: 'no_expiry_snapshot',
                    resolved_expiry: '2026-07-17',
                    expirations: [
                        { value: '2026-07-17', label: 'Jul 17', readiness: 'preparing' },
                    ],
                },
            })
            .mockResolvedValueOnce(runResponse('completed', {
                calculator: {
                    status: 'complete',
                    expected_count: 1,
                    completed_count: 1,
                    failed_count: 0,
                    expirations: [
                        { expiration: '2026-07-17', readiness: 'ready', publication_id: 10 },
                    ],
                },
            }))
            .mockResolvedValueOnce({ data: completeResponse })

        wrapper = mount(Calculator)
        await flushPromises()

        expect(axiosMock.get.mock.calls.filter(([url]) => url === '/api/option-chain')).toHaveLength(2)
        expect(wrapper.text()).toContain('2026-07-17 595 CALL')
        expect(wrapper.find('[data-testid="calculator-refresh-running"]').exists()).toBe(false)
    })

    it('retries a transient status failure and continues to terminal success', async () => {
        vi.useFakeTimers()
        axiosMock.post.mockResolvedValue(startResponse())
        axiosMock.get
            .mockResolvedValueOnce({ data: coldResponse })
            .mockRejectedValueOnce({ response: { status: 500, headers: { 'retry-after': '1' } } })
            .mockResolvedValueOnce(runResponse('completed'))
            .mockResolvedValueOnce({ data: completeResponse })

        wrapper = mount(Calculator)
        await flushPromises()

        expect(axiosMock.get).toHaveBeenCalledTimes(2)
        expect(wrapper.text()).toContain('Preparing SPY calculator')
        await vi.advanceTimersByTimeAsync(1_000)
        await flushPromises()

        expect(axiosMock.get).toHaveBeenCalledTimes(4)
        expect(wrapper.text()).toContain('Live Chain')
        expect(wrapper.find('[data-testid="calculator-refresh-slow"]').exists()).toBe(false)
    })

    it('preserves the last complete chain and menu through partial data and terminal failure', async () => {
        vi.useFakeTimers()
        axiosMock.post.mockResolvedValue(startResponse())
        axiosMock.get
            .mockResolvedValueOnce({ data: completeResponse })
            .mockResolvedValueOnce(runResponse('running', {
                calculator: {
                    expected_count: 2,
                    completed_count: 1,
                    failed_count: 0,
                    expirations: [
                        { expiration: '2026-07-17', readiness: 'ready', publication_id: 2 },
                        { expiration: '2026-07-24', readiness: 'pending' },
                    ],
                },
            }))
            .mockResolvedValueOnce({
                data: {
                    ...coldResponse,
                    status: 'partial',
                    expirations: [{ value: '2026-07-24', label: 'Jul 24' }],
                },
            })
            .mockResolvedValueOnce(runResponse('failed', { message: 'Provider stopped early.' }))
            .mockResolvedValueOnce(runResponse('completed'))
            .mockResolvedValueOnce({
                data: {
                    ...completeResponse,
                    underlying: { ...completeResponse.underlying, price: 600 },
                },
            })

        wrapper = mount(Calculator)
        await flushPromises()
        const refresh = wrapper.findAll('button').find((button) => button.text().includes('Refresh Live Data'))
        await refresh.trigger('click')
        await flushPromises()

        expect(wrapper.text()).toContain('Jul 17')
        expect(wrapper.text()).toContain('2026-07-17 595 CALL')
        expect(wrapper.text()).not.toContain('Jul 24 Pending')

        await vi.advanceTimersByTimeAsync(250)
        await flushPromises()

        expect(wrapper.text()).toContain('Provider stopped early.')
        expect(wrapper.text()).toContain('Jul 17')
        expect(wrapper.text()).toContain('2026-07-17 595 CALL')

        const retry = wrapper.findAll('button').find((button) => button.text().includes('Retry refresh'))
        await retry.trigger('click')
        await flushPromises()

        expect(axiosMock.post).toHaveBeenCalledTimes(2)
        expect(wrapper.text()).toContain('SPY @ $600.00')
        expect(wrapper.find('[data-testid="calculator-refresh-failed"]').exists()).toBe(false)
    })

    it('clears a manual entry when its contract disappears after refresh', async () => {
        axiosMock.post.mockResolvedValue(startResponse())
        axiosMock.get
            .mockResolvedValueOnce({ data: completeResponse })
            .mockResolvedValueOnce(runResponse('completed'))
            .mockResolvedValueOnce({
                data: {
                    ...completeResponse,
                    chain: completeResponse.chain.map((contract) => ({
                        ...contract,
                        contract_symbol: contract.contract_symbol.replace('595000', '600000'),
                        strike: 600,
                    })),
                },
            })

        wrapper = mount(Calculator)
        await flushPromises()
        const entry = wrapper.find('input[step="0.01"]')
        await entry.setValue('3')

        const refresh = wrapper.findAll('button').find((button) => button.text().includes('Refresh Live Data'))
        await refresh.trigger('click')
        await flushPromises()

        expect(entry.element.value).toBe('')
        expect(wrapper.text()).not.toContain('2026-07-17 600 CALL')
        expect(wrapper.text()).toContain('Breakeven—')
    })

    it('treats a complete empty catalog as terminal no-options without starting work', async () => {
        const noOptions = {
            ...coldResponse,
            status: 'no_options',
            catalog_state: 'complete',
            resolved_expiry: null,
            underlying: completeResponse.underlying,
        }
        axiosMock.get.mockResolvedValue({ data: noOptions })

        wrapper = mount(Calculator)
        await flushPromises()

        expect(wrapper.find('[data-testid="calculator-no-options"]').exists()).toBe(true)
        expect(wrapper.text()).toContain('No options are available for SPY')
        expect(axiosMock.post).not.toHaveBeenCalled()

        wrapper.unmount()
        wrapper = mount(Calculator)
        await flushPromises()

        expect(wrapper.find('[data-testid="calculator-no-options"]').exists()).toBe(true)
        expect(axiosMock.post).not.toHaveBeenCalled()
    })

    it('clears an old selection when a terminal full refresh publishes no options', async () => {
        const noOptions = {
            ...coldResponse,
            status: 'no_options',
            catalog_state: 'complete',
            resolved_expiry: null,
            underlying: completeResponse.underlying,
        }
        axiosMock.post
            .mockResolvedValueOnce(startResponse())
            .mockReturnValueOnce(new Promise(() => {}))
        axiosMock.get
            .mockResolvedValueOnce({ data: completeResponse })
            .mockResolvedValueOnce(runResponse('completed'))
            .mockResolvedValueOnce({ data: noOptions })

        wrapper = mount(Calculator)
        await flushPromises()
        expect(wrapper.text()).toContain('2026-07-17 595 CALL')
        const entry = wrapper.find('input[step="0.01"]')
        await entry.setValue('3')

        const refresh = wrapper.findAll('button').find((button) => button.text().includes('Refresh Live Data'))
        await refresh.trigger('click')
        await flushPromises()

        expect(wrapper.find('[data-testid="calculator-no-options"]').exists()).toBe(true)
        expect(wrapper.text()).not.toContain('2026-07-17 595 CALL')
        expect(wrapper.text()).not.toContain('Calculator data is ready.')
        expect(entry.element.value).toBe('')

        await refresh.trigger('click')
        await flushPromises()

        expect(axiosMock.post).toHaveBeenCalledTimes(2)
        expect(axiosMock.post.mock.calls[1][1]).toEqual({ symbol: 'SPY', force: true })
    })

    it.each([
        [401, 'session expired'],
        [403, 'plan does not include'],
        [429, 'capacity is busy'],
    ])('keeps a stable %s work-start error without retrying', async (status, message) => {
        axiosMock.get.mockResolvedValue({ data: coldResponse })
        axiosMock.post.mockRejectedValue({
            response: {
                status,
                headers: { 'retry-after': '7' },
                data: { retry_after_seconds: 7 },
            },
        })

        wrapper = mount(Calculator)
        await flushPromises()

        expect(wrapper.text().toLowerCase()).toContain(message)
        expect(axiosMock.get).toHaveBeenCalledTimes(1)
        expect(axiosMock.post).toHaveBeenCalledTimes(1)
    })

    it('prevents a late symbol response from overwriting a newer selection', async () => {
        let resolveSpy
        const spyResponse = new Promise((resolve) => { resolveSpy = resolve })
        axiosMock.get
            .mockReturnValueOnce(spyResponse)
            .mockResolvedValueOnce({
                data: {
                    ...completeResponse,
                    underlying: { ...completeResponse.underlying, symbol: 'AAPL', price: 225 },
                    chain: completeResponse.chain.map((contract) => ({
                        ...contract,
                        contract_symbol: contract.contract_symbol.replace('SPY', 'AAPL'),
                        symbol: 'AAPL',
                    })),
                },
            })

        wrapper = mount(Calculator)
        window.dispatchEvent(new CustomEvent('select-symbol', { detail: { symbol: 'AAPL' } }))
        await flushPromises()

        expect(wrapper.text()).toContain('AAPL @ $225.00')
        resolveSpy({ data: completeResponse })
        await flushPromises()

        expect(wrapper.text()).toContain('AAPL @ $225.00')
        expect(wrapper.text()).not.toContain('SPY @ $595.25')
        expect(axiosMock.get.mock.calls[0][1].signal.aborted).toBe(true)
    })

    it('does not show the previous symbol chain while the new symbol is preparing', async () => {
        axiosMock.get
            .mockResolvedValueOnce({ data: completeResponse })
            .mockResolvedValueOnce({
                data: {
                    ...coldResponse,
                    underlying: { ...coldResponse.underlying, symbol: 'AAPL' },
                },
            })
        axiosMock.post.mockReturnValue(new Promise(() => {}))

        wrapper = mount(Calculator)
        await flushPromises()
        expect(wrapper.text()).toContain('2026-07-17 595 CALL')

        window.dispatchEvent(new CustomEvent('select-symbol', { detail: { symbol: 'AAPL' } }))
        await flushPromises()

        expect(wrapper.text()).toContain('Preparing AAPL calculator')
        expect(wrapper.text()).not.toContain('2026-07-17 595 CALL')
    })

    it('clears an old active poll when a new symbol is already ready', async () => {
        const pendingStatus = new Promise(() => {})
        axiosMock.post.mockResolvedValue(startResponse())
        axiosMock.get
            .mockResolvedValueOnce({ data: coldResponse })
            .mockReturnValueOnce(pendingStatus)
            .mockResolvedValueOnce({
                data: {
                    ...completeResponse,
                    underlying: { ...completeResponse.underlying, symbol: 'AAPL', price: 225 },
                    chain: completeResponse.chain.map((contract) => ({
                        ...contract,
                        contract_symbol: contract.contract_symbol.replace('SPY', 'AAPL'),
                        symbol: 'AAPL',
                    })),
                },
            })

        wrapper = mount(Calculator)
        await flushPromises()
        const oldStatusSignal = axiosMock.get.mock.calls[1][1].signal
        expect(wrapper.text()).toContain('Preparing SPY calculator')

        window.dispatchEvent(new CustomEvent('select-symbol', { detail: { symbol: 'AAPL' } }))
        await flushPromises()

        const refresh = wrapper.findAll('button').find((button) => button.text().includes('Refresh Live Data'))
        expect(oldStatusSignal.aborted).toBe(true)
        expect(wrapper.text()).toContain('AAPL @ $225.00')
        expect(wrapper.find('[data-testid="calculator-refresh-running"]').exists()).toBe(false)
        expect(refresh.attributes('disabled')).toBeUndefined()
    })

    it('clears an old active poll when a different expiry is already ready', async () => {
        const pendingStatus = new Promise(() => {})
        const catalog = [
            { value: '2026-07-17', label: 'Jul 17', dte: 7, readiness: 'ready' },
            { value: '2026-07-24', label: 'Jul 24', dte: 14, readiness: 'ready' },
        ]
        axiosMock.get
            .mockResolvedValueOnce({
                data: {
                    ...completeResponse,
                    resolved_expiry: '2026-07-17',
                    expirations: catalog,
                    work_run: startResponse('old-run').data,
                },
            })
            .mockReturnValueOnce(pendingStatus)
            .mockResolvedValueOnce({
                data: {
                    ...completeResponse,
                    resolved_expiry: '2026-07-24',
                    expirations: catalog,
                    chain: completeResponse.chain.map((contract) => ({
                        ...contract,
                        expiry: '2026-07-24',
                        expiration_date: '2026-07-24',
                        dte: 14,
                    })),
                },
            })

        wrapper = mount(Calculator)
        await flushPromises()
        const oldStatusSignal = axiosMock.get.mock.calls[1][1].signal
        const july24 = wrapper.findAll('button').find((button) => button.text().includes('Jul 24'))
        await july24.trigger('click')
        await flushPromises()

        const refresh = wrapper.findAll('button').find((button) => button.text().includes('Refresh Live Data'))
        expect(oldStatusSignal.aborted).toBe(true)
        expect(wrapper.text()).toContain('2026-07-24 595 CALL')
        expect(wrapper.find('[data-testid="calculator-refresh-running"]').exists()).toBe(false)
        expect(refresh.attributes('disabled')).toBeUndefined()
    })

    it('prevents a late expiry response from overwriting a newer expiry', async () => {
        let resolveJuly24
        const july24Response = new Promise((resolve) => { resolveJuly24 = resolve })
        const catalog = [
            { value: '2026-07-17', label: 'Jul 17', dte: 7, readiness: 'ready' },
            { value: '2026-07-24', label: 'Jul 24', dte: 14, readiness: 'ready' },
        ]
        axiosMock.get
            .mockResolvedValueOnce({
                data: { ...completeResponse, resolved_expiry: '2026-07-17', expirations: catalog },
            })
            .mockReturnValueOnce(july24Response)
            .mockResolvedValueOnce({
                data: { ...completeResponse, resolved_expiry: '2026-07-17', expirations: catalog },
            })

        wrapper = mount(Calculator)
        await flushPromises()

        const july24 = wrapper.findAll('button').find((button) => button.text().includes('Jul 24'))
        await july24.trigger('click')
        const july17 = wrapper.findAll('button').find((button) => button.text().includes('Jul 17'))
        await july17.trigger('click')
        await flushPromises()

        resolveJuly24({
            data: {
                ...completeResponse,
                resolved_expiry: '2026-07-24',
                expirations: catalog,
                underlying: { ...completeResponse.underlying, price: 610 },
                chain: completeResponse.chain.map((contract) => ({
                    ...contract,
                    expiry: '2026-07-24',
                    expiration_date: '2026-07-24',
                    dte: 14,
                })),
            },
        })
        await flushPromises()

        expect(wrapper.text()).toContain('2026-07-17 595 CALL')
        expect(wrapper.text()).toContain('SPY @ $595.25')
        expect(wrapper.text()).not.toContain('SPY @ $610.00')
        expect(axiosMock.get.mock.calls[1][1].signal.aborted).toBe(true)
    })

    it('aborts status polling when the component unmounts', async () => {
        const never = new Promise(() => {})
        axiosMock.get
            .mockResolvedValueOnce({ data: coldResponse })
            .mockReturnValueOnce(never)
        axiosMock.post.mockResolvedValue(startResponse())

        wrapper = mount(Calculator)
        await flushPromises()
        const statusSignal = axiosMock.get.mock.calls[1][1].signal

        wrapper.unmount()
        wrapper = undefined

        expect(statusSignal.aborted).toBe(true)
        expect(axiosMock.post).toHaveBeenCalledTimes(1)
    })
})
