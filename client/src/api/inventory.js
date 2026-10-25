import http from './http.js'

/** GET /branches/{id}/inventory (staff only; the gateway and inventory-service enforce role and branch). One page of stock rows. */
export function getBranchInventory(branchId, { status, page, perPage } = {}, { signal } = {}) {
  return http.get(`/branches/${branchId}/inventory`, { params: { status: status || undefined, page, per_page: perPage }, signal })
}
