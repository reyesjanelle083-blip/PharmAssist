import http from './http.js'

/**
 * q is the medicine name. lat and lng are sent only when the person pressed "Use my location"; they are used for this
 * one request and never stored by the client (not in the URL of the page, not in storage).
 */
export function searchAvailability({ q, lat, lng, limit = 10 }, { signal } = {}) {
  const params = { q, limit }
  if (lat !== undefined && lng !== undefined) {
    params.lat = lat
    params.lng = lng
  }
  return http.get('/availability', { params, signal })
}
