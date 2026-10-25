/**
 * The prescription photo field of the checkout form (JPG or PNG, up to 4 MB): a preview with a remove button once a
 * photo is chosen, the file input, help text and an error line. Purely presentational: the parent owns the file and
 * any validation error (Cart.jsx also needs them to decide whether the form can be submitted).
 */
export default function PrescriptionUploader({ preview, fileError, busy, onChoose, onRemove }) {
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
        A pharmacist will check your prescription. The photo is stored privately and only you and the branch staff can see it. Text reading of
        prescriptions is not active yet.
      </small>
      <p id="cart-prescription-error" className="field-error" style={{ marginLeft: 0 }} role="alert">
        {fileError}
      </p>
    </div>
  )
}
