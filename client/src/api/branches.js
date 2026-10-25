import http from './http.js'

/** GET /branches (public): the active branches with their coordinates. The envelope is unwrapped like every other call. */
export function getBranches({ signal } = {}) {
  return http.get('/branches', { signal })
}
