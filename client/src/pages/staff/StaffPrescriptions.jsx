import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { getReservation, listReservations } from '../../api/index.js'
import ErrorState from '../../components/ui/ErrorState.jsx'
import EmptyState from '../../components/ui/EmptyState.jsx'
import LoadingState from '../../components/ui/LoadingState.jsx'
import StatusBadge from '../../components/ui/StatusBadge.jsx'
import Forbidden from '../Forbidden.jsx'
import { useAuth } from '../../context/useAuth.js'
import { branchLabel, useBranchNames } from '../../hooks/useBranchNames.js'
import { usePageTitle } from '../../hooks/usePageTitle.js'
import { formatDateTime } from '../../utils/format.js'
import { STATUS_OPTIONS } from '../../utils/status.js'

const PER_PAGE = 10
const OCR_LABELS = {
  success: 'Read automatically',
  low_confidence: 'Read, low confidence - check carefully',
  unavailable: 'Not read - check the original',
  not_processed: 'Not read - check the original',
}

/**
 * The prescription queue. The reservation list does not say which reservations have a photo, so this page loads one page of the
 * list, opens each reservation, and keeps the ones with a prescription. The review itself (photo and stored OCR text) is on the
 * reservation page.
 */
export default function StaffPrescriptions() {
  usePageTitle('Prescription review - Staff')
  const { role } = useAuth()
  const names = useBranchNames()
  const isCeo = role === 'ceo'
  const [status, setStatus] = useState('pending')
  const [page, setPage] = useState(1)
  const [reloadKey, setReloadKey] = useState(0)
  const [state, setState] = useState({ phase: 'loading' })

  useEffect(() => {
    const controller = new AbortController()
    listReservations({ status, page, perPage: PER_PAGE }, { signal: controller.signal })
      .then(async ({ data }) => {
        const rows = data.items ?? []
        const details = await Promise.all(rows.map((row) => getReservation(row.id, { signal: controller.signal }).then((r) => r.data).catch(() => null)))
        const withRx = details.filter((detail) => detail?.prescription_uploaded)
        setState({ phase: 'done', rows: withRx, checked: rows.length, unreadable: details.filter((d) => d === null).length, meta: data.meta ?? null })
      })
      .catch((error) => {
        if (error?.name !== 'CanceledError') setState({ phase: 'error', error })
      })
    return () => controller.abort()
  }, [status, page, reloadKey])

  function showLoading() {
    setState({ phase: 'loading' })
  }

  if (state.phase === 'error' && state.error?.status === 403) return <Forbidden />
  const lastPage = state.meta?.last_page ?? 1

  return (
    <div className="legacy-notif legacy-cart">
      <div className="notif-container">
        <div className="notif-header">
          <h1 style={{ fontFamily: 'Bricolage Grotesque', fontSize: '1.8rem', fontWeight: 700, marginBottom: 8, color: 'inherit' }}>Prescription review</h1>
          <p>Reservations with a prescription photo. Open one to see the photo and the text read from it.</p>
        </div>

        <div className="notif-filter">
          <label htmlFor="rxStatus">Status:</label>
          <select
            id="rxStatus"
            value={status}
            onChange={(event) => {
              showLoading()
              setStatus(event.target.value)
              setPage(1)
            }}
          >
            <option value="">All statuses</option>
            {STATUS_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>

        <div aria-live="polite">
          {state.phase === 'loading' ? <LoadingState>Loading prescriptions...</LoadingState> : null}
          {state.phase === 'error' ? (
            <ErrorState
              error={state.error}
              onRetry={() => {
                showLoading()
                setReloadKey((key) => key + 1)
              }}
            />
          ) : null}
          {state.phase === 'done' && state.unreadable > 0 ? (
            <p className="app-notice app-notice--warning">{state.unreadable} reservation(s) on this page could not be opened, so they are not listed. Try again in a moment.</p>
          ) : null}
          {state.phase === 'done' && state.rows.length === 0 ? (
            <EmptyState title="No prescriptions to review">
              {state.checked === 0 ? 'There are no reservations with this status.' : 'None of the reservations on this page has a prescription photo.'}
            </EmptyState>
          ) : null}
        </div>

        {state.phase === 'done' && state.rows.length > 0 ? (
          <ul className="items-list" style={{ listStyle: 'none', padding: 0 }}>
            {state.rows.map((r) => (
              <li key={r.id} className="cart-item">
                <div className="item-thumb" aria-hidden="true">
                  <i className="bi bi-file-medical"></i>
                </div>
                <div className="item-body">
                  <h2 className="item-name" style={{ fontSize: '1.1rem' }}>
                    <span className="code-display">{r.claim_code}</span> <StatusBadge status={r.status} />
                  </h2>
                  <p className="field-hint">
                    {isCeo ? `${branchLabel(names, r.branch_id)} - ` : ''}
                    {r.items.map((item) => `${item.medicine_name} x ${item.quantity}`).join(', ')} - created {formatDateTime(r.created_at)}
                  </p>
                  <p className="field-hint">Reading: {OCR_LABELS[r.ocr_status] ?? 'Not read'}</p>
                  <Link to={`/staff/reservations/${r.id}`} className="btn-outline btn-outline-blue">
                    Review<span className="visually-hidden"> reservation {r.claim_code}</span>
                  </Link>
                </div>
              </li>
            ))}
          </ul>
        ) : null}

        {state.phase === 'done' && lastPage > 1 ? (
          <nav className="notif-pager" aria-label="Pages of reservations">
            <button
              type="button"
              className="btn-outline btn-outline-blue"
              onClick={() => {
                showLoading()
                setPage((p) => Math.max(1, p - 1))
              }}
              disabled={page <= 1}
            >
              <i className="bi bi-chevron-left" aria-hidden="true"></i> Previous
            </button>
            <span>
              Page {state.meta?.page ?? page} of {lastPage}
            </span>
            <button
              type="button"
              className="btn-outline btn-outline-blue"
              onClick={() => {
                showLoading()
                setPage((p) => Math.min(lastPage, p + 1))
              }}
              disabled={page >= lastPage}
            >
              Next <i className="bi bi-chevron-right" aria-hidden="true"></i>
            </button>
          </nav>
        ) : null}
      </div>
    </div>
  )
}
