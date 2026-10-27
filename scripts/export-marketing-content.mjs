import { mkdir, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { buildPublicMarketingContent } from '../resources/js/Support/marketing-content.js'

const destination = new URL('../resources/marketing/', import.meta.url)
await mkdir(destination, { recursive: true })
await writeFile(new URL('public-content.json', destination), `${JSON.stringify(buildPublicMarketingContent(), null, 2)}\n`)
console.log(`Public marketing HTML content exported to ${fileURLToPath(destination)}.`)

// Content versions are committed with the pages. Unchanged builds retain their
// dates; checkout mtimes and unrelated deployments never advance lastmod.
await import('./export-sitemap-content.mjs')
