import PrescriptionPhoto from './PrescriptionPhoto.jsx'

import '../styles/staff-prescriptions.css'

const OCR_HELP = {
  success:
    'PharmAssist successfully extracted text from the prescription photo.',

  low_confidence:
    'PharmAssist extracted text, but some words may be inaccurate. Compare every important detail with the original photo.',

  unavailable:
    'The prescription could not be read automatically. Review the original image manually.',

  not_processed:
    'No automatic OCR reading is available. Review the original image manually.',

  not_required:
    'No prescription was required for this reservation.',
}

function confidencePercent(value) {
  if (
    value === null ||
    value === undefined ||
    value === ''
  ) {
    return null
  }

  const number =
    Number(value)

  if (
    !Number.isFinite(number)
  ) {
    return null
  }

  return Math.max(
    0,
    Math.min(
      100,
      Math.round(
        number <= 1
          ? number * 100
          : number,
      ),
    ),
  )
}

export default function PrescriptionReview({
  reservation,
}) {
  if (
    !reservation.prescription_uploaded
  ) {
    return (
      <div className="staff-rx-no-photo">
        <i
          className="bi bi-file-earmark-x"
          aria-hidden="true"
        ></i>

        <div>
          <strong>
            No prescription uploaded
          </strong>

          <p>
            This reservation does not
            have a prescription image
            to review.
          </p>
        </div>
      </div>
    )
  }

  /*
   * Show any OCR text the backend stored.
   * We intentionally do not require a
   * "success" status here so rough text is
   * not hidden from the reviewer.
   */
  const text =
    reservation.ocr_text?.trim() ??
    ''

  const hasText =
    text.length > 0

  const confidence =
    confidencePercent(
      reservation.ocr_confidence,
    )

  const lowConfidence =
    reservation.ocr_status ===
    'low_confidence'

  const successful =
    reservation.ocr_status ===
      'success' ||
    lowConfidence

  return (
    <div className="staff-rx-review">
      <div className="staff-rx-review__notice">
        <i
          className="bi bi-shield-check"
          aria-hidden="true"
        ></i>

        <div>
          <strong>
            Verify against the original prescription
          </strong>

          <p>
            OCR is only a reading aid.
            The pharmacist or authorized
            branch staff must use the
            uploaded photo as the primary
            reference.
          </p>
        </div>
      </div>

      <div className="staff-rx-review__grid">
        <section
          className="staff-rx-review-panel"
          aria-labelledby="original-prescription-heading"
        >
          <div className="staff-rx-review-panel__heading">
            <div>
              <span>
                Original
              </span>

              <h3 id="original-prescription-heading">
                Prescription photo
              </h3>
            </div>

            <span className="staff-rx-review-state staff-rx-review-state--success">
              <i
                className="bi bi-check-circle-fill"
                aria-hidden="true"
              ></i>

              Uploaded
            </span>
          </div>

          <div className="staff-rx-review-photo">
            <PrescriptionPhoto
              id={reservation.id}
            />
          </div>

          <p className="staff-rx-review-caption">
            <i
              className="bi bi-lock-fill"
              aria-hidden="true"
            ></i>

            Customer prescription image.
            Use this photo to verify the
            OCR reading.
          </p>
        </section>

        <section
          className="staff-rx-review-panel"
          aria-labelledby="ocr-reading-heading"
        >
          <div className="staff-rx-review-panel__heading">
            <div>
              <span>
                Automatic reading
              </span>

              <h3 id="ocr-reading-heading">
                OCR result
              </h3>
            </div>

            {successful ? (
              <span
                className={
                  lowConfidence
                    ? 'staff-rx-review-state staff-rx-review-state--warning'
                    : 'staff-rx-review-state staff-rx-review-state--success'
                }
              >
                <i
                  className={
                    lowConfidence
                      ? 'bi bi-exclamation-triangle-fill'
                      : 'bi bi-check-circle-fill'
                  }
                  aria-hidden="true"
                ></i>

                {lowConfidence
                  ? 'Low confidence'
                  : 'Text detected'}
              </span>
            ) : (
              <span className="staff-rx-review-state staff-rx-review-state--warning">
                <i
                  className="bi bi-eye-slash-fill"
                  aria-hidden="true"
                ></i>

                Manual review
              </span>
            )}
          </div>

          {confidence !==
            null &&
          hasText ? (
            <div className="staff-rx-review-confidence">
              <div>
                <span>
                  OCR confidence
                </span>

                <strong>
                  {confidence}%
                </strong>
              </div>

              <div
                className="staff-rx-review-confidence__track"
                aria-hidden="true"
              >
                <span
                  style={{
                    width: `${confidence}%`,
                  }}
                ></span>
              </div>
            </div>
          ) : null}

          <p
            className={
              lowConfidence
                ? 'staff-rx-review-help staff-rx-review-help--warning'
                : 'staff-rx-review-help'
            }
          >
            <i
              className={
                lowConfidence
                  ? 'bi bi-exclamation-triangle-fill'
                  : 'bi bi-info-circle-fill'
              }
              aria-hidden="true"
            ></i>

            <span>
              {OCR_HELP[
                reservation
                  .ocr_status
              ] ??
                'No automatic OCR information is available.'}
            </span>
          </p>

          {hasText ? (
            <>
              <span className="staff-rx-review-text-label">
                Text read from the photo
              </span>

              <pre className="staff-rx-review-text">
                {text}
              </pre>
            </>
          ) : (
            <div className="staff-rx-review-empty">
              <i
                className="bi bi-eye-slash"
                aria-hidden="true"
              ></i>

              <strong>
                No OCR text available
              </strong>

              <p>
                Read the prescription
                directly from the
                original image.
              </p>
            </div>
          )}

          <div className="staff-rx-review-checklist">
            <span>
              Verify before approving
            </span>

            <ul>
              <li>
                Medicine name
              </li>

              <li>
                Strength and dosage
              </li>

              <li>
                Quantity or duration
              </li>

              <li>
                Any important written
                instructions
              </li>
            </ul>
          </div>
        </section>
      </div>
    </div>
  )
}