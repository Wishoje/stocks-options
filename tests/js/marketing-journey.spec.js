import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  journeyUrl,
  normalizePlanSelection,
  selectionForPage,
  selectionFromUrl,
  yearlySavings,
} from '../../resources/js/Support/marketing-journey.js'
import { initGA, isAllowedEventParam, isStripeCheckoutReferrer, sanitizeEventParams, sanitizePageUrl, sanitizeReferrer, trackEvent, trackEventOnce, trackEventOnceAndWait, trackPageView } from '../../resources/js/lib/ga.js'

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
    })).toEqual({ billing: 'yearly', event_origin: 'pricing_page' })

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
    expect(isAllowedEventParam('event_origin', 'marketing_nav')).toBe(true)
  })

  it('keeps internal event placement separate from acquisition source on the wire', () => {
    expect(trackEvent('pricing_view', { source: 'pricing_page' })).toBe(true)
    const payload = window.gtag.mock.calls[0][2]
    expect(payload.event_origin).toBe('pricing_page')
    expect(payload).not.toHaveProperty('source')
    expect(payload).not.toHaveProperty('campaign_source')
    expect(sanitizeEventParams({ event_origin: 'home', source: 'pricing_page' })).toEqual({ event_origin: 'home' })
    expect(sanitizeEventParams({ source: 'pricing_page', event_origin: 'home' })).toEqual({ event_origin: 'home' })
    expect(sanitizeEventParams({ event_origin: 'private@example.test' })).toEqual({})
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

  it('retains known email attribution while grouping account and billing details', () => {
    for (const campaign of ['welcome_signup', 'finish_checkout_1h', 'finish_checkout_24h']) {
      const safe = sanitizePageUrl(`/pricing?utm_source=email&utm_medium=lifecycle&utm_campaign=${campaign}&email=private@example.test`)
      expect(safe.pagePath).toBe(`/pricing?utm_source=email&utm_medium=lifecycle&utm_campaign=${campaign}`)
    }
    for (const campaign of ['trial_started', 'payment_failed_1', 'payment_failed_2', 'payment_failed_3', 'cancellation_confirmation', 'winback_7d']) {
      expect(sanitizePageUrl(`/user/profile?utm_source=email&utm_medium=lifecycle&utm_campaign=${campaign}`).pagePath)
        .toBe('/user/profile?utm_source=email&utm_medium=lifecycle&utm_campaign=account_updates')
    }
    for (const query of [
      'utm_source=email&utm_medium=lifecycle&utm_campaign=private@example.test',
      'utm_source=google&utm_medium=organic&utm_campaign=welcome_signup',
      'utm_source=email&utm_medium=cpc&utm_campaign=payment_failed_1',
    ]) expect(sanitizePageUrl(`/pricing?${query}`).pagePath).not.toContain('utm_campaign')
  })

  it('retains only the existing public social campaign labels without arbitrary content', () => {
    for (const symbol of ['spy', 'qqq', 'tsla']) {
      expect(sanitizePageUrl(`/?utm_source=x&utm_medium=social&utm_campaign=daily_gex&utm_content=${symbol}`).pagePath)
        .toBe(`/?utm_source=x&utm_medium=social&utm_campaign=daily_gex&utm_content=${symbol}`)
    }
    for (const query of [
      'utm_source=x&utm_medium=social&utm_campaign=daily_gex&utm_content=private@example.test',
      'utm_source=email&utm_medium=lifecycle&utm_campaign=daily_gex&utm_content=spy',
      'utm_source=x&utm_medium=social&utm_campaign=unknown&utm_content=spy',
      'utm_source=x&utm_medium=referral&utm_campaign=daily_gex&utm_content=spy',
    ]) expect(sanitizePageUrl(`/?${query}`).pagePath).not.toContain('utm_content')
  })

  it('recognizes only the HTTPS hosted Stripe checkout origin as a processor return', () => {
    expect(isStripeCheckoutReferrer('https://checkout.stripe.com/c/pay/cs_private?token=private')).toBe(true)
    expect(isStripeCheckoutReferrer('https://checkout.stripe.com:443/')).toBe(true)
    for (const referrer of ['', 'not a URL', 'https://www.google.com/search?q=gex', 'https://gexoptions.com/pricing', 'http://checkout.stripe.com/', 'https://checkout.stripe.com.evil.test/', 'https://stripe.com/', 'https://checkout.stripe.com@evil.test/']) {
      expect(isStripeCheckoutReferrer(referrer)).toBe(false)
    }
  })

  it.each(['https://checkout.stripe.com/c/pay/cs_private', '', 'https://www.google.com/search?q=gex', 'https://checkout.stripe.com.evil.test/'])('ignores a referral only on a genuine processor return: %s', async referrer => {
    vi.resetModules()
    const referrerGetter = vi.spyOn(document, 'referrer', 'get').mockReturnValue(referrer)
    try {
      const freshGA = await import('../../resources/js/lib/ga.js')
      window.gtag = vi.fn()
      freshGA.initGA('G-REFERRERTEST')
      const config = window.gtag.mock.calls.find(args => args[0] === 'config')[2]
      if (referrer.startsWith('https://checkout.stripe.com/')) expect(config.ignore_referrer).toBe(true)
      else expect(config).not.toHaveProperty('ignore_referrer')
      expect(config.page_referrer).not.toContain('cs_private')
    } finally { referrerGetter.mockRestore() }
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
