let ready = false;
let lastPath = null;

export function initGA(gaId) {
  if (!gaId || typeof window === 'undefined' || ready) return;
  ready = true;

  const s1 = document.createElement('script');
  s1.id = 'ga4-script';
  s1.async = true;
  s1.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(gaId)}`;
  document.head.appendChild(s1);

  const s2 = document.createElement('script');
  s2.id = 'ga4-inline';
  s2.innerHTML = `
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    window.gtag = gtag;
    gtag('js', new Date());
    gtag('config', '${gaId}', { send_page_view: false });
  `;
  document.head.appendChild(s2);
}

export function trackPageView(url) {
  if (typeof window === 'undefined') return;
  if (typeof window.gtag !== 'function') {
    console.log('[GA] skipped page_view (gtag not ready)', url);
    return;
  }
  if (url === lastPath) {
    console.log('[GA] skipped duplicate page_view', url);
    return;
  }
  lastPath = url
  console.log('[GA] page_view', url);
  window.gtag('event', 'page_view', {
    page_location: window.location.href,
    page_path: url,
    page_title: document.title,
  });
}
