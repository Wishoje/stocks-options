import { createHash } from 'node:crypto'
import { execFile } from 'node:child_process'
import { access, readFile, readdir, stat } from 'node:fs/promises'
import { basename, relative, resolve, sep } from 'node:path'
import { promisify } from 'node:util'
import { fileURLToPath } from 'node:url'
import { productMedia } from '../resources/js/Support/marketing-content.js'
import { isAllowedEventParam } from '../resources/js/lib/ga.js'

const root = resolve(fileURLToPath(new URL('..', import.meta.url)))
const releaseMode = process.argv.includes('--release')
const findings = []
const execFileAsync = promisify(execFile)

const add = (severity, check, detail) => findings.push({ severity, check, detail })
const source = async path => readFile(resolve(root, path), 'utf8')
const exists = async path => access(resolve(root, path)).then(() => true, () => false)
const filesBelow = async (directory, extensions) => {
  const found = []
  const visit = async current => {
    for (const entry of await readdir(resolve(root, current), { withFileTypes: true })) {
      const child = `${current}/${entry.name}`
      if (entry.isDirectory()) await visit(child)
      else if (extensions.some(extension => entry.name.endsWith(extension))) found.push(child)
    }
  }
  await visit(directory)
  return found
}
const expectedCaptureIds = Object.freeze([
  'eod-overview-spy-2w',
  'eod-positioning-spy',
  'eod-expiry-pressure-spy',
  'eod-skew-pricing-spy',
  'eod-volatility-spy',
  'eod-volatility-vrp-spy',
  'eod-volatility-seasonality-spy',
  'eod-ua-spy',
  'eod-ua-results-spy',
  'eod-strikes-spy-2w',
  'eod-strikes-volume-change-spy',
  'eod-strikes-oi-change-spy',
  'intraday-flow-spy',
  'intraday-strikes-spy',
  'intraday-strikes-put-call-spy',
  'intraday-strikes-premium-spy',
  'watchlist-navigation',
  'scanner-volume',
  'scanner-walls',
  'calculator-spy',
  'calculator-outcomes-spy',
  'ai-export',
])

function webpDimensions(buffer) {
  if (buffer.length < 30 || buffer.toString('ascii', 0, 4) !== 'RIFF' || buffer.toString('ascii', 8, 12) !== 'WEBP') {
    return null
  }

  const chunk = buffer.toString('ascii', 12, 16)
  if (chunk === 'VP8X') {
    return {
      width: 1 + buffer.readUIntLE(24, 3),
      height: 1 + buffer.readUIntLE(27, 3),
    }
  }
  if (chunk === 'VP8 ' && buffer.toString('hex', 23, 26) === '9d012a') {
    return {
      width: buffer.readUInt16LE(26) & 0x3fff,
      height: buffer.readUInt16LE(28) & 0x3fff,
    }
  }
  if (chunk === 'VP8L' && buffer[20] === 0x2f) {
    const bits = buffer.readUInt32LE(21)
    return {
      width: 1 + (bits & 0x3fff),
      height: 1 + ((bits >> 14) & 0x3fff),
    }
  }

  return null
}

const routes = await source('routes/web.php')
const publicRoutes = [
  ["Route::get('/',", '/'],
  ["Route::get('/features'", '/features'],
  ["Route::get('/pricing'", '/pricing'],
  ["Route::get('/contact'", '/contact'],
  ["Route::get('/terms-of-service'", '/terms-of-service'],
  ["Route::get('/privacy-policy'", '/privacy-policy'],
]

for (const [needle, route] of publicRoutes) {
  if (!routes.includes(needle)) add('error', 'public-route', `${route} is not registered in routes/web.php`)
}

const publicFiles = [
  'resources/js/Layouts/MarketingLayout.vue',
  'resources/js/Components/AuthenticationCard.vue',
  'resources/js/Components/AuthenticationCardLogo.vue',
  'resources/js/Pages/TermsOfService.vue',
  'resources/js/Pages/PrivacyPolicy.vue',
  'resources/js/Support/marketing-content.js',
  'resources/markdown/terms.md',
  'resources/markdown/policy.md',
  ...await filesBelow('resources/js/Components/Marketing', ['.vue', '.js']),
  ...await filesBelow('resources/js/Pages/Marketing', ['.vue', '.js']),
  ...await filesBelow('resources/js/Pages/Auth', ['.vue', '.js']),
]
const uniquePublicFiles = [...new Set(publicFiles)]
const publicSource = (await Promise.all(uniquePublicFiles.map(source))).join('\n')

if (publicSource.includes('support@gexlevels.com')) {
  add('error', 'support-address', 'A public link still points to support@gexlevels.com')
}
if (!publicSource.includes('support@gexoptions.com')) {
  add('error', 'support-address', 'The current support@gexoptions.com destination is missing')
}

const unsupportedClaims = [
  [/\bonce per minute\b/i, 'once-per-minute refresh'],
  [/\ball symbols\b/i, 'unlimited symbol coverage'],
  [/\bprice increases later\b/i, 'unverified future price increase'],
  [/\b1[- ]minute\b/i, 'one-minute refresh'],
]
for (const [pattern, label] of unsupportedClaims) {
  if (pattern.test(publicSource)) add('error', 'public-claim', `Public copy contains the unsupported ${label} claim`)
}

const legacyAssets = [
  'calculator.png', 'dex.png', 'gex.png', 'ivSkew.png', 'liveFlow.png',
  'oiAndVolChart.png', 'oiByStrike.png', 'overviewNav.jpg', 'pcr.png', 'pin.png', 'premiumLive.png',
  'qscore.png', 'risk.png', 'scanner.png', 'seasonality.png', 'term.png',
  'terminal_scanner.png', 'ua.png', 'volBYStrike.png', 'volOiByStrike.png',
  'watchlist.png', 'watchlist-2.png', 'watchlist-3.png', 'watchlist-4.png',
]
const publicTemplates = (await Promise.all(uniquePublicFiles.filter(path => path.endsWith('.vue')).map(source))).join('\n')
for (const tag of publicTemplates.matchAll(/<MarketingCta\b[^>]*>/g)) {
  for (const key of ['source', 'location']) {
    const value = tag[0].match(new RegExp(`\\b${key}="([^"]+)"`))?.[1]
    if (value && !isAllowedEventParam(key, value)) {
      add('error', 'analytics-contract', `MarketingCta ${key}="${value}" is not in the fixed analytics value contract`)
    }
  }
}
for (const asset of legacyAssets) {
  if (publicTemplates.includes(asset)) add('error', 'legacy-product-media', `${asset} is still referenced by a public template`)
}

const manifest = JSON.parse(await source('docs/ui-refresh/batch-9/product-media-manifest.json'))
const manifestAssets = Array.isArray(manifest.assets) ? manifest.assets : []
const registryAssets = Object.values(productMedia)
const registryIds = registryAssets.map(media => media.id)
const manifestIds = manifestAssets.map(media => media.id)

if (!/^[a-f0-9]{40}$/i.test(manifest.source_revision || '')) {
  add('error', 'product-capture', 'Capture manifest source_revision must be a full Git SHA')
}
if (/^[a-f0-9]{40}$/i.test(manifest.source_revision || '')) {
  try {
    await execFileAsync('git', ['merge-base', '--is-ancestor', manifest.source_revision, 'HEAD'], { cwd: root })
    const auditedPaths = [
      'app/Http', 'app/Models', 'app/Support', 'bootstrap', 'config', 'database/migrations',
      'package.json', 'package-lock.json', 'resources/css', 'resources/js', 'resources/views',
      'routes', 'vite.config.js',
    ]
    const { stdout } = await execFileAsync(
      'git',
      ['diff', '--name-only', `${manifest.source_revision}..HEAD`, '--', ...auditedPaths],
      { cwd: root },
    )
    const changedAfterCapture = stdout.trim().split(/\r?\n/).filter(Boolean)
    if (changedAfterCapture.length) {
      add(
        releaseMode ? 'error' : 'warning',
        'product-capture',
        `UI/runtime files changed after the capture source revision: ${changedAfterCapture.slice(0, 8).join(', ')}${changedAfterCapture.length > 8 ? ', ...' : ''}`,
      )
    }

    if (releaseMode) {
      const { stdout: dirty } = await execFileAsync('git', ['status', '--porcelain'], { cwd: root })
      if (dirty.trim()) add('error', 'release-revision', 'The complete release worktree must be tracked, committed, and clean before the release audit')
    }
  } catch {
    add('error', 'product-capture', 'Capture source_revision is not an ancestor of the reviewed Git revision')
  }
}
if (manifest.capture_policy?.synthetic_product_images_allowed !== false
  || manifest.capture_policy?.legacy_images_allowed_as_current !== false) {
  add('error', 'product-capture', 'Capture manifest must prohibit synthetic and legacy current-product images')
}

for (const [collection, ids] of [['runtime registry', registryIds], ['capture manifest', manifestIds]]) {
  const duplicates = ids.filter((id, index) => ids.indexOf(id) !== index)
  if (duplicates.length) add('error', 'product-capture', `${collection} has duplicate IDs: ${[...new Set(duplicates)].join(', ')}`)
  for (const id of expectedCaptureIds) {
    if (!ids.includes(id)) add('error', 'product-capture', `${collection} is missing required ID ${id}`)
  }
  for (const id of ids) {
    if (!expectedCaptureIds.includes(id)) add('error', 'product-capture', `${collection} has unexpected ID ${id}`)
  }
}

const manifestLegacy = new Set(manifest.legacy_assets?.files || [])
for (const legacy of legacyAssets) {
  if (!manifestLegacy.has(legacy)) add('error', 'legacy-product-media', `${legacy} is missing from the manifest legacy inventory`)
}

const currentMediaRoot = resolve(root, 'public/marketing/current')
const plannedPaths = []
for (const media of registryAssets) {
  const manifestMedia = manifestAssets.find(item => item.id === media.id)
  if (!manifestMedia) continue
  if (!Array.isArray(manifestMedia.placements) || manifestMedia.placements.length === 0) {
    add('error', 'product-capture', `${media.id} has no manifest placements`)
  }
  if (!Array.isArray(manifestMedia.required_context) || manifestMedia.required_context.length === 0) {
    add('error', 'product-capture', `${media.id} has no required capture context`)
  }
  for (const variant of ['desktop', 'mobile']) {
    const planned = manifestMedia[variant]
    if (typeof planned !== 'string' || !planned.startsWith('public/marketing/current/') || planned.includes('..') || !planned.endsWith('.webp')) {
      add('error', 'product-capture', `${media.id} has an invalid planned ${variant} path`)
    } else {
      plannedPaths.push(planned)
    }
  }
  if (manifestMedia.status !== media.status) {
    add('error', 'product-capture', `${media.id} status differs between runtime registry and capture manifest`)
  }

  if (media.status === 'pending_capture') {
    add(releaseMode ? 'error' : 'warning', 'product-capture', `${media.id} is awaiting a verified capture`)
    continue
  }

  if (media.status !== 'ready') {
    add('error', 'product-capture', `${media.id} has unsupported status ${String(media.status)}`)
    continue
  }

  for (const variant of ['desktop', 'mobile']) {
    const asset = media[variant]
    const expectedManifestPath = manifestMedia[variant]
    if (!asset || typeof asset.src !== 'string') {
      add('error', 'product-capture', `${media.id} is missing its ${variant} asset metadata`)
      continue
    }
    if (!Number.isInteger(asset.width) || asset.width <= 0 || !Number.isInteger(asset.height) || asset.height <= 0) {
      add('error', 'product-capture', `${media.id} has invalid ${variant} dimensions`)
    }
    if (!asset.src.startsWith('/marketing/current/') || asset.src.includes('..') || !asset.src.endsWith('.webp')) {
      add('error', 'product-capture', `${media.id} has an unsafe or unoptimized ${variant} path`)
      continue
    }
    if (expectedManifestPath !== `public${asset.src}`) {
      add('error', 'product-capture', `${media.id} ${variant} path differs between runtime registry and manifest`)
    }
    if (manifestLegacy.has(basename(asset.src)) || legacyAssets.includes(basename(asset.src))) {
      add('error', 'legacy-product-media', `${media.id} points to legacy asset ${basename(asset.src)}`)
    }

    const absoluteAsset = resolve(root, `public${asset.src}`)
    const contained = relative(currentMediaRoot, absoluteAsset)
    if (contained.startsWith(`..${sep}`) || contained === '..' || contained.startsWith(sep)) {
      add('error', 'product-capture', `${media.id} ${variant} asset escapes public/marketing/current`)
      continue
    }
    if (!await exists(`public${asset.src}`)) {
      add('error', 'product-capture', `${media.id} is missing its ${variant} file`)
      continue
    }

    if (releaseMode) {
      try {
        await execFileAsync('git', ['ls-files', '--error-unmatch', `public${asset.src}`], { cwd: root })
      } catch {
        add('error', 'product-capture', `${media.id} ${variant} file is not tracked by Git`)
      }
    }

    const file = await readFile(absoluteAsset)
    const dimensions = webpDimensions(file)
    if (!dimensions || dimensions.width !== asset.width || dimensions.height !== asset.height) {
      add('error', 'product-capture', `${media.id} ${variant} file dimensions do not match its metadata`)
    }
    const fileSize = (await stat(absoluteAsset)).size
    const limit = variant === 'desktop' ? 1_000_000 : 600_000
    if (fileSize > limit) add('error', 'product-capture', `${media.id} ${variant} exceeds ${limit} bytes`)
  }
  if (!media.alt?.trim()) add('error', 'product-capture', `${media.id} is missing alt text`)
  if (!/recorded/i.test(media.caption || '')) add('error', 'product-capture', `${media.id} caption must identify the recorded example`)
}

const duplicatePaths = plannedPaths.filter((path, index) => plannedPaths.indexOf(path) !== index)
if (duplicatePaths.length) {
  add('error', 'product-capture', `Capture manifest reuses asset paths: ${[...new Set(duplicatePaths)].join(', ')}`)
}

const batch8Validation = JSON.parse(await source('docs/ui-refresh/batch-8/validation.json'))
const requiredBatch8Checks = [
  'account_settings',
  'account_deletion_safeguards',
  'authenticated_product_routes',
  'sanctum_dead_configuration',
  'first_use_measurement',
]
const incompleteBatch8Checks = requiredBatch8Checks.filter(check => batch8Validation.checks?.[check] !== 'verified')
const batch8ReleaseException = batch8Validation.release_exception
const ownerAcceptedBatch8 = batch8Validation.status === 'automated_verified_pending_manual_review'
  && requiredBatch8Checks.every(check => ['automated_verified', 'verified'].includes(batch8Validation.checks?.[check]))
  && /^\d{4}-\d{2}-\d{2}$/.test(batch8ReleaseException?.approved_on || '')
  && ['approved_by', 'scope', 'approval'].every(field => String(batch8ReleaseException?.[field] || '').trim())
if (batch8Validation.status !== 'verified' || incompleteBatch8Checks.length > 0) {
  add(
    releaseMode && !ownerAcceptedBatch8 ? 'error' : 'warning',
    'batch-8-validation',
    ownerAcceptedBatch8
      ? `Owner approved release on ${batch8ReleaseException.approved_on} with the manual checklist still pending; automated checks are verified`
      : `Batch 8 is not verified: ${incompleteBatch8Checks.join(', ') || 'overall status is pending'}`,
  )
} else {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(batch8Validation.verified_on || '')) {
    add('error', 'batch-8-validation', 'Verified Batch 8 evidence is missing a valid verified_on date')
  }
  if (!Array.isArray(batch8Validation.evidence) || batch8Validation.evidence.length === 0) {
    add('error', 'batch-8-validation', 'Verified Batch 8 evidence must list its contract and review evidence')
  }
}

const legalSources = {
  'resources/markdown/terms.md': '81211de3b5f420e46e9cfb1b3dc120c68a712543c4559b7f890fdae3c88abf53',
  'resources/markdown/policy.md': 'c6b01d17a747ad97fd7b395244280a36b622766338ffb62027d46b98ce6300db',
}
const legalApproval = JSON.parse(await source('docs/ui-refresh/batch-13/legal-approval.json'))
if (releaseMode) {
  for (const evidencePath of [
    'docs/ui-refresh/batch-9/product-media-manifest.json',
    'docs/ui-refresh/batch-8/validation.json',
    'docs/ui-refresh/batch-13/legal-approval.json',
    'resources/markdown/terms.md',
    'resources/markdown/policy.md',
    'scripts/ui-release-audit.mjs',
  ]) {
    try {
      await execFileAsync('git', ['ls-files', '--error-unmatch', evidencePath], { cwd: root })
    } catch {
      add('error', 'release-revision', `${evidencePath} is not tracked by Git`)
    }
  }
}
for (const [path, placeholderHash] of Object.entries(legalSources)) {
  const hash = createHash('sha256').update(await readFile(resolve(root, path))).digest('hex')
  if (hash === placeholderHash) {
    add(releaseMode ? 'error' : 'warning', 'legal-copy', `${path} still contains the preserved placeholder copy`)
    continue
  }

  const approval = legalApproval.documents?.[path]
  if (approval?.status === 'drafted_pending_owner_approval') {
    if (!/^[a-f0-9]{64}$/i.test(approval.sha256 || '') || approval.sha256.toLowerCase() !== hash) {
      add('error', 'legal-copy', `${path} does not match its recorded draft SHA-256 hash`)
    }
    if (!/^\d{4}-\d{2}-\d{2}$/.test(approval.drafted_on || '')) {
      add('error', 'legal-copy', `${path} draft is missing a valid drafted_on date`)
    }
    add(releaseMode ? 'error' : 'warning', 'legal-copy', `${path} is drafted and awaiting owner approval`)
    continue
  }
  if (approval?.status !== 'approved') {
    add(releaseMode ? 'error' : 'warning', 'legal-copy', `${path} has changed but has no recognized approval status`)
    continue
  }
  if (!/^[a-f0-9]{64}$/i.test(approval.sha256 || '') || approval.sha256.toLowerCase() !== hash) {
    add('error', 'legal-copy', `${path} does not match its approved SHA-256 hash`)
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(approval.approved_on || '') || !String(approval.approved_by || '').trim()) {
    add('error', 'legal-copy', `${path} approval is missing approved_on or approved_by`)
  }
}

const errors = findings.filter(finding => finding.severity === 'error')
const warnings = findings.filter(finding => finding.severity === 'warning')

console.log(JSON.stringify({
  mode: releaseMode ? 'release' : 'review',
  passed: errors.length === 0,
  errors: errors.length,
  warnings: warnings.length,
  findings,
}, null, 2))

if (errors.length > 0) process.exitCode = 1
