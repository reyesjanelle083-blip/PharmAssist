import http from './http.js'

/**
 * POST /labels/ocr
 * Reads a medicine-package label using the Integration Service OCR.
 *
 * @param {File|Blob} image
 */
export function ocrMedicineLabel(image, { signal } = {}) {
  const form = new FormData()

  form.append(
    'image',
    image,
    image instanceof File && image.name
      ? image.name
      : 'medicine-label.jpg',
  )

  return http.post('/labels/ocr', form, { signal })
}