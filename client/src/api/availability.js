import http from './http.js'

/**
 * q is the medicine name. lat and lng are sent only when the person pressed "Use my location"; they are used for this
 * one request and never stored by the client (not in the URL of the page, not in storage). category and rx narrow the
 * search (the gateway needs at least one of medicine_id, q or category; rx alone is not enough).
 */
export function searchAvailability({ q, medicineId, category, rx, lat, lng, limit = 10 }, { signal } = {}) {
  // a name search, all the branches of one medicine (used when a reserve link only knows the ids), or a category alone
  const params = medicineId ? { medicine_id: medicineId, limit } : { limit }
  if (!medicineId && q) params.q = q
  if (category) params.category = category
  if (rx !== undefined && rx !== '') params.rx = rx
  if (lat !== undefined && lng !== undefined) {
    params.lat = lat
    params.lng = lng
  }
  return http.get('/availability', { params, signal })
}
