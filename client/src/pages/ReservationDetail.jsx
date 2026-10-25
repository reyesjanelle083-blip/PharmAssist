import { useCallback, useEffect, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { cancelReservation, getReservation } from '../api/index.js'
import ConfirmDialog from '../components/ConfirmDialog.jsx'
import ErrorMessage from '../components/ErrorMessage.jsx'
import PrescriptionPhoto from '../components/PrescriptionPhoto.jsx'
import Button from '../components/ui/Button.jsx'
import LoadingState from '../components/ui/LoadingState.jsx'
import PageHeader from '../components/ui/PageHeader.jsx'
import StatusBadge from '../components/ui/StatusBadge.jsx'
import Forbidden from './Forbidden.jsx'
import NotFound from './NotFound.jsx'
import { branchLabel, useBranchNames } from '../hooks/useBranchNames.js'
import { useAutoRefresh } from '../hooks/useAutoRefresh.js'
import { usePageTitle } from '../hooks/usePageTitle.js'
import { formatCents, formatDateTime, formatPeso, toCents } from '../utils/format.js'
import { isActiveStatus, STATUS_HELP, STATUS_LABELS } from '../utils/status.js'

const CANCELLABLE = ['pending', 'approved']

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
  const [copyStatus, setCopyStatus] = useState('')
  const lastStatus = useRef(null)
  const cancelButton = useRef(null)
  const heading = useRef(null)
  usePageTitle(state.phase === 'done' ? `Reservation ${state.reservation.claim_code}` : 'Reservation')

  // A reload keeps what is on screen: a failed background refresh shows a small notice instead of replacing the page with an error.
  const load = useCallback(
    (signal) =>
      getReservation(id, { signal })
        .then(({ data }) => {
          if (lastStatus.current && lastStatus.current !== data.status) {
            setNotice(`This reservation is now ${STATUS_LABELS[data.status] ?? data.status}.`)
          }
          lastStatus.current = data.status
          setState({ phase: 'done', reservation: data, stale: false, checkedAt: Date.now() })
        })
        .catch((error) => {
          if (error?.name === 'CanceledError') return
          setState((current) => (current.phase === 'done' ? { ...current, stale: true } : { phase: 'error', error }))
        }),
    [id],
  )

  useEffect(() => {
    const controller = new AbortController()
    load(controller.signal)
    return () => controller.abort()
  }, [load])

  const active = state.phase === 'done' && isActiveStatus(state.reservation.status)
  useAutoRefresh(() => load(), { enabled: active && !cancelling && !confirm })

  async function copyCode(code) {
    try {
      await navigator.clipboard.writeText(code)
      setCopyStatus('Code copied.')
    } catch {
      setCopyStatus('Could not copy automatically. Please select the code and copy it.')
    }
  }

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
  const usable = isActiveStatus(r.status)
  const holdPassed = usable && new Date(r.expires_at).getTime() < state.checkedAt
  const reason = [...(r.history ?? [])].reverse().find((h) => h.to_status === r.status && h.reason)?.reason
  const bannerTone = { pending: 'info', approved: 'success', completed: 'success', rejected: 'error', cancelled: 'warning', expired: 'warning' }[r.status] ?? 'info'

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
          {state.stale ? <p className="app-notice app-notice--warning">Could not refresh just now. Showing the last details we loaded.</p> : null}
        </div>

        <div className={`app-notice app-notice--${bannerTone}`} aria-label="What this status means">
          <p>
            <strong>{STATUS_LABELS[r.status] ?? r.status}: </strong>
            {STATUS_HELP[r.status] ?? ''}
          </p>
          {reason && (r.status === 'rejected' || r.status === 'cancelled') ? <p>Reason: {reason}</p> : null}
          {holdPassed ? <p>The hold time has passed. The branch will update this reservation shortly.</p> : null}
          {usable ? <p className="app-notice__reference">This page checks for updates on its own.</p> : null}
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

          <aside aria-label="Claim ticket and details">
          <section className="summary-card claim-ticket" aria-labelledby="detail-heading" style={{ position: 'static' }}>
            <div className="summary-head">
              <h2 id="detail-heading">PharmAssist reservation</h2>
              <p>{usable ? 'Show this code at the branch' : 'This code can no longer be used at the branch'}</p>
            </div>
            <div className="summary-body">
              <p className="claim-label">Claim code</p>
              <p className="claim-code claim-code--small" aria-label={`Claim code ${r.claim_code.split('').join(' ')}`} style={usable ? undefined : { opacity: 0.6 }}>
                {r.claim_code}
              </p>
              {usable ? (
                <div className="no-print claim-copy">
                  <Button onClick={() => copyCode(r.claim_code)}>
                    <i className="bi bi-clipboard" aria-hidden="true"></i> Copy code
                  </Button>
                  <Button onClick={() => window.print()}>
                    <i className="bi bi-printer" aria-hidden="true"></i> Print ticket
                  </Button>
                  <span role="status" className="claim-copy-status">
                    {copyStatus}
                  </span>
                </div>
              ) : null}
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
            </div>
          </section>

          <section className="summary-card" aria-labelledby="extras-heading" style={{ position: 'static', marginTop: 20 }}>
            <div className="summary-head">
              <h2 id="extras-heading">Notes and prescription</h2>
            </div>
            <div className="summary-body">
              {!r.notes && !r.prescription_uploaded ? <p className="field-hint">No notes or prescription were added to this reservation.</p> : null}
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
          </section>
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
