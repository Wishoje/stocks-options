import { readFile, writeFile } from 'node:fs/promises'
import { createHash } from 'node:crypto'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const root = fileURLToPath(new URL('../', import.meta.url))
const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')
const json = async path => JSON.parse(await read(path))
const destination = new URL('../resources/marketing/sitemap-content.json', import.meta.url)
const previous = await readFile(destination, 'utf8').then(JSON.parse).catch(error => {
  if (error.code === 'ENOENT') return {}
  throw error
})
const today = new Date().toISOString().slice(0, 10)
const output = {}
function sourceDate(paths) {
  try {
    const changed = execFileSync('git', ['status', '--porcelain', '--', ...paths], { cwd: root, encoding: 'utf8' }).trim()
    if (changed) return today
    const date = execFileSync('git', ['log', '-1', '--format=%cI', '--', ...paths], { cwd: root, encoding: 'utf8' }).trim().slice(0, 10)
    return /^\d{4}-\d{2}-\d{2}$/.test(date) && date <= today ? date : today
  } catch { return today }
}
async function add(path, content, paths, initialDate) {
  const sources = await Promise.all(paths.map(read))
  const hash = createHash('sha256').update(JSON.stringify([content, ...sources])).digest('hex')
  const prior = previous[path]
  const lastmod = prior?.hash === hash ? prior.lastmod : (prior ? today : initialDate || sourceDate(paths))
  output[path] = { lastmod, hash }
}
const pages = await json('resources/marketing/public-content.json')
await add('/', pages.home, ['resources/js/Pages/Marketing/Home.vue'])
await add('/pricing', await json('resources/marketing/pricing-content.json'), ['config/plans.php', 'resources/marketing/pricing-content.json'])
await add('/features', pages.features, ['resources/js/Pages/Marketing/Features.vue'])
const workflows = await json('resources/marketing/public-workflows.json')
for (const [route, path] of [['workflows.swing', '/swing-trading-ideas'], ['workflows.alerts', '/intraday-alerts'], ['about', '/about']]) {
  await add(path, workflows[route], [], today)
}
const guides = await json('resources/marketing/trading-guides.json')
await add('/guides', guides.map(({ slug, title, description, updated }) => ({ slug, title, description, updated })), [], guides.map(guide => guide.updated).sort().at(-1))
for (const guide of guides) await add(`/guides/${guide.slug}`, guide, [], guide.updated)
await add('/spy-gex-levels', await json('resources/marketing/spy-preview.json'), ['resources/js/Pages/Marketing/SpyPreview.vue'])
await add('/contact', null, ['resources/js/Pages/Marketing/Contact.vue'])
await add('/terms-of-service', null, ['resources/markdown/terms.md'])
await add('/privacy-policy', null, ['resources/markdown/policy.md'])
await writeFile(destination, `${JSON.stringify(output, null, 2)}\n`)
console.log(`Sitemap content versions prepared for ${Object.keys(output).length} public URLs.`)
