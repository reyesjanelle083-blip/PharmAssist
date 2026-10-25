import http from './http.js'

export function getMedicines({ q, category, rx, page, per_page } = {}, { signal } = {}) {
  return http.get('/medicines', { params: { q, category, rx, page, per_page }, signal })
}
