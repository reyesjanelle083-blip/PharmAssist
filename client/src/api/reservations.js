import http from './http.js'

/**
 * POST /reservations. JSON when there is no prescription file, multipart when there is one (items are then sent as
 * items[0][medicine_id], items[0][quantity], ...). The Idempotency-Key is required: sending the same key with the same body again
 * returns the same reservation instead of creating a second one.
 *
 * @param {{ branchId: number, items: {medicineId: number, quantity: number}[], notes?: string, prescription?: File|null }} reservation
 * @param {string} idempotencyKey
 */
export function createReservation({ branchId, items, notes, prescription }, idempotencyKey) {
  const headers = { 'Idempotency-Key': idempotencyKey }
  const cleanNotes = typeof notes === 'string' && notes.trim() !== '' ? notes.trim() : undefined

  if (prescription) {
    const form = new FormData()
    form.append('branch_id', String(branchId))
    if (cleanNotes) form.append('notes', cleanNotes)
    items.forEach((item, i) => {
      form.append(`items[${i}][medicine_id]`, String(item.medicineId))
      form.append(`items[${i}][quantity]`, String(item.quantity))
    })
    form.append('prescription', prescription)
    return http.post('/reservations', form, { headers })
  }

  return http.post(
    '/reservations',
    {
      branch_id: branchId,
      items: items.map((item) => ({ medicine_id: item.medicineId, quantity: item.quantity })),
      ...(cleanNotes ? { notes: cleanNotes } : {}),
    },
    { headers },
  )
}

export function listReservations({ status, page, perPage, branchId } = {}, { signal } = {}) {
  return http.get('/reservations', { params: { status: status || undefined, page, per_page: perPage, branch_id: branchId || undefined }, signal })
}

export function getReservation(id, { signal } = {}) {
  return http.get(`/reservations/${id}`, { signal })
}

/** PATCH /reservations/{id}/status with {action: 'cancel'}: allowed for the owner while it is pending or approved. */
export function cancelReservation(id, reason) {
  return http.patch(`/reservations/${id}/status`, { action: 'cancel', ...(reason ? { reason } : {}) })
}

/**
 * The prescription image. An authenticated request that returns the file itself (not an envelope), as a Blob: the caller makes an
 * object URL for an <img> and revokes it again. The token is sent in the Authorization header, never in a URL.
 */
export async function getPrescriptionBlob(id, { signal } = {}) {
  const { blob } = await http.get(`/reservations/${id}/prescription`, { responseType: 'blob', signal })
  return blob
}

/**
 * PATCH /reservations/{id}/status for staff: approve, reject (reason required) or complete (the customer's claim code required).
 * The backend decides who may do it; a refused one comes back as 403, a wrong state as 409, a wrong code as 422.
 */
export function changeReservationStatus(id, { action, reason, claimCode }) {
  return http.patch(`/reservations/${id}/status`, {
    action,
    ...(reason ? { reason } : {}),
    ...(claimCode ? { claim_code: claimCode } : {}),
  })
}
