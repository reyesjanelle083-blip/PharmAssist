function confidencePercent(confidence) {
  if (
    confidence === null ||
    confidence === undefined ||
    confidence === ''
  ) {
    return null
  }

  const number = Number(confidence)

  if (!Number.isFinite(number)) {
    return null
  }

  const normalized =
    number <= 1
      ? number * 100
      : number

  return Math.max(
    0,
    Math.min(
      100,
      Math.round(normalized),
    ),
  )
}

export default function PrescriptionUploader({
  preview,
  fileError,
  busy,
  ocr,
  onChoose,
  onRemove,
  onRetry,
}) {
  const confidence =
    confidencePercent(
      ocr?.confidence,
    )

  const hasText =
    ocr?.phase === 'done' &&
    Boolean(ocr?.text?.trim())

  const unavailable =
    ocr?.phase === 'error' ||
    (
      ocr?.phase === 'done' &&
      !hasText
    )

  return (
    <section
      className="checkout-prescription"
      aria-labelledby="prescription-heading"
    >
      <div className="checkout-section-heading">
        <span className="checkout-section-heading__icon">
          <i
            className="bi bi-file-earmark-medical"
            aria-hidden="true"
          ></i>
        </span>

        <div>
          <span>Required</span>

          <h2 id="prescription-heading">
            Prescription
          </h2>

          <p>
            Upload a clear JPG or PNG photo.
            PharmAssist will try to read the image,
            while branch staff will still verify the
            original prescription.
          </p>
        </div>
      </div>

      <div className="checkout-prescription__grid">
        <div className="checkout-prescription__photo">
          <div className="checkout-prescription__panel-head">
            <div>
              <span>Original prescription</span>
              <strong>Uploaded photo</strong>
            </div>

            {preview ? (
              <span className="checkout-status-pill checkout-status-pill--success">
                <i
                  className="bi bi-check-circle-fill"
                  aria-hidden="true"
                ></i>
                Photo ready
              </span>
            ) : null}
          </div>

          {preview ? (
            <div className="checkout-prescription__image">
              <img
                src={preview}
                alt="Preview of the prescription photo you chose"
              />
            </div>
          ) : (
            <div className="checkout-prescription__empty">
              <i
                className="bi bi-image"
                aria-hidden="true"
              ></i>

              <strong>
                No prescription photo yet
              </strong>

              <p>
                Choose a clear image with readable
                medicine and dosage information.
              </p>
            </div>
          )}

          <div className="checkout-prescription__photo-actions">
            <label
              htmlFor="cart-prescription"
              className="checkout-upload-button"
            >
              <i
                className="bi bi-upload"
                aria-hidden="true"
              ></i>

              {preview
                ? 'Choose another photo'
                : 'Choose photo'}
            </label>

            {preview ? (
              <button
                type="button"
                className="checkout-remove-photo"
                onClick={onRemove}
                disabled={busy}
              >
                <i
                  className="bi bi-trash3"
                  aria-hidden="true"
                ></i>
                Remove
              </button>
            ) : null}
          </div>

          <input
            id="cart-prescription"
            className="checkout-hidden-file"
            type="file"
            accept="image/jpeg,image/png"
            onChange={onChoose}
            aria-describedby="cart-prescription-help cart-prescription-error"
            aria-invalid={
              fileError
                ? 'true'
                : undefined
            }
            disabled={busy}
          />

          <p
            id="cart-prescription-help"
            className="checkout-prescription__privacy"
          >
            <i
              className="bi bi-lock-fill"
              aria-hidden="true"
            ></i>

            The image is stored privately and can
            only be viewed by you and authorized
            pharmacy staff.
          </p>

          {fileError ? (
            <p
              id="cart-prescription-error"
              className="checkout-prescription__error"
              role="alert"
            >
              <i
                className="bi bi-exclamation-circle-fill"
                aria-hidden="true"
              ></i>

              {fileError}
            </p>
          ) : null}
        </div>

        <div className="checkout-prescription__ocr">
          <div className="checkout-prescription__panel-head">
            <div>
              <span>Automatic reading</span>
              <strong>OCR result</strong>
            </div>

            {ocr?.phase === 'loading' ? (
              <span className="checkout-status-pill checkout-status-pill--reading">
                Reading...
              </span>
            ) : null}

            {hasText ? (
              <span
                className={
                  ocr.status === 'low_confidence'
                    ? 'checkout-status-pill checkout-status-pill--warning'
                    : 'checkout-status-pill checkout-status-pill--success'
                }
              >
                <i
                  className={
                    ocr.status === 'low_confidence'
                      ? 'bi bi-exclamation-circle-fill'
                      : 'bi bi-check-circle-fill'
                  }
                  aria-hidden="true"
                ></i>

                {ocr.status === 'low_confidence'
                  ? 'Low confidence'
                  : 'Text detected'}
              </span>
            ) : null}
          </div>

          {!preview ? (
            <div className="checkout-prescription__empty">
              <i
                className="bi bi-text-paragraph"
                aria-hidden="true"
              ></i>

              <strong>
                Waiting for a photo
              </strong>

              <p>
                OCR output will appear here after
                you upload a prescription.
              </p>
            </div>
          ) : null}

          {ocr?.phase === 'loading' ? (
            <div
              className="checkout-ocr-loading"
              role="status"
            >
              <span>
                <i
                  className="bi bi-arrow-repeat"
                  aria-hidden="true"
                ></i>
              </span>

              <strong>
                Reading the prescription...
              </strong>

              <p>
                Extracting readable text from your
                uploaded image.
              </p>
            </div>
          ) : null}

          {hasText ? (
            <div
              className="checkout-ocr-result"
              aria-live="polite"
            >
              {confidence !== null ? (
                <div className="checkout-ocr-confidence">
                  <div>
                    <span>
                      OCR confidence
                    </span>

                    <strong>
                      {confidence}%
                    </strong>
                  </div>

                  <div
                    className="checkout-ocr-confidence__track"
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

              <span className="checkout-ocr-result__label">
                Text read from the photo
              </span>

              <pre>
                {ocr.text}
              </pre>

              <div
                className={
                  ocr.status === 'low_confidence'
                    ? 'checkout-ocr-note checkout-ocr-note--warning'
                    : 'checkout-ocr-note'
                }
              >
                <i
                  className={
                    ocr.status === 'low_confidence'
                      ? 'bi bi-exclamation-triangle-fill'
                      : 'bi bi-info-circle-fill'
                  }
                  aria-hidden="true"
                ></i>

                <p>
                  {ocr.status === 'low_confidence'
                    ? 'Some words may be inaccurate. Compare the OCR result with the original image before continuing.'
                    : 'OCR is only an automated reading aid. The pharmacist will verify the original prescription.'}
                </p>
              </div>
            </div>
          ) : null}

          {preview && unavailable ? (
            <div className="checkout-ocr-unavailable">
              <i
                className="bi bi-eye-slash"
                aria-hidden="true"
              ></i>

              <strong>
                Automatic reading unavailable
              </strong>

              <p>
                You may continue with the
                reservation. The pharmacist will
                check the original image.
              </p>

              {onRetry ? (
                <button
                  type="button"
                  onClick={onRetry}
                  disabled={busy}
                >
                  <i
                    className="bi bi-arrow-clockwise"
                    aria-hidden="true"
                  ></i>

                  Try OCR again
                </button>
              ) : null}
            </div>
          ) : null}
        </div>
      </div>

      <div className="checkout-prescription__reminder">
        <i
          className="bi bi-shield-check"
          aria-hidden="true"
        ></i>

        <div>
          <strong>
            Pharmacist verification is always
            required.
          </strong>

          <p>
            The OCR result does not replace the
            original prescription or professional
            review.
          </p>
        </div>
      </div>
    </section>
  )
}