import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { listReservations } from '../../api/index.js'
import ErrorState from '../../components/ui/ErrorState.jsx'
import Button from '../../components/ui/Button.jsx'
import EmptyState from '../../components/ui/EmptyState.jsx'
import LoadingState from '../../components/ui/LoadingState.jsx'
import StatusBadge from '../../components/ui/StatusBadge.jsx'
import Forbidden from '../Forbidden.jsx'
import { useAuth } from '../../context/useAuth.js'
import { branchLabel, useBranchNames } from '../../hooks/useBranchNames.js'
import { useAutoRefresh } from '../../hooks/useAutoRefresh.js'
import { usePageTitle } from '../../hooks/usePageTitle.js'
import { formatDateTime, formatPeso } from '../../utils/format.js'
import { STATUS_OPTIONS } from '../../utils/status.js'

const PER_PAGE = 10

/**
 * Reservations for staff: a technician, assistant or branch manager sees their own branch (the backend ignores any other branch),
 * the CEO sees all branches, or one chosen branch, read-only. New requests are checked for every 20 s.
 */
export default function StaffReservations() {
  usePageTitle('Reservations - Staff')
  const { role } = useAuth()
  const names = useBranchNames()
  const isCeo = role === 'ceo'
  const [status, setStatus] = useState('pending')
  const [branch, setBranch] = useState('')
  const [page, setPage] = useState(1)
  const [reloadKey, setReloadKey] = useState(0)
  const [state, setState] = useState({ phase: 'loading', items: [], meta: null, updatedAt: null, stale: false })

  useEffect(() => {
    const controller = new AbortController()
    listReservations({ status, page, perPage: PER_PAGE, branchId: isCeo ? branch : undefined }, { signal: controller.signal })
      .then(({ data }) => setState({ phase: 'done', items: data.items ?? [], meta: data.meta ?? null, updatedAt: new Date(), stale: false }))
      .catch((caught) => {
        if (caught?.name === 'CanceledError') return
        setState((current) => (current.phase === 'done' ? { ...current, stale: true } : { phase: 'error', error: caught, items: [], meta: null, updatedAt: null, stale: false }))
      })
    return () => controller.abort()
  }, [status, branch, page, reloadKey, isCeo])

  useAutoRefresh(() => setReloadKey((key) => key + 1), { enabled: state.phase === 'done' })

  function showLoading() {
    setState((current) => ({ ...current, phase: 'loading' }))
  }

  const { phase, items, meta, error, updatedAt, stale } = state
  const lastPage = meta?.last_page ?? 1

  if (phase === 'error' && error?.status === 403) return <Forbidden />

  return (
    <div className="legacy-notif legacy-cart">
      <div className="notif-container">
        <div className="notif-header">
          <h1 style={{ fontFamily: 'Bricolage Grotesque', fontSize: '1.8rem', fontWeight: 700, marginBottom: 8, color: 'inherit' }}>Reservations</h1>
          <p>{isCeo ? 'All branches. The CEO can look but not change a reservation.' : 'Reservations for your branch. Newest first.'}</p>
        </div>

        <div className="notif-filter">
          <label htmlFor="staffStatus">Status:</label>
          <select
            id="staffStatus"
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
          {isCeo ? (
            <>
              <label htmlFor="staffBranch">Branch:</label>
              <select
                id="staffBranch"
                value={branch}
                onChange={(event) => {
                  showLoading()
                  setBranch(event.target.value)
                  setPage(1)
                }}
              >
                <option value="">All branches</option>
                {Object.entries(names).map(([id, name]) => (
                  <option key={id} value={id}>
                    {name}
                  </option>
                ))}
              </select>
            </>
          ) : null}
        </div>

        <div aria-live="polite">
          {phase === 'loading' ? <LoadingState>Loading reservations...</LoadingState> : null}
          {phase === 'error' ? (
            <ErrorState
              error={error}
              onRetry={() => {
                showLoading()
                setReloadKey((key) => key + 1)
              }}
            />
          ) : null}
          {phase === 'done' && stale ? <p className="app-notice app-notice--warning">Could not refresh just now. Showing the last list we loaded.</p> : null}
          {phase === 'done' && items.length === 0 ? (
            <EmptyState title="No reservations found">{status === 'pending' ? 'There are no pending reservations right now.' : 'Try another status.'}</EmptyState>
          ) : null}
        </div>

        {phase === 'done' && items.length > 0 ? (
          <>
            <p className="field-hint">
              Updated {formatDateTime(updatedAt)} - this list checks for new reservations on its own.{' '}
              <Button onClick={() => setReloadKey((key) => key + 1)}>
                <i className="bi bi-arrow-clockwise" aria-hidden="true"></i> Refresh
              </Button>
            </p>
            <table className="reservation-table">
              <caption className="visually-hidden">Reservations, newest first</caption>
              <thead>
                <tr>
                  <th scope="col">Claim code</th>
                  {isCeo ? <th scope="col">Branch</th> : null}
                  <th scope="col">Items</th>
                  <th scope="col">Total price</th>
                  <th scope="col">Created</th>
                  <th scope="col">Expires</th>
                  <th scope="col">Status</th>
                  <th scope="col">Details</th>
                </tr>
              </thead>
              <tbody>
                {items.map((row) => (
                  <tr key={row.id}>
                    <td data-label="Claim code">
                      <span className="code-display">{row.claim_code}</span>
                    </td>
                    {isCeo ? <td data-label="Branch">{branchLabel(names, row.branch_id)}</td> : null}
                    <td data-label="Items">{row.item_count}</td>
                    <td data-label="Total price">{formatPeso(row.total_price)}</td>
                    <td data-label="Created">{formatDateTime(row.created_at)}</td>
                    <td data-label="Expires">{formatDateTime(row.expires_at)}</td>
                    <td data-label="Status">
                      <StatusBadge status={row.status} />
                    </td>
                    <td data-label="Details">
                      <Link to={`/staff/reservations/${row.id}`} className="btn-outline btn-outline-blue">
                        Open<span className="visually-hidden"> reservation {row.claim_code}</span>
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            <nav className="notif-pager" aria-label="Pages of reservations">
              <button
                type="button"
                className="btn-outline btn-outline-blue"
                onClick={() => {
                  showLoading()
                  setPage((p) => Math.max(1, p - 1))
                }}
                disabled={page <= 1 || phase === 'loading'}
              >
                <i className="bi bi-chevron-left" aria-hidden="true"></i> Previous
              </button>
              <span>
                Page {meta?.page ?? page} of {lastPage}
              </span>
              <button
                type="button"
                className="btn-outline btn-outline-blue"
                onClick={() => {
                  showLoading()
                  setPage((p) => Math.min(lastPage, p + 1))
                }}
                disabled={page >= lastPage || phase === 'loading'}
              >
                Next <i className="bi bi-chevron-right" aria-hidden="true"></i>
              </button>
            </nav>
          </>
        ) : null}
      </div>
    </div>
  )
}
