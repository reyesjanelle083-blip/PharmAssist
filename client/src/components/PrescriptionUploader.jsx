/**
 * The prescription photo field of the checkout form (JPG or PNG, up to 4 MB): a preview with a remove button once a
 * photo is chosen, the file input, help text, an error line, and a reading of the photo as it comes in (`ocr`, a
 * {phase: 'idle'|'loading'|'done'|'error', text?, status?} from Cart.jsx's own call to POST /prescriptions/ocr - a
 * preview only; the authoritative reading happens again, on its own, when the reservation is actually created).
 * Otherwise purely presentational: the parent owns the file and any validation error.
 */
export default function PrescriptionUploader({ preview, fileError, busy, ocr, onChoose, onRemove }) {
  return (
    <div className="presc-upload-item">
      <label htmlFor="cart-prescription">Prescription photo (JPG or PNG, up to 4 MB) *</label>
      {preview ? (
        <div className="presc-preview">
          <img src={preview} alt="Preview of the prescription photo you chose" />
          <button type="button" className="btn-outline btn-outline-red" onClick={onRemove} disabled={busy}>
            <i className="bi bi-x-circle" aria-hidden="true"></i> Remove photo
          </button>
        </div>
      ) : null}
      <input
        id="cart-prescription"
        type="file"
        accept="image/jpeg,image/png"
        onChange={onChoose}
        aria-describedby="cart-prescription-help cart-prescription-error"
        aria-invalid={fileError ? 'true' : undefined}
        disabled={busy}
      />
      <small id="cart-prescription-help">
        A pharmacist will check your prescription. The photo is stored privately and only you and the branch staff can see it.
      </small>
      <p id="cart-prescription-error" className="field-error" style={{ marginLeft: 0 }} role="alert">
        {fileError}
      </p>

      {ocr?.phase === 'loading' ? (
        <p className="field-hint" role="status">
          Reading your photo...
        </p>
      ) : null}

      {ocr?.phase === 'done' && ocr.text ? (
        <div aria-live="polite">
          <p className="field-hint">What we read from your photo (check it matches before you continue):</p>
          <p className="detail-notes">{ocr.text}</p>
          {ocr.status === 'low_confidence' ? (
            <p className="field-hint">This may not be fully accurate: a blurry or angled photo is harder to read. You can still continue; the pharmacist checks the original.</p>
          ) : null}
        </div>
      ) : null}

      {(ocr?.phase === 'error' || (ocr?.phase === 'done' && !ocr.text)) ? (
        <p className="field-hint" role="status">
          We could not automatically read this photo right now. That is fine: you can still continue, and the pharmacist will check the original.
        </p>
      ) : null}
    </div>
  )
}
