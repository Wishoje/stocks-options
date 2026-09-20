import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { buildHomeFaqs, featureFaqs, featureGroups, homePreviews, productMedia } from '@/Support/marketing-content'

describe('public marketing content registry', () => {
  it('covers all delivered product areas and the required EOD and intraday tabs', () => {
    const ids = featureGroups.flatMap(group => group.features.map(feature => feature.id))
    expect(ids).toEqual(expect.arrayContaining([
      'eod-overview', 'eod-positioning', 'eod-volatility', 'eod-unusual-activity', 'eod-strikes',
      'intraday-live-flow', 'intraday-live-strikes', 'watchlist', 'volume-scanner', 'wall-scanner',
      'options-calculator', 'ai-export',
    ]))
    expect(new Set(ids).size).toBe(ids.length)
    expect(homePreviews.map(preview => preview.id)).toEqual([
      'put-call-pricing',
      'dealer-positioning',
      'intraday-flow',
      'scanner',
      'calculator-outcomes',
    ])
    expect(homePreviews.map(preview => preview.media.id)).toEqual([
      'eod-skew-pricing-spy',
      'eod-positioning-spy',
      'intraday-flow-spy',
      'scanner-walls',
      'calculator-outcomes-spy',
    ])
  })

  it('requires every product image to be either explicitly pending or fully described as ready', () => {
    for (const media of Object.values(productMedia)) {
      expect(['pending_capture', 'ready']).toContain(media.status)
      expect(media.requiredContext.length).toBeGreaterThan(0)
      if (media.status === 'pending_capture') {
        expect(media.desktop).toBeNull()
        expect(media.mobile).toBeNull()
        expect(media.alt).toBe('')
      } else {
        expect(media.desktop).toMatchObject({ src: expect.any(String), width: expect.any(Number), height: expect.any(Number) })
        expect(media.mobile).toMatchObject({ src: expect.any(String), width: expect.any(Number), height: expect.any(Number) })
        expect(media.alt.trim()).not.toBe('')
        expect(media.caption.toLowerCase()).toContain('recorded')
      }
    }
  })

  it('keeps the capture manifest aligned with the runtime media registry', () => {
    const manifest = JSON.parse(readFileSync(
      resolve(process.cwd(), 'docs/ui-refresh/batch-9/product-media-manifest.json'),
      'utf8',
    ))
    expect(manifest.assets.map(asset => asset.id).sort()).toEqual(
      Object.values(productMedia).map(media => media.id).sort(),
    )
    for (const asset of manifest.assets) {
      const runtime = Object.values(productMedia).find(media => media.id === asset.id)
      expect(asset.placements).toEqual(runtime.placements)
      expect(asset.required_context.map(value => value.toLowerCase())).toEqual(
        runtime.requiredContext.map(value => value.toLowerCase()),
      )
      expect(asset.status).toBe(runtime.status)
    }
    expect(manifest.capture_policy.synthetic_product_images_allowed).toBe(false)
    expect(manifest.capture_policy.legacy_images_allowed_as_current).toBe(false)
  })

  it('avoids false cadence, universal coverage, alert, and entitlement claims', () => {
    const publicCopy = JSON.stringify({ featureGroups, homeFaqs: buildHomeFaqs(7), featureFaqs }).toLowerCase()
    expect(publicCopy).not.toContain('once per minute')
    expect(publicCopy).not.toContain('1-minute')
    expect(publicCopy).not.toContain('all symbols')
    expect(publicCopy).not.toContain('pin alerts')
    expect(publicCopy).toContain('snapshot-based')
    expect(publicCopy).toContain('supported us-listed optionable stocks and etfs')
    expect(publicCopy).toContain('not presented here as a general subscriber entitlement')
  })
})
