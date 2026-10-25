import { useEffect, useMemo, useRef, useState } from 'react'
import { createReservation, friendlyMessage, shortages } from '../api/index.js'
import CartItem from '../components/CartItem.jsx'
import ClaimTicket from '../components/ClaimTicket.jsx'
import ConfirmDialog from '../components/ConfirmDialog.jsx'
import ErrorMessage from '../components/ErrorMessage.jsx'
import PrescriptionUploader from '../components/PrescriptionUploader.jsx'
import Button from '../components/ui/Button.jsx'
import EmptyState from '../components/ui/EmptyState.jsx'
import PageHeader from '../components/ui/PageHeader.jsx'
import { useCart } from '../context/useCart.js'
import { usePageTitle } from '../hooks/usePageTitle.js'
import { formatCents } from '../utils/format.js'

const MAX_PHOTO_BYTES = 4 * 1024 * 1024
const PHOTO_TYPES = ['image/jpeg', 'image/png']
const NOTES_MAX = 500

function photoProblem(file) {
  if (!PHOTO_TYPES.includes(file.type)) return 'The photo must be a JPG or PNG picture.'
  if (file.size > MAX_PHOTO_BYTES) return 'The photo must be 4 MB or smaller.'
  return ''
}

/**
 * The old cart page (cart.php): "Reservation Cart" header, the items with a quantity stepper, and the Order Summary card on the
 * right. The checkout step (notes, prescription photo, Reserve) is inside that card; the success ticket replaces the page.
 */
export default function Cart() {
  usePageTitle('Cart')
  const cart = useCart()
  const [step, setStep] = useState('cart') // cart | checkout | review | done
  const [notes, setNotes] = useState('')
  const [notesError, setNotesError] = useState('')
  const [file, setFile] = useState(null)
  const [fileError, setFileError] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [short, setShort] = useState([]) // [{medicineId, requested, available}]
  const [created, setCreated] = useState(null) // {reservation, branchName}
  const [confirmClear, setConfirmClear] = useState(false)
  const clearButton = useRef(null)
  const errorBox = useRef(null)
  const reviewHeading = useRef(null)
  // A synchronous guard against a request going out twice (a very fast double click, or Enter plus a click in the same
  // instant): React state updates are not guaranteed to be visible yet to a second, near-simultaneous event handler.
  // The Idempotency-Key below already makes a duplicate request harmless server-side; this just avoids sending it.
  const submitting = useRef(false)

  // The Idempotency-Key: made when the checkout step opens, kept for a retry of the same request (for example after a network
  // error or a timeout), and replaced only when what is sent changes (cart contents, notes or the file).
  const keyRef = useRef(null)
  const signatureRef = useRef(null)
  const signature = JSON.stringify({
    branch: cart.branchId,
    items: cart.items.map((i) => [i.medicineId, i.quantity]),
    notes: notes.trim(),
    file: file ? [file.name, file.size, file.lastModified] : null,
  })
  function currentKey() {
    if (keyRef.current === null || signatureRef.current !== signature) {
      keyRef.current = crypto.randomUUID()
      signatureRef.current = signature
    }
    return keyRef.current
  }

  // the preview of the chosen photo; the object URL is released when the photo changes or the page closes
  const preview = useMemo(() => (file ? URL.createObjectURL(file) : null), [file])
  useEffect(() => () => { if (preview) URL.revokeObjectURL(preview) }, [preview])

  if (step === 'done' && created) return <ClaimTicket reservation={created.reservation} branchName={created.branchName} />

  const needsPhoto = cart.needsPrescription
  const shortById = new Map(short.map((s) => [s.medicineId, s]))

  function openCheckout() {
    keyRef.current = crypto.randomUUID()
    signatureRef.current = signature
    setError(null)
    setStep('checkout')
  }

  function choosePhoto(event) {
    const chosen = event.target.files?.[0] ?? null
    event.target.value = ''
    if (!chosen) return
    const problem = photoProblem(chosen)
    setFileError(problem)
    setFile(problem ? null : chosen)
  }

  /** Checkout step -> Review step: only client-side validation here, nothing is sent yet. */
  function proceedToReview(event) {
    event.preventDefault()
    if (busy) return

    if (notes.length > NOTES_MAX) {
      setNotesError(`Please shorten your notes to ${NOTES_MAX} characters or fewer.`)
      return
    }
    setNotesError('')
    if (needsPhoto && !file) {
      setFileError('Please add a photo of the prescription.')
      return
    }

    setError(null)
    setStep('review')
    requestAnimationFrame(() => reviewHeading.current?.focus())
  }

  /** Review step -> the actual request. Nothing here changes what is sent; it only moves where Reserve is pressed from. */
  async function confirmReservation() {
    if (busy || submitting.current) return
    submitting.current = true

    setBusy(true)
    setError(null)
    setShort([])
    try {
      const { data } = await createReservation(
        { branchId: cart.branchId, items: cart.items.map((i) => ({ medicineId: i.medicineId, quantity: i.quantity })), notes, prescription: file },
        currentKey(),
      )
      setCreated({ reservation: data, branchName: cart.branchName })
      cart.clear()
      setStep('done')
    } catch (caught) {
      setError(caught)
      if (caught.code === 'STOCK_INSUFFICIENT') setShort(shortages(caught))
      if (caught.code === 'PRESCRIPTION_REQUIRED' && Array.isArray(caught.details?.medicine_ids)) {
        cart.markPrescription(caught.details.medicine_ids.map(Number))
        // The review step has no file field; send them back to where they can actually add the photo.
        if (!file) setStep('checkout')
      }
      requestAnimationFrame(() => errorBox.current?.focus())
    } finally {
      setBusy(false)
      submitting.current = false
    }
  }

  if (cart.count === 0) {
    return (
      <div className="legacy-cart">
        <div className="cart-wrapper">
          <PageHeader title="Reservation Cart" subtitle="Your cart is empty" />
          <EmptyState
            icon="bi bi-cart"
            title="Your cart is empty"
            actions={
              <Button to="/">
                <i className="bi bi-search" aria-hidden="true"></i> Search medicines
              </Button>
            }
          >
            Search for a medicine and press Reserve to add it here.
          </EmptyState>
        </div>
      </div>
    )
  }

  return (
    <div className="legacy-cart">
      <div className="cart-wrapper">
        <PageHeader
          title="Reservation Cart"
          subtitle={
            <span aria-live="polite">
              {cart.count} {cart.count === 1 ? 'item' : 'items'} in your cart &middot; {cart.branchName}
            </span>
          }
          actions={
            <>
              <Button to="/">
                <i className="bi bi-plus-circle" aria-hidden="true"></i> Add More
              </Button>
              <Button variant="danger" ref={clearButton} onClick={() => setConfirmClear(true)} disabled={busy}>
                <i className="bi bi-trash3" aria-hidden="true"></i> Clear Cart
              </Button>
            </>
          }
        />

        {cart.notice ? (
          <p className="app-notice app-notice--info" role="status">
            {cart.notice}
          </p>
        ) : null}

        <div className="cart-layout">
          <section aria-label="Items in your cart">
            <ul className="items-list" style={{ listStyle: 'none', padding: 0 }}>
              {cart.items.map((item) => (
                <CartItem
                  key={item.medicineId}
                  item={item}
                  busy={busy}
                  problem={shortById.get(item.medicineId)}
                  onQuantityChange={(quantity) => cart.setQuantity(item.medicineId, quantity)}
                  onRemove={() => cart.removeLine(item.medicineId)}
                />
              ))}
            </ul>
          </section>

          <aside className="summary-card" aria-labelledby="summary-heading">
            <div className="summary-head">
              <h2 id="summary-heading">Order Summary</h2>
              <p>Review and complete your reservation</p>
            </div>

            <div className="summary-body">
              <div className="breakdown">
                <div className="bk-row total-row">
                  <span>Total Amount</span>
                  <span className="total-amt">{formatCents(cart.totalCents)}</span>
                </div>
              </div>
              <p className="pay-note">
                <i className="bi bi-shop" aria-hidden="true"></i> <strong>You pay at the branch.</strong> Nothing is charged online.
              </p>

              <div ref={errorBox} tabIndex={-1} className="checkout-error">
                {error ? (
                  <ErrorMessage error={error} message={error.code === 'STOCK_INSUFFICIENT' ? 'Some items are no longer available in the amount you asked for. Change the quantities in your cart and try again.' : friendlyMessage(error)}>
                    {short.length > 0 ? (
                      <>
                        <ul className="short-list">
                          {short.map((s) => (
                            <li key={s.medicineId}>
                              {cart.items.find((i) => i.medicineId === s.medicineId)?.name ?? `Medicine ${s.medicineId}`}: you asked for {s.requested}, {s.available} available.
                            </li>
                          ))}
                        </ul>
                        <button type="button" className="btn-outline btn-outline-blue" onClick={() => { cart.applyAvailability(short); setShort([]); setError(null) }}>
                          Use the available amounts
                        </button>
                      </>
                    ) : null}
                  </ErrorMessage>
                ) : (
                  <ErrorMessage />
                )}
              </div>

              {step === 'cart' ? (
                <button type="button" className="submit-btn" onClick={openCheckout}>
                  <i className="bi bi-arrow-right-circle" aria-hidden="true"></i> Continue to checkout
                </button>
              ) : null}

              {step === 'checkout' ? (
                <form onSubmit={proceedToReview} noValidate>
                  <div className="form-section-title">Your reservation</div>

                  <div className="form-field">
                    <label htmlFor="cart-notes">Additional notes (optional)</label>
                    <textarea
                      id="cart-notes"
                      rows={3}
                      maxLength={NOTES_MAX}
                      value={notes}
                      onChange={(e) => {
                        setNotes(e.target.value)
                        if (notesError) setNotesError('')
                      }}
                      placeholder="Any special instructions..."
                      aria-describedby={notesError ? 'cart-notes-count cart-notes-error' : 'cart-notes-count'}
                      aria-invalid={notesError ? 'true' : undefined}
                    />
                    <small id="cart-notes-count">
                      {notes.length} of {NOTES_MAX} characters
                    </small>
                    {notesError ? (
                      <p id="cart-notes-error" className="field-error" role="alert">
                        {notesError}
                      </p>
                    ) : null}
                  </div>

                  {needsPhoto ? (
                    <PrescriptionUploader
                      preview={preview}
                      fileError={fileError}
                      busy={busy}
                      onChoose={choosePhoto}
                      onRemove={() => {
                        setFile(null)
                        setFileError('')
                      }}
                    />
                  ) : null}

                  <button type="submit" className="submit-btn">
                    <i className="bi bi-eye" aria-hidden="true"></i> Review reservation
                  </button>
                  <button type="button" className="btn-outline btn-outline-blue back-button" onClick={() => setStep('cart')}>
                    Back to cart
                  </button>
                </form>
              ) : null}

              {step === 'review' ? (
                <div aria-busy={busy}>
                  <h3 ref={reviewHeading} tabIndex={-1} className="form-section-title">
                    Review before you reserve
                  </h3>

                  <dl className="claim-facts">
                    <div>
                      <dt>Branch</dt>
                      <dd>{cart.branchName}</dd>
                    </div>
                    <div>
                      <dt>Items</dt>
                      <dd>
                        {cart.count} {cart.count === 1 ? 'medicine' : 'medicines'}
                      </dd>
                    </div>
                  </dl>

                  {notes.trim() ? (
                    <>
                      <div className="form-section-title">Your notes</div>
                      <p className="detail-notes">{notes}</p>
                    </>
                  ) : null}

                  {needsPhoto ? (
                    <>
                      <div className="form-section-title">Prescription photo</div>
                      {preview ? <img className="presc-photo" src={preview} alt="The prescription photo that will be submitted with this reservation" /> : null}
                    </>
                  ) : null}

                  <p className="field-hint">Check the quantities above before you continue: they cannot be changed once sent.</p>

                  <button type="button" className="submit-btn" onClick={confirmReservation} disabled={busy}>
                    <i className="bi bi-bag-check" aria-hidden="true"></i> {busy ? 'Sending...' : 'Confirm & Reserve'}
                  </button>
                  <button type="button" className="btn-outline btn-outline-blue back-button" onClick={() => setStep('checkout')} disabled={busy}>
                    Back to edit
                  </button>
                </div>
              ) : null}
            </div>
          </aside>
        </div>
      </div>

      <ConfirmDialog
        open={confirmClear}
        title="Clear your cart?"
        confirmLabel="Clear cart"
        cancelLabel="Keep my cart"
        danger
        returnFocusRef={clearButton}
        onCancel={() => setConfirmClear(false)}
        onConfirm={() => {
          setConfirmClear(false)
          cart.clear()
        }}
      >
        <p>All items will be removed from your cart. Nothing has been reserved yet.</p>
      </ConfirmDialog>
    </div>
  )
}
