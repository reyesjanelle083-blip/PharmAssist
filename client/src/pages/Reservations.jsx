import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { listReservations } from '../api/index.js'
import ErrorMessage from '../components/ErrorMessage.jsx'
import Button from '../components/ui/Button.jsx'
import EmptyState from '../components/ui/EmptyState.jsx'
import LoadingState from '../components/ui/LoadingState.jsx'
import StatusBadge from '../components/ui/StatusBadge.jsx'
import { STATUS_OPTIONS } from '../utils/status.js'
import { branchLabel, useBranchNames } from '../hooks/useBranchNames.js'
import { usePageTitle } from '../hooks/usePageTitle.js'
import { formatDateTime, formatPeso } from '../utils/format.js'

const PER_PAGE = 10

/**
 * The customer's reservation history. The old site had this as "Reservation Notifications" (user_notif.php): a white container, a
 * centred header, a status filter and a table with a coloured status. Same look here, with paging and a link to each reservation.
 */
export default function Reservations() {
  usePageTitle('My Reservations')
  const names = useBranchNames()
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [state, setState] = useState({ phase: 'loading', items: [], meta: null })
  const heading = useRef(null)

  useEffect(() => {
    const controller = new AbortController()
    listReservations({ status, page, perPage: PER_PAGE }, { signal: controller.signal })
      .then(({ data }) => setState({ phase: 'done', items: data.items ?? [], meta: data.meta ?? null }))
      .catch((error) => {
        if (error?.name !== 'CanceledError') setState({ phase: 'error', error, items: [], meta: null })
      })
    return () => controller.abort()
  }, [status, page])

  const { phase, items, meta, error } = state
  const lastPage = meta?.last_page ?? 1

  return (
    <div className="legacy-notif legacy-cart">
      <div className="notif-container">
        <div className="notif-header">
          <h1 ref={heading} tabIndex={-1} style={{ fontFamily: 'Bricolage Grotesque', fontSize: '1.8rem', fontWeight: 700, marginBottom: 8, color: 'inherit' }}>
            My Reservations
          </h1>
          <p>Track your medicine reservations. Newest first.</p>
        </div>

        <div className="notif-filter">
          <label htmlFor="statusFilter">Filter by Status:</label>
          <select
            id="statusFilter"
            value={status}
            onChange={(event) => {
              setState((current) => ({ ...current, phase: 'loading' }))
              setStatus(event.target.value)
              setPage(1)
            }}
          >
            <option value="">All Statuses</option>
            {STATUS_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>

        <div aria-live="polite">
          {phase === 'loading' ? <LoadingState>Loading your reservations...</LoadingState> : null}
          {phase === 'error' ? <ErrorMessage error={error} /> : <ErrorMessage />}

          {phase === 'done' && items.length === 0 ? (
            <EmptyState
              title="No reservations found"
              actions={<Button to="/">Search medicines</Button>}
            >
              {status ? 'Try another status.' : 'Make a reservation to see it here!'}
            </EmptyState>
          ) : null}
        </div>

        {phase === 'done' && items.length > 0 ? (
          <>
            <table className="reservation-table">
              <caption className="visually-hidden">Your reservations, newest first</caption>
              <thead>
                <tr>
                  <th scope="col">Claim code</th>
                  <th scope="col">Branch</th>
                  <th scope="col">Items</th>
                  <th scope="col">Total price</th>
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
                    <td data-label="Branch">{branchLabel(names, row.branch_id)}</td>
                    <td data-label="Items">{row.item_count}</td>
                    <td data-label="Total price">{formatPeso(row.total_price)}</td>
                    <td data-label="Expires">{formatDateTime(row.expires_at)}</td>
                    <td data-label="Status">
                      <StatusBadge status={row.status} />
                    </td>
                    <td data-label="Details">
                      <Link to={`/reservations/${row.id}`} className="btn-outline btn-outline-blue">
                        View<span className="visually-hidden"> reservation {row.claim_code}</span>
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            <nav className="notif-pager" aria-label="Pages of reservations">
              <button type="button" className="btn-outline btn-outline-blue" onClick={() => { setState((c) => ({ ...c, phase: 'loading' })); setPage((p) => Math.max(1, p - 1)) }} disabled={page <= 1 || phase === 'loading'}>
                <i className="bi bi-chevron-left" aria-hidden="true"></i> Previous
              </button>
              <span>
                Page {meta?.page ?? page} of {lastPage}
              </span>
              <button type="button" className="btn-outline btn-outline-blue" onClick={() => { setState((c) => ({ ...c, phase: 'loading' })); setPage((p) => Math.min(lastPage, p + 1)) }} disabled={page >= lastPage || phase === 'loading'}>
                Next <i className="bi bi-chevron-right" aria-hidden="true"></i>
              </button>
            </nav>
          </>
        ) : null}
      </div>
    </div>
  )
}
