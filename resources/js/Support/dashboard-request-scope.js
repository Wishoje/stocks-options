// Share transport without allowing an old, cancelled request's finally block
// to remove a replacement request registered under the same key.
export function coalesceDashboardRequest(inflight, key, send) {
  const existing = inflight.get(key)
  if (existing) return existing
  const request = Promise.resolve().then(send).finally(() => {
    if (inflight.get(key) === request) inflight.delete(key)
  })
  inflight.set(key, request)
  return request
}
