import PrescriptionPhoto from './PrescriptionPhoto.jsx'

const OCR_HELP = {
  success: 'Read automatically from the photo.',
  low_confidence: 'Read automatically, but the reading may be wrong. Check it against the photo.',
  unavailable: 'The photo could not be read automatically. Review the original photo.',
  not_processed: 'The photo was not read automatically. Review the original photo.',
  not_required: 'No prescription was needed for this reservation.',
}

/**
 * What a reviewer needs for a prescription: the uploaded photo (fetched with the login) and the text the OCR stored with the
 * reservation when it was created. The text is a helper only: the pharmacist always checks the original prescription.
 */
export default function PrescriptionReview({ reservation }) {
  if (!reservation.prescription_uploaded) {
    return <p className="field-hint">No prescription photo was uploaded for this reservation.</p>
  }

  const hasText = (reservation.ocr_status === 'success' || reservation.ocr_status === 'low_confidence') && reservation.ocr_text

  return (
    <div>
      <PrescriptionPhoto id={reservation.id} />
      <div className="form-section-title">Text read from the photo</div>
      <p className="field-hint">{OCR_HELP[reservation.ocr_status] ?? 'Not read automatically.'}</p>
      {hasText ? <p className="detail-notes" style={{ whiteSpace: 'pre-wrap' }}>{reservation.ocr_text}</p> : null}
      <p className="field-hint">The pharmacist always checks the original prescription.</p>
    </div>
  )
}
