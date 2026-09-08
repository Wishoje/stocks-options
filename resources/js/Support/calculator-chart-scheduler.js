/** Coalesce calculator changes into one render using the latest committed DOM. */
export const createCalculatorChartScheduler = (render, {
    requestFrame = (callback) => globalThis.requestAnimationFrame(callback),
    cancelFrame = (handle) => globalThis.cancelAnimationFrame(handle),
} = {}) => {
    let pending = null
    let disposed = false

    return {
        schedule() {
            if (disposed || pending !== null) return

            pending = requestFrame(() => {
                pending = null
                if (!disposed) render()
            })
        },
        dispose() {
            disposed = true
            if (pending !== null) cancelFrame(pending)
            pending = null
        },
    }
}
