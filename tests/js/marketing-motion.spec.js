import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'

import { activateMarketingMotion, reducedMotionQuery } from '@/Support/marketing-motion'

function makeRoot() {
  const root = document.createElement('div')
  root.innerHTML = '<section data-reveal></section><article data-reveal></article>'
  return root
}

function makePreference(matches = false) {
  const listeners = new Set()
  return {
    matches,
    addEventListener: vi.fn((event, listener) => listeners.add(listener)),
    removeEventListener: vi.fn((event, listener) => listeners.delete(listener)),
    setMatches(next) {
      this.matches = next
      listeners.forEach(listener => listener({ matches: next }))
    },
  }
}

describe('marketing reveal motion', () => {
  it('keeps a CSS reduced-motion override for reveal and hover transforms', () => {
    const css = readFileSync(resolve(process.cwd(), 'resources/css/marketing-refresh.css'), 'utf8')
    const reducedMotion = css.slice(css.indexOf('@media (prefers-reduced-motion: reduce)'))

    expect(reducedMotion).toContain('.mk-motion-ready [data-reveal]')
    expect(reducedMotion).toContain('animation: none !important')
    expect(reducedMotion).toContain('.mk-page-shell .mk-button:hover')
    expect(reducedMotion).toContain('transform: none !important')
  })

  it('keeps all content visible when reduced motion is requested', () => {
    const root = makeRoot()
    const preference = makePreference(true)
    const Observer = vi.fn()
    const stop = activateMarketingMotion(root, {
      window: { matchMedia: vi.fn(query => {
        expect(query).toBe(reducedMotionQuery)
        return preference
      }) },
      IntersectionObserver: Observer,
    })

    expect(Observer).not.toHaveBeenCalled()
    expect(root.classList.contains('mk-motion-ready')).toBe(false)
    expect([...root.querySelectorAll('[data-reveal]')].map(item => item.dataset.revealState)).toEqual(['visible', 'visible'])
    stop()
  })

  it('reveals intersecting items once and disconnects cleanly', () => {
    const root = makeRoot()
    const preference = makePreference(false)
    const observed = []
    const unobserve = vi.fn()
    const disconnect = vi.fn()
    let callback
    const Observer = vi.fn((nextCallback, options) => {
      callback = nextCallback
      expect(options).toEqual({ threshold: 0.12, rootMargin: '0px 0px -8% 0px' })
      return { observe: item => observed.push(item), unobserve, disconnect }
    })
    const stop = activateMarketingMotion(root, {
      window: { matchMedia: () => preference },
      IntersectionObserver: Observer,
    })

    expect(root.classList.contains('mk-motion-ready')).toBe(true)
    expect(observed).toHaveLength(2)
    expect(observed.map(item => item.dataset.revealState)).toEqual(['pending', 'pending'])
    callback([{ isIntersecting: true, target: observed[0] }, { isIntersecting: false, target: observed[1] }])
    expect(observed[0].dataset.revealState).toBe('visible')
    expect(observed[1].dataset.revealState).toBe('pending')
    expect(unobserve).toHaveBeenCalledWith(observed[0])

    stop()
    expect(disconnect).toHaveBeenCalled()
    expect(root.classList.contains('mk-motion-ready')).toBe(false)
    expect(observed.map(item => item.dataset.revealState)).toEqual(['visible', 'visible'])
  })

  it('falls back to visible content if IntersectionObserver is unavailable or fails', () => {
    const unavailableRoot = makeRoot()
    activateMarketingMotion(unavailableRoot, {
      window: { matchMedia: () => makePreference(false) },
      IntersectionObserver: 'unsupported',
    })
    expect(unavailableRoot.classList.contains('mk-motion-ready')).toBe(false)
    expect([...unavailableRoot.querySelectorAll('[data-reveal]')].every(item => item.dataset.revealState === 'visible')).toBe(true)

    const failedRoot = makeRoot()
    const FailingObserver = vi.fn(() => { throw new Error('observer failed') })
    expect(() => activateMarketingMotion(failedRoot, {
      window: { matchMedia: () => makePreference(false) },
      IntersectionObserver: FailingObserver,
    })).not.toThrow()
    expect(failedRoot.classList.contains('mk-motion-ready')).toBe(false)
    expect([...failedRoot.querySelectorAll('[data-reveal]')].every(item => item.dataset.revealState === 'visible')).toBe(true)
  })

  it('reveals pending content if the user enables reduced motion at runtime', () => {
    const root = makeRoot()
    const preference = makePreference(false)
    const disconnect = vi.fn()
    const Observer = vi.fn(() => ({ observe: vi.fn(), unobserve: vi.fn(), disconnect }))
    const stop = activateMarketingMotion(root, {
      window: { matchMedia: () => preference },
      IntersectionObserver: Observer,
    })

    preference.setMatches(true)
    expect(disconnect).toHaveBeenCalled()
    expect(root.classList.contains('mk-motion-ready')).toBe(false)
    expect([...root.querySelectorAll('[data-reveal]')].every(item => item.dataset.revealState === 'visible')).toBe(true)
    stop()
    expect(preference.removeEventListener).toHaveBeenCalledWith('change', expect.any(Function))
  })
})
