import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  journeyUrl,
  normalizePlanSelection,
  selectionForPage,
  selectionFromUrl,
  yearlySavings,
} from '../../resources/js/Support/marketing-journey.js'
import { initGA, isAllowedEventParam, sanitizeEventParams, sanitizePageUrl, sanitizeReferrer, trackEvent, trackEventOnce, trackEventOnceAndWait, trackPageView } from '../../resources/js/lib/ga.js'

describe('public journey contracts', () => {
  beforeEach(() => {
    window.sessionStorage.clear()
    window.localStorage.clear()
    window.gtag = vi.fn()
  })

  it('keeps only the supported plan and billing interval', () => {
    expect(normalizePlanSelection({ plan: 'other', billing: 'weekly' })).toEqual({
      plan: 'earlybird',
      billing: 'monthly',
    })
    expect(selectionFromUrl('/pricing?plan=earlybird&billing=yearly')).toEqual({
      plan: 'earlybird',
      billing: 'yearly',
    })
  })

  it('carries the selection through registration, login, and checkout', () => {
    const selection = { plan: 'earlybird', billing: 'yearly' }
    expect(journeyUrl('register', selection)).toBe('/register?plan=earlybird&billing=yearly')
    expect(journeyUrl('login', selection)).toBe('/login?plan=earlybird&billing=yearly')
    expect(journeyUrl('checkout', selection)).toBe('/checkout?plan=earlybird&billing=yearly')
  })

  it('keeps the session selection when a public URL has no explicit billing query', () => {
    expect(selectionForPage('/features', { plan: 'earlybird', billing: 'yearly' })).toEqual({
      plan: 'earlybird',
      billing: 'yearly',
    })
    expect(selectionForPage('/features?billing=monthly', { plan: 'earlybird', billing: 'yearly' })).toEqual({
      plan: 'earlybird',
      billing: 'monthly',
    })
  })

  it('derives yearly savings from the shared display amounts', () => {
    expect(yearlySavings({ monthly: { amount_minor: 2999 }, yearly: { amount_minor: 29900 } })).toBeCloseTo(60.88)
    expect(yearlySavings({})).toBeNull()
    expect(yearlySavings({ monthly: { amount_minor: 2999.5 }, yearly: { amount_minor: 29900 } })).toBeNull()
  })

  it('removes personal and market data from analytics parameters', () => {
    expect(sanitizeEventParams({
      source: 'pricing_page',
      billing: 'yearly',
      surface: 'account_123',
      destination: 'private@example.test',
      email: 'private@example.test',
      symbol: 'SPY',
      message: 'private',
      nested: { unsafe: true },
    })).toEqual({ source: 'pricing_page', billing: 'yearly' })

    expect(sanitizeEventParams({
      source: 'john_smith',
      location: 'private_campaign',
      surface: 'dashboard',
      state: 'ready',
    })).toEqual({ surface: 'dashboard', state: 'ready' })
  })

  it('keeps every fixed public navigation CTA placement in the analytics contract', () => {
    for (const value of ['marketing_nav', 'marketing_mobile_nav']) {
      expect(isAllowedEventParam('location', value)).toBe(true)
    }
    expect(isAllowedEventParam('source', 'marketing_nav')).toBe(true)
  })

  it('removes sensitive query values while retaining safe attribution', () => {
    expect(sanitizePageUrl('/reset-password/private-token?email=private%40example.test&utm_source=newsletter&utm_campaign=private-name&plan=earlybird')).toEqual({
      pagePath: '/reset-password/:token?utm_source=newsletter&plan=earlybird',
      pageLocation: 'http://localhost/reset-password/:token?utm_source=newsletter&plan=earlybird',
    })
    expect(sanitizePageUrl('/pricing?utm_source=private-person&utm_medium=email')).toEqual({
      pagePath: '/pricing?utm_medium=email',
      pageLocation: 'http://localhost/pricing?utm_medium=email',
    })
    expect(sanitizeReferrer('https://partner.example/path?email=private@example.test')).toBe('https://partner.example/')
    expect(sanitizeReferrer('http://localhost/email/verify/12/private-hash?email=private@example.test')).toBe(
      'http://localhost/email/verify/:id/:hash',
    )
  })

  it('deduplicates confirmed outcomes within the selected storage scope', () => {
    expect(trackEventOnce('subscription_activation_confirmed', 'trial', { state: 'trial' })).toBe(true)
    expect(trackEventOnce('subscription_activation_confirmed', 'trial', { state: 'trial' })).toBe(false)
    expect(window.gtag).toHaveBeenCalledTimes(1)
  })

  it('deduplicates in memory when browser storage is unavailable', () => {
    const getItem = vi.spyOn(Storage.prototype, 'getItem').mockImplementationOnce(() => {
      throw new DOMException('Blocked', 'SecurityError')
    })
    const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementationOnce(() => {
      throw new DOMException('Blocked', 'SecurityError')
    })

    expect(trackEventOnce('first_useful_reading', 'storage-blocked', { state: 'ready' })).toBe(true)
    expect(trackEventOnce('first_useful_reading', 'storage-blocked', { state: 'ready' })).toBe(false)
    expect(window.gtag).toHaveBeenCalledTimes(1)

    getItem.mockRestore()
    setItem.mockRestore()
  })

  it('waits for the analytics callback before a registration handoff continues', async () => {
    window.gtag.mockImplementation((command, eventName, params) => {
      expect(command).toBe('event')
      expect(eventName).toBe('sign_up')
      params.event_callback()
    })

    await expect(trackEventOnceAndWait('sign_up', 'handoff-key', { method: 'email' })).resolves.toBe(true)
    await expect(trackEventOnceAndWait('sign_up', 'handoff-key', { method: 'email' })).resolves.toBe(false)
    expect(window.gtag).toHaveBeenCalledTimes(1)
  })

  it('rejects unregistered event names', () => {
    expect(trackEvent('custom_private_event', { source: 'home' })).toBe(false)
    expect(window.gtag).not.toHaveBeenCalled()
  })

  it('uses the previous sanitized SPA location as the next page referrer', () => {
    expect(trackPageView('/features?email=private@example.test')).toBe(true)
    expect(trackPageView('/pricing?billing=yearly')).toBe(true)

    expect(window.gtag).toHaveBeenLastCalledWith('event', 'page_view', {
      page_location: 'http://localhost/pricing?billing=yearly',
      page_path: '/pricing?billing=yearly',
      page_referrer: 'http://localhost/features',
    })
  })

  it('installs the analytics transport before direct-load component events run', () => {
    window.history.replaceState({}, '', '/reset-password/private-token?email=private%40example.test&plan=earlybird')
    delete window.gtag
    window.dataLayer = []

    initGA('G-TEST123')

    expect(typeof window.gtag).toBe('function')
    expect(trackEvent('pricing_view', { source: 'pricing_page' })).toBe(true)
    expect(window.dataLayer.some(args => args[0] === 'event' && args[1] === 'pricing_view')).toBe(true)
    expect(window.dataLayer.some(args => args[0] === 'config'
      && args[2].page_location === 'http://localhost/reset-password/:token?plan=earlybird')).toBe(true)
    expect(window.dataLayer.some(args => args[0] === 'event'
      && args[1] === 'pricing_view'
      && args[2].page_path === '/reset-password/:token?plan=earlybird')).toBe(true)
  })
})
