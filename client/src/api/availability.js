import http from './http.js'

/**
 * q is the medicine name. lat and lng are sent only when the person pressed "Use my location"; they are used for this
 * one request and never stored by the client (not in the URL of the page, not in storage).
 */
export function searchAvailability({ q, medicineId, lat, lng, limit = 10 }, { signal } = {}) {
  // a name search, or all the branches of one medicine (used when a reserve link only knows the ids)
  const params = medicineId ? { medicine_id: medicineId, limit } : { q, limit }
  if (lat !== undefined && lng !== undefined) {
    params.lat = lat
    params.lng = lng
  }
  return http.get('/availability', { params, signal })
}
