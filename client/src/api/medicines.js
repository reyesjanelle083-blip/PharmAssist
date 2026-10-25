import http from './http.js'

export function getMedicines({ q, category, rx, page, per_page } = {}, { signal } = {}) {
  return http.get('/medicines', { params: { q, category, rx, page, per_page }, signal })
}

/** The distinct categories in the catalog, for the search filter. */
export function getMedicineCategories({ signal } = {}) {
  return http.get('/medicines/categories', { signal })
}

export function getMedicine(id, { signal } = {}) {
  return http.get(`/medicines/${id}`, { signal })
}

/** Plain-language counseling text stored with the medicine. summary can be null when none is stored. */
export function getMedicineCounseling(id, { signal } = {}) {
  return http.get(`/medicines/${id}/counseling`, { signal })
}
