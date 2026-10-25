import { useCallback, useEffect, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { cancelReservation, getPrescriptionBlob, getReservation } from '../api/index.js'
import ConfirmDialog from '../components/ConfirmDialog.jsx'
import ErrorMessage from '../components/ErrorMessage.jsx'
import Button from '../components/ui/Button.jsx'
import LoadingState from '../components/ui/LoadingState.jsx'
import PageHeader from '../components/ui/PageHeader.jsx'
import StatusBadge from '../components/ui/StatusBadge.jsx'
import Forbidden from './Forbidden.jsx'
import NotFound from './NotFound.jsx'
import { branchLabel, useBranchNames } from '../hooks/useBranchNames.js'
import { usePageTitle } from '../hooks/usePageTitle.js'
import { formatCents, formatDateTime, formatPeso, toCents } from '../utils/format.js'

const CANCELLABLE = ['pending', 'approved']

/** The prescription photo, fetched with the login (never a URL with a token), shown from an object URL that is released again. */
function PrescriptionPhoto({ id }) {
  const [state, setState] = useState({ phase: 'loading' })

  useEffect(() => {
    const controller = new AbortController()
    let url = null
    getPrescriptionBlob(id, { signal: controller.signal })
      .then((blob) => {
        url = URL.createObjectURL(blob)
        setState({ phase: 'done', url })
      })
      .catch((error) => {
        if (error?.name !== 'CanceledError') setState({ phase: 'error', error })
      })
    return () => {
      controller.abort()
      if (url) URL.revokeObjectURL(url)
    }
  }, [id])

  return (
    <div aria-live="polite">
      {state.phase === 'loading' ? <p className="waiting-text">Loading the prescription photo...</p> : null}
      {state.phase === 'error' ? <ErrorMessage error={state.error} /> : null}
      {state.phase === 'done' ? <img className="presc-photo" src={state.url} alt="The prescription photo you added to this reservation" /> : null}
    </div>
  )
}

/**
 * One reservation: the items, the claim code, the notes and expiry, the status history as a timeline, the prescription photo, and
 * Cancel for a pending or approved one. Built from the old cart page (cart.php): header, item cards and the summary card.
 */
export default function ReservationDetail() {
  const { id } = useParams()
  return <Detail key={id} id={id} />
}

function Detail({ id }) {
  const names = useBranchNames()
  const [state, setState] = useState({ phase: 'loading' })
  const [confirm, setConfirm] = useState(false)
  const [cancelling, setCancelling] = useState(false)
  const [cancelError, setCancelError] = useState(null)
  const [notice, setNotice] = useState('')
  const cancelButton = useRef(null)
  const heading = useRef(null)
  usePageTitle(state.phase === 'done' ? `Reservation ${state.reservation.claim_code}` : 'Reservation')

  const load = useCallback(
    (signal) =>
      getReservation(id, { signal })
        .then(({ data }) => setState({ phase: 'done', reservation: data }))
        .catch((error) => {
          if (error?.name !== 'CanceledError') setState({ phase: 'error', error })
        }),
    [id],
  )

  useEffect(() => {
    const controller = new AbortController()
    load(controller.signal)
    return () => controller.abort()
  }, [load])

  async function doCancel() {
    setConfirm(false)
    setCancelling(true)
    setCancelError(null)
    try {
      await cancelReservation(id)
      await load()
      setNotice('The reservation was cancelled. The stock held for it was released.')
      requestAnimationFrame(() => heading.current?.focus())
    } catch (error) {
      setCancelError(error)
      if (error.code === 'INVALID_TRANSITION') await load() // it changed in the meantime: show what it is now
    } finally {
      setCancelling(false)
    }
  }

  if (state.phase === 'loading') return <LoadingState>Loading the reservation...</LoadingState>
  if (state.phase === 'error') {
    if (state.error.status === 403) return <Forbidden />
    if (state.error.status === 404) return <NotFound />
    return (
      <div className="legacy-cart">
        <div className="cart-wrapper">
          <ErrorMessage error={state.error} />
          <Button to="/reservations">Back to My Reservations</Button>
        </div>
      </div>
    )
  }

  const r = state.reservation
  const totalCents = r.items.reduce((sum, item) => sum + toCents(item.unit_price) * item.quantity, 0)

  return (
    <div className="legacy-cart legacy-notif">
      <div className="cart-wrapper">
        <PageHeader
          headingRef={heading}
          title={
            <>
              Reservation <span className="code-display">{r.claim_code}</span>
            </>
          }
          subtitle={
            <>
              {branchLabel(names, r.branch_id)} &middot; <StatusBadge status={r.status} />
            </>
          }
          actions={
            <>
              <Button to="/reservations">
                <i className="bi bi-arrow-left" aria-hidden="true"></i> My Reservations
              </Button>
              {CANCELLABLE.includes(r.status) ? (
                <Button variant="danger" ref={cancelButton} onClick={() => setConfirm(true)} disabled={cancelling}>
                  <i className="bi bi-x-circle" aria-hidden="true"></i> {cancelling ? 'Cancelling...' : 'Cancel reservation'}
                </Button>
              ) : null}
            </>
          }
        />

        <div aria-live="polite">
          {notice ? <p className="app-notice app-notice--success">{notice}</p> : null}
          {cancelError ? <ErrorMessage error={cancelError} /> : <ErrorMessage />}
        </div>

        <div className="cart-layout">
          <div>
            <section aria-labelledby="items-heading">
              <h2 id="items-heading" className="visually-hidden">
                Items
              </h2>
              <ul className="items-list" style={{ listStyle: 'none', padding: 0 }}>
                {r.items.map((item) => (
                  <li key={item.medicine_id} className="cart-item">
                    <div className="item-thumb" aria-hidden="true">
                      <i className="bi bi-capsule"></i>
                    </div>
                    <div className="item-body">
                      <h3 className="item-name">{item.medicine_name}</h3>
                      <div className="item-meta">
                        <span className="meta-unit-price">
                          {item.quantity} &times; <strong>{formatPeso(item.unit_price)}</strong>
                        </span>
                        <span className="item-line-total">{formatCents(toCents(item.unit_price) * item.quantity)}</span>
                      </div>
                    </div>
                  </li>
                ))}
              </ul>
            </section>

            <section className="summary-card history-card" aria-labelledby="history-heading" style={{ position: 'static', marginTop: 20 }}>
              <div className="summary-head">
                <h2 id="history-heading">Status history</h2>
                <p>What happened to this reservation</p>
              </div>
              <ol className="timeline">
                {(r.history ?? []).map((h, i) => (
                  <li key={i}>
                    <strong>
                      {h.from_status ? <><StatusBadge status={h.from_status} /> to </> : 'Created as '}
                      <StatusBadge status={h.to_status} />
                    </strong>
                    <span className="timeline-time">{formatDateTime(h.created_at)}</span>
                    {h.reason ? <span className="timeline-reason">Reason: {h.reason}</span> : null}
                  </li>
                ))}
              </ol>
            </section>
          </div>

          <aside className="summary-card" aria-labelledby="detail-heading">
            <div className="summary-head">
              <h2 id="detail-heading">Reservation</h2>
              <p>Show the claim code at the branch</p>
            </div>
            <div className="summary-body">
              <p className="claim-label">Claim code</p>
              <p className="claim-code claim-code--small">{r.claim_code}</p>
              <dl className="claim-facts">
                <div>
                  <dt>Status</dt>
                  <dd>
                    <StatusBadge status={r.status} />
                  </dd>
                </div>
                <div>
                  <dt>Branch</dt>
                  <dd>{branchLabel(names, r.branch_id)}</dd>
                </div>
                <div>
                  <dt>{r.status === 'pending' || r.status === 'approved' ? 'Held until' : 'Expiry'}</dt>
                  <dd>{formatDateTime(r.expires_at)}</dd>
                </div>
                <div>
                  <dt>Created</dt>
                  <dd>{formatDateTime(r.created_at)}</dd>
                </div>
              </dl>
              <div className="breakdown">
                <div className="bk-row total-row">
                  <span>Total</span>
                  <span className="total-amt">{formatCents(totalCents)}</span>
                </div>
              </div>
              <p className="pay-note">
                <i className="bi bi-shop" aria-hidden="true"></i> <strong>You pay at the branch.</strong>
              </p>
              {r.notes ? (
                <>
                  <div className="form-section-title">Your notes</div>
                  <p className="detail-notes">{r.notes}</p>
                </>
              ) : null}
              {r.prescription_uploaded ? (
                <>
                  <div className="form-section-title">Prescription photo</div>
                  <PrescriptionPhoto id={r.id} />
                  {r.ocr_status === 'success' || r.ocr_status === 'low_confidence' ? (
                    <>
                      <p className="field-hint">What we read from it:</p>
                      <p className="detail-notes">{r.ocr_text}</p>
                    </>
                  ) : null}
                  {r.ocr_status === 'unavailable' || r.ocr_status === 'not_processed' ? (
                    <p className="field-hint">This photo was not read automatically; the pharmacist checks the original.</p>
                  ) : null}
                </>
              ) : null}
            </div>
          </aside>
        </div>
      </div>

      <ConfirmDialog
        open={confirm}
        title="Cancel this reservation?"
        confirmLabel="Yes, cancel it"
        cancelLabel="Keep it"
        danger
        returnFocusRef={cancelButton}
        onCancel={() => setConfirm(false)}
        onConfirm={doCancel}
      >
        <p>
          Reservation <strong>{r.claim_code}</strong> will be cancelled and the medicines held for you go back on the shelf. This cannot be undone.
        </p>
      </ConfirmDialog>
    </div>
  )
}
