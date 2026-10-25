import { useCallback, useEffect, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { changeReservationStatus, getReservation } from '../../api/index.js'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import ErrorMessage from '../../components/ErrorMessage.jsx'
import PrescriptionReview from '../../components/PrescriptionReview.jsx'
import Button from '../../components/ui/Button.jsx'
import FormField from '../../components/ui/FormField.jsx'
import LoadingState from '../../components/ui/LoadingState.jsx'
import Modal from '../../components/ui/Modal.jsx'
import PageHeader from '../../components/ui/PageHeader.jsx'
import StatusBadge from '../../components/ui/StatusBadge.jsx'
import Forbidden from '../Forbidden.jsx'
import NotFound from '../NotFound.jsx'
import { useAuth } from '../../context/useAuth.js'
import { branchLabel, useBranchNames } from '../../hooks/useBranchNames.js'
import { useAutoRefresh } from '../../hooks/useAutoRefresh.js'
import { usePageTitle } from '../../hooks/usePageTitle.js'
import { formatCents, formatDateTime, formatPeso, toCents } from '../../utils/format.js'
import { actionsFor, ROLE_LABELS } from '../../utils/staff.js'
import { isActiveStatus, STATUS_HELP, STATUS_LABELS } from '../../utils/status.js'

const ACTION_LABELS = { approve: 'Approve', reject: 'Reject', complete: 'Complete (hand over)' }
const DONE_NOTICES = {
  approve: 'The reservation was approved.',
  reject: 'The reservation was rejected and the stock held for it was released.',
  complete: 'The reservation was completed and the stock was taken off the shelf.',
}

/**
 * One reservation for staff: the items, the status history, the prescription review, and the actions this role may take.
 * The buttons shown follow the role (utils/staff.js) but are only a convenience: the backend decides, and a refusal is shown as it is.
 */
export default function StaffReservationDetail() {
  const { id } = useParams()
  return <Detail key={id} id={id} />
}

function Detail({ id }) {
  const { role } = useAuth()
  const names = useBranchNames()
  const [state, setState] = useState({ phase: 'loading' })
  const [dialog, setDialog] = useState(null) // 'approve' | 'reject' | 'complete' | null
  const [busy, setBusy] = useState(false)
  const [actionError, setActionError] = useState(null)
  const [notice, setNotice] = useState('')
  const lastStatus = useRef(null)
  const heading = useRef(null)
  const returnFocus = useRef(null)
  usePageTitle(state.phase === 'done' ? `Reservation ${state.reservation.claim_code} - Staff` : 'Reservation - Staff')

  const load = useCallback(
    (signal) =>
      getReservation(id, { signal })
        .then(({ data }) => {
          if (lastStatus.current && lastStatus.current !== data.status) {
            setNotice(`This reservation is now ${STATUS_LABELS[data.status] ?? data.status}.`)
          }
          lastStatus.current = data.status
          setState({ phase: 'done', reservation: data, stale: false })
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
  useAutoRefresh(() => load(), { enabled: active && dialog === null && !busy })

  async function act(action, extra = {}) {
    setBusy(true)
    setActionError(null)
    try {
      await changeReservationStatus(id, { action, ...extra })
      setDialog(null)
      await load()
      setNotice(DONE_NOTICES[action])
      requestAnimationFrame(() => heading.current?.focus())
    } catch (error) {
      setActionError(error)
      // It changed under us (another person acted first): show what it is now, and close the dialog.
      if (error.code === 'INVALID_TRANSITION' || error.code === 'RESERVATION_EXPIRED') {
        setDialog(null)
        await load()
      }
      if (error.status === 403) setDialog(null)
    } finally {
      setBusy(false)
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
          <p>
            <Button onClick={() => { setState({ phase: 'loading' }); load() }}>Try again</Button>{' '}
            <Button to="/staff/reservations">Back to reservations</Button>
          </p>
        </div>
      </div>
    )
  }

  const r = state.reservation
  const totalCents = r.items.reduce((sum, item) => sum + toCents(item.unit_price) * item.quantity, 0)
  const available = actionsFor(role, r.status)
  const readOnlyReason =
    role === 'ceo'
      ? 'The CEO can view reservations but not change them.'
      : available.length === 0 && isActiveStatus(r.status)
        ? `${ROLE_LABELS[role] ?? 'Your role'} cannot ${r.status === 'pending' ? 'approve or reject' : 'complete'} a ${r.status} reservation.`
        : ''
  const needsPrescription = r.prescription_uploaded && r.status === 'pending'

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
              <Button to="/staff/reservations">
                <i className="bi bi-arrow-left" aria-hidden="true"></i> Reservations
              </Button>
              {available.map((action) => (
                <Button
                  key={action}
                  variant={action === 'reject' ? 'danger' : 'primary'}
                  disabled={busy}
                  onClick={(event) => {
                    returnFocus.current = event.currentTarget
                    setActionError(null)
                    setDialog(action)
                  }}
                >
                  {ACTION_LABELS[action]}
                </Button>
              ))}
            </>
          }
        />

        <div aria-live="polite">
          {notice ? <p className="app-notice app-notice--success">{notice}</p> : null}
          {actionError && dialog === null ? <ErrorMessage error={actionError} /> : <ErrorMessage />}
          {state.stale ? <p className="app-notice app-notice--warning">Could not refresh just now. Showing the last details we loaded.</p> : null}
        </div>

        <div className={`app-notice app-notice--${r.status === 'approved' ? 'success' : r.status === 'rejected' ? 'error' : 'info'}`}>
          <p>
            <strong>{STATUS_LABELS[r.status] ?? r.status}: </strong>
            {STATUS_HELP[r.status] ?? ''}
          </p>
          {readOnlyReason ? <p>{readOnlyReason}</p> : null}
          {needsPrescription && available.includes('approve') ? <p>This reservation has a prescription photo. Review it below before you approve.</p> : null}
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

          <aside aria-label="Reservation details">
            <section className="summary-card" aria-labelledby="detail-heading" style={{ position: 'static' }}>
              <div className="summary-head">
                <h2 id="detail-heading">Details</h2>
              </div>
              <div className="summary-body">
                <dl className="claim-facts">
                  <div>
                    <dt>Claim code</dt>
                    <dd>{r.claim_code}</dd>
                  </div>
                  <div>
                    <dt>Branch</dt>
                    <dd>{branchLabel(names, r.branch_id)}</dd>
                  </div>
                  <div>
                    <dt>{isActiveStatus(r.status) ? 'Held until' : 'Expiry'}</dt>
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
                  <i className="bi bi-shop" aria-hidden="true"></i> <strong>The customer pays at the branch.</strong>
                </p>
                {r.notes ? (
                  <>
                    <div className="form-section-title">Customer notes</div>
                    <p className="detail-notes">{r.notes}</p>
                  </>
                ) : null}
              </div>
            </section>

            <section className="summary-card" aria-labelledby="rx-heading" style={{ position: 'static', marginTop: 20 }}>
              <div className="summary-head">
                <h2 id="rx-heading">Prescription review</h2>
              </div>
              <div className="summary-body">
                <PrescriptionReview reservation={r} />
              </div>
            </section>
          </aside>
        </div>
      </div>

      <ConfirmDialog
        open={dialog === 'approve'}
        title="Approve this reservation?"
        confirmLabel={busy ? 'Approving...' : 'Yes, approve'}
        cancelLabel="Not yet"
        returnFocusRef={returnFocus}
        onCancel={() => setDialog(null)}
        onConfirm={() => act('approve')}
      >
        <p>
          Reservation <strong>{r.claim_code}</strong> will be approved. The stock stays held for the customer until it expires.
        </p>
        {r.prescription_uploaded ? <p>The pharmacist must still check the original prescription at the counter.</p> : null}
        {actionError && dialog === 'approve' ? <ErrorMessage error={actionError} /> : null}
      </ConfirmDialog>

      <ActionDialog
        open={dialog === 'reject'}
        title="Reject this reservation"
        label="Reason for the customer"
        hint="Required. The customer can read this."
        submitLabel={busy ? 'Rejecting...' : 'Reject reservation'}
        danger
        busy={busy}
        error={dialog === 'reject' ? actionError : null}
        returnFocusRef={returnFocus}
        onClose={() => setDialog(null)}
        onSubmit={(value) => act('reject', { reason: value })}
        textarea
      />

      <ActionDialog
        open={dialog === 'complete'}
        title="Complete this reservation"
        label="Claim code shown by the customer"
        hint="Required. Ask the customer for the code and type it here (for example PA-ABCD1234)."
        submitLabel={busy ? 'Completing...' : 'Complete reservation'}
        busy={busy}
        error={dialog === 'complete' ? actionError : null}
        returnFocusRef={returnFocus}
        onClose={() => setDialog(null)}
        onSubmit={(value) => act('complete', { claimCode: value })}
        intro={<p>Handing the medicines over takes them off the shelf. This cannot be undone.</p>}
      />
    </div>
  )
}

/** A small dialog with one required text value (a reason, or the claim code). It stays open and shows the problem when the backend refuses. */
function ActionDialog({ open, title, returnFocusRef, onClose, ...form }) {
  return (
    <Modal open={open} title={title} returnFocusRef={returnFocusRef} onClose={onClose}>
      {open ? <ActionForm onClose={onClose} {...form} /> : null}
    </Modal>
  )
}

// Mounted only while the dialog is open, so the typed value and any message start fresh every time.
function ActionForm({ label, hint, submitLabel, danger = false, busy, error, onClose, onSubmit, textarea = false, intro = null }) {
  const [value, setValue] = useState('')
  const [problem, setProblem] = useState('')

  function submit(event) {
    event.preventDefault()
    const trimmed = value.trim()
    if (trimmed === '') {
      setProblem(`${label} is required.`)
      return
    }
    setProblem('')
    onSubmit(trimmed)
  }

  return (
    <form onSubmit={submit} noValidate>
      {intro}
      <FormField label={label} hint={hint} errors={problem ? [problem] : []}>
        {(controlProps) =>
          textarea ? (
            <textarea {...controlProps} rows={3} maxLength={255} value={value} onChange={(event) => setValue(event.target.value)} />
          ) : (
            <input {...controlProps} type="text" autoComplete="off" maxLength={32} value={value} onChange={(event) => setValue(event.target.value)} />
          )
        }
      </FormField>
      {error ? <ErrorMessage error={error} /> : null}
      <div className="confirm-actions">
        <Button className="confirm-button" onClick={onClose} disabled={busy}>
          Cancel
        </Button>
        <Button type="submit" variant={danger ? 'danger' : 'primary'} className="confirm-button" disabled={busy}>
          {submitLabel}
        </Button>
      </div>
    </form>
  )
}
