import { createHash } from 'node:crypto'
import { describe, expect, it } from 'vitest'
import { sitemapContentVersion } from '../../scripts/lib/sitemap-content-version.mjs'

const today = '2026-11-01'
const oldDate = '2026-10-27'
const content = { title: 'Gamma exposure levels', description: 'Recorded options analysis.' }
const sources = ['<template>\n  <h1>Gamma exposure</h1>\n</template>\n', '<?php\nreturn [\'enabled\' => true];\n']
const crlf = source => source.replace(/\n/g, '\r\n')
const legacy = files => ({ lastmod: oldDate, hash: createHash('sha256').update(JSON.stringify([content, ...files])).digest('hex') })
const version = overrides => sitemapContentVersion({ content, sources, today, ...overrides })

describe('sitemap content version dates', () => {
  it('keeps the same hash and modification date across LF and CRLF builds', () => {
    const linux = version({ initialDate: oldDate })
    const windows = version({ sources: sources.map(crlf), previous: linux, today: '2026-11-03' })
    expect(windows).toEqual(linux)
    expect(version({ previous: windows, today: '2026-11-04' })).toEqual(linux)
  })

  it.each([
    ['LF', sources],
    ['CRLF', sources.map(crlf)],
    ['mixed source files', [crlf(sources[0]), sources[1]]],
  ])('preserves the date when migrating a legacy %s hash on Linux', (_, files) => {
    const actual = version({ previous: legacy(files) })
    expect(actual.lastmod).toBe(oldDate)
    expect(actual.hash).toBe(legacy(sources).hash)
    expect(version({ sources: sources.map(crlf), previous: actual, today: '2026-11-07' })).toEqual(actual)
  })

  it('updates the date for changed public content and preserves it on later rebuilds', () => {
    const changed = { ...content, description: 'New content about intraday alerts.' }
    const actual = version({ content: changed, previous: legacy(sources.map(crlf)) })
    expect(actual.lastmod).toBe(today)
    expect(actual.hash).not.toBe(legacy(sources).hash)
    expect(version({ content: changed, previous: actual, sources: sources.map(crlf), today: '2026-11-05' })).toEqual(actual)
  })

  it('updates the date for an actual template or calculation source change', () => {
    const changedSources = [sources[0].replace('Gamma exposure', 'Options analysis'), sources[1]]
    const actual = version({ sources: changedSources, previous: legacy(sources) })
    expect(actual.lastmod).toBe(today)
    expect(actual.hash).not.toBe(legacy(sources).hash)
  })

  it('uses the supplied publication date for a newly added route', () => {
    expect(version({ initialDate: '2026-10-24' }).lastmod).toBe('2026-10-24')
    expect(version({}).lastmod).toBe(today)
  })

  it('preserves dates for JSON-only pages without source files', () => {
    const first = version({ sources: [], initialDate: oldDate })
    expect(version({ sources: [], previous: first, today: '2026-11-10' })).toEqual(first)
    expect(version({ sources: [], content: { ...content, title: 'A changed title' }, previous: first }).lastmod).toBe(today)
  })
})
