import { describe, expect, it } from 'vitest'

import { formatDocumentTitle } from '@/Support/document-title'

describe('document title formatting', () => {
  it('keeps established Home and Features titles single-branded', () => {
    expect(formatDocumentTitle(
      'GexOptions - Premarket Levels, Positioning, and Options Flow',
      'GEXoptions',
    )).toBe('GexOptions - Premarket Levels, Positioning, and Options Flow')
    expect(formatDocumentTitle(
      'GexOptions Features - Flow, GEX Levels, DEX, Scanners, VRP & Term Structure',
      'GEXoptions',
    )).toBe('GexOptions Features - Flow, GEX Levels, DEX, Scanners, VRP & Term Structure')
    expect(formatDocumentTitle('GEX Options Pricing', 'GEXoptions')).toBe('GEX Options Pricing')
  })

  it('adds the application name to unbranded titles such as Login', () => {
    expect(formatDocumentTitle('Log in', 'GEXoptions')).toBe('Log in - GEXoptions')
  })
})
