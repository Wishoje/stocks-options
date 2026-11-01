import { createHash } from 'node:crypto'

const digest = (content, sources) => createHash('sha256').update(JSON.stringify([content, ...sources])).digest('hex')
const normalize = source => source.replace(/\r\n/g, '\n')

/** Recognize old checkout-specific hashes once, then save the normalized hash. */
function matchesLegacyHash(content, sources, hash) {
  let combinations = [[]]
  for (const source of sources) {
    const lineEndings = [...new Set([source, source.replace(/\n/g, '\r\n')])]
    combinations = combinations.flatMap(prefix => lineEndings.map(value => [...prefix, value]))
  }
  return combinations.some(values => digest(content, values) === hash)
}

export function sitemapContentVersion({ content, sources = [], previous, today, initialDate }) {
  const normalized = sources.map(normalize)
  const hash = digest(content, normalized)
  const unchanged = previous && (previous.hash === hash || matchesLegacyHash(content, normalized, previous.hash))

  return {
    lastmod: unchanged ? previous.lastmod : (previous ? today : initialDate || today),
    hash,
  }
}
