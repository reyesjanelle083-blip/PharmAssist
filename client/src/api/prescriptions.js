import http from './http.js'

/**
 * POST /prescriptions/ocr: reads a prescription photo into text, without creating or storing anything (the gateway's
 * own summary for this route). Used to show the customer a preview of what will be read before they submit the
 * reservation; reservation-service reads the photo again, on its own, once the reservation is actually created, and
 * that second reading - not this preview - is what ends up stored with the reservation.
 *
 * @param {File} image
 * @returns {Promise<{data: {ocr_text: string|null, confidence: number|null, ocr_status: 'success'|'low_confidence'|'unavailable'}, warnings: string[]}>}
 */
export function ocrPrescription(image, { signal } = {}) {
  const form = new FormData()
  form.append('image', image)
  return http.post('/prescriptions/ocr', form, { signal })
}
