const gexOptionsBrand = /\bgex\s*options\b/i

export function formatDocumentTitle(title, appName) {
    const pageTitle = String(title ?? '').trim()
    const applicationName = String(appName ?? '').trim()

    if (!pageTitle) return applicationName
    if (!applicationName || gexOptionsBrand.test(pageTitle)) return pageTitle

    return `${pageTitle} - ${applicationName}`
}
