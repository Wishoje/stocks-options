import { afterEach, vi } from 'vitest'

Object.defineProperty(HTMLCanvasElement.prototype, 'getContext', {
    configurable: true,
    value: () => ({}),
})

afterEach(() => {
    window.localStorage.clear()
    document.body.innerHTML = ''
    vi.useRealTimers()
})
