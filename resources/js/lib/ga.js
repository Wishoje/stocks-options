let ready = false;
let lastPath = null;
let lastPageLocation = null;
let activePageReferrer = null;
const sentMarkers = new Set();

const blockedParameter = /(email|name|account|user|symbol|ticker|strike|expir|watchlist|message|token|session|card|payment|password|secret)/i;
const allowedEventNames = new Set([
  'checkout_activating',
  'checkout_canceled',
  'checkout_start',
  'first_useful_reading',
  'hero_cta_click',
  'plan_select',
  'pricing_view',
  'product_preview_select',
  'register_complete',
  'register_start',
  'sign_up',
  'subscription_activation_confirmed',
]);
const allowedParameterValues = Object.freeze({
  source: new Set(['features', 'home', 'marketing', 'marketing_nav', 'pricing_page', 'register_page']),
  location: new Set(['features_final', 'features_hero', 'home_final', 'home_hero', 'home_offer', 'marketing_mobile_nav', 'marketing_nav']),
  destination: new Set(['checkout', 'dashboard', 'register']),
  plan: new Set(['earlybird']),
  billing: new Set(['monthly', 'yearly']),
  next_step: new Set(['checkout', 'dashboard', 'register']),
  method: new Set(['email']),
  state: new Set(['confirmed', 'eod-context', 'intraday-context', 'pending', 'ready', 'scan-and-model']),
  surface: new Set(['dashboard', 'home_workflow', 'pricing']),
});

export function isAllowedEventParam(key, value) {
  return typeof value === 'string' && allowedParameterValues[key]?.has(value) === true;
}
const retainedQueryValues = Object.freeze({
  utm_source: new Set(['bing', 'facebook', 'google', 'instagram', 'linkedin', 'newsletter', 'partner', 'reddit', 'x']),
  utm_medium: new Set(['cpc', 'email', 'organic', 'referral', 'social']),
  plan: new Set(['earlybird']),
  billing: new Set(['monthly', 'yearly']),
  canceled: new Set(['1']),
  activating: new Set(['1']),
  welcome: new Set(['1']),
});

export function sanitizeEventParams(params = {}) {
  return Object.fromEntries(
    Object.entries(params).flatMap(([key, value]) => {
      const allowedValues = allowedParameterValues[key];
      if (blockedParameter.test(key) || !allowedValues || !isAllowedEventParam(key, value)) return [];
      return [[key, value]];
    }),
  );
}

function eventPayload(params = {}) {
  const safePage = sanitizePageUrl(window.location.pathname + window.location.search);
  const safeReferrer = activePageReferrer ?? sanitizeReferrer(document.referrer);

  return {
    ...sanitizeEventParams(params),
    page_location: safePage.pageLocation,
    page_path: safePage.pagePath,
    page_referrer: safeReferrer,
  };
}

function onceMarker(name, dedupeKey) {
  const safeKey = String(dedupeKey || 'default').replace(/[^a-z0-9_-]/gi, '').slice(0, 80) || 'default';
  return `gex.analytics.${name}.${safeKey}`;
}

function markerStorage(storage) {
  return storage === 'local' ? window.localStorage : window.sessionStorage;
}

export function sanitizePageUrl(input, origin = window.location.origin) {
  const parsed = new URL(input || '/', origin);
  const retained = new URLSearchParams();
  const pathname = parsed.pathname
    .replace(/^\/reset-password\/[^/]+/i, '/reset-password/:token')
    .replace(/^\/email\/verify\/[^/]+\/[^/]+/i, '/email/verify/:id/:hash')
    .replace(/\/\d+(?=\/|$)/g, '/:id');

  for (const [key, value] of parsed.searchParams.entries()) {
    const allowedValues = retainedQueryValues[key];
    const normalized = String(value).toLowerCase();
    if (!allowedValues?.has(normalized)) continue;
    retained.set(key, normalized);
  }

  const query = retained.toString();
  const pagePath = `${pathname}${query ? `?${query}` : ''}`;

  return {
    pagePath,
    pageLocation: `${origin}${pagePath}`,
  };
}

export function sanitizeReferrer(input, currentOrigin = window.location.origin) {
  if (!input) return '';

  try {
    const parsed = new URL(input, currentOrigin);
    if (parsed.origin !== currentOrigin) return `${parsed.origin}/`;
    return sanitizePageUrl(parsed.href, currentOrigin).pageLocation;
  } catch {
    return '';
  }
}

export function initGA(gaId) {
  if (!gaId || typeof window === 'undefined' || ready) return;
  ready = true;

  const s1 = document.createElement('script');
  s1.id = 'ga4-script';
  s1.async = true;
  s1.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(gaId)}`;
  document.head.appendChild(s1);

  window.dataLayer = window.dataLayer || [];
  window.gtag = window.gtag || function gtag() {
    window.dataLayer.push(arguments);
  };
  window.gtag('js', new Date());
  const safePage = sanitizePageUrl(window.location.pathname + window.location.search);
  const safeReferrer = sanitizeReferrer(document.referrer);
  window.gtag('config', gaId, {
    send_page_view: false,
    page_location: safePage.pageLocation,
    page_path: safePage.pagePath,
    page_referrer: safeReferrer,
  });
}

export function trackPageView(url) {
  if (typeof window === 'undefined') return;
  if (typeof window.gtag !== 'function') {
    return false;
  }
  const safePage = sanitizePageUrl(url);
  if (safePage.pagePath === lastPath) {
    return false;
  }
  const pageReferrer = lastPageLocation || sanitizeReferrer(document.referrer);
  lastPath = safePage.pagePath;
  activePageReferrer = pageReferrer;
  lastPageLocation = safePage.pageLocation;
  window.gtag('event', 'page_view', {
    page_location: safePage.pageLocation,
    page_path: safePage.pagePath,
    page_referrer: pageReferrer,
  });
  return true;
}

export function trackEvent(name, params = {}) {
  if (typeof window === 'undefined') return;
  if (!allowedEventNames.has(name) || typeof window.gtag !== 'function') {
    return false;
  }

  window.gtag('event', name, eventPayload(params));
  return true;
}

export function trackEventOnce(name, dedupeKey, params = {}, storage = 'session') {
  if (typeof window === 'undefined') return false;

  const marker = onceMarker(name, dedupeKey);
  if (sentMarkers.has(marker)) return false;

  let target = null;
  try {
    target = markerStorage(storage);
    if (target.getItem(marker) === '1') return false;
  } catch {
    // Storage may be unavailable in privacy modes; process memory still deduplicates.
  }

  if (!trackEvent(name, params)) return false;
  sentMarkers.add(marker);

  try {
    target?.setItem(marker, '1');
  } catch {
    // The event has already been sent; storage failure must not repeat it here.
  }

  return true;
}

export function trackEventOnceAndWait(name, dedupeKey, params = {}, timeoutMs = 800, storage = 'session') {
  if (typeof window === 'undefined' || !allowedEventNames.has(name) || typeof window.gtag !== 'function') {
    return Promise.resolve(false);
  }

  const marker = onceMarker(name, dedupeKey);
  if (sentMarkers.has(marker)) return Promise.resolve(false);

  let target = null;
  try {
    target = markerStorage(storage);
    if (target.getItem(marker) === '1') return Promise.resolve(false);
  } catch {
    // Storage may be unavailable in privacy modes; process memory still deduplicates.
  }

  const safeTimeout = Math.min(2000, Math.max(100, Number(timeoutMs) || 800));
  sentMarkers.add(marker);
  try {
    target?.setItem(marker, '1');
  } catch {
    // The in-memory marker remains authoritative for this page.
  }

  return new Promise(resolve => {
    let settled = false;
    const finish = value => {
      if (settled) return;
      settled = true;
      window.clearTimeout(timer);
      resolve(value);
    };
    const timer = window.setTimeout(() => finish(true), safeTimeout);

    try {
      window.gtag('event', name, {
        ...eventPayload(params),
        event_callback: () => finish(true),
        event_timeout: safeTimeout,
      });
    } catch {
      finish(false);
    }
  });
}
