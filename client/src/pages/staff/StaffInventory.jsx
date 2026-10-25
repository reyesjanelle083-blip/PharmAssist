import { useEffect, useState } from 'react'
import { getBranchInventory } from '../../api/index.js'
import ErrorState from '../../components/ui/ErrorState.jsx'
import EmptyState from '../../components/ui/EmptyState.jsx'
import LoadingState from '../../components/ui/LoadingState.jsx'
import StockBadge from '../../components/ui/StockBadge.jsx'
import Forbidden from '../Forbidden.jsx'
import { branchLabel, useBranchNames } from '../../hooks/useBranchNames.js'
import { usePageTitle } from '../../hooks/usePageTitle.js'
import { useStaffBranch } from '../../hooks/useStaffBranch.js'
import { formatPeso } from '../../utils/format.js'

const PER_PAGE = 20
const STOCK_OPTIONS = [
  { value: 'available', label: 'Available' },
  { value: 'low_stock', label: 'Low stock' },
  { value: 'out_of_stock', label: 'Out of stock' },
]

function medicineLabel(medicine) {
  const brand = medicine.brand_name ? ` (${medicine.brand_name})` : ''
  return `${medicine.generic_name}${brand}`
}

/** Stock of one branch (view only). Staff see their own branch; the CEO picks a branch. The backend refuses any other branch with 403. */
export default function StaffInventory() {
  usePageTitle('Inventory - Staff')
  const names = useBranchNames()
  const { isCeo, branchId, branches, branchesFailed, setBranch } = useStaffBranch()
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [reloadKey, setReloadKey] = useState(0)
  const [state, setState] = useState({ phase: 'loading' })

  useEffect(() => {
    if (branchId === null) return undefined
    const controller = new AbortController()
    getBranchInventory(branchId, { status, page, perPage: PER_PAGE }, { signal: controller.signal })
      .then(({ data }) => setState({ phase: 'done', items: data.items ?? [], meta: data.meta ?? null }))
      .catch((error) => {
        if (error?.name !== 'CanceledError') setState({ phase: 'error', error })
      })
    return () => controller.abort()
  }, [branchId, status, page, reloadKey])

  function showLoading() {
    setState({ phase: 'loading' })
  }

  if (state.phase === 'error' && state.error?.status === 403) return <Forbidden />

  const lastPage = state.meta?.last_page ?? 1

  return (
    <div className="legacy-notif legacy-cart">
      <div className="notif-container">
        <div className="notif-header">
          <h1 style={{ fontFamily: 'Bricolage Grotesque', fontSize: '1.8rem', fontWeight: 700, marginBottom: 8, color: 'inherit' }}>Inventory</h1>
          <p>{branchId !== null ? `Stock at ${branchLabel(names, branchId)}. View only.` : 'Stock for your branch. View only.'}</p>
        </div>

        {branchId === null && !isCeo ? (
          <EmptyState title="No branch assigned">Your account is not tied to a branch, so there is no stock to show. Ask the branch manager or the CEO.</EmptyState>
        ) : null}
        {branchId === null && isCeo && !branchesFailed ? <LoadingState>Loading branches...</LoadingState> : null}
        {branchId === null && isCeo && branchesFailed ? <ErrorState error={{ code: 'NETWORK_ERROR' }} onRetry={() => window.location.reload()} /> : null}

        {branchId !== null ? (
          <div className="notif-filter">
            {isCeo ? (
              <>
                <label htmlFor="invBranch">Branch:</label>
                <select
                  id="invBranch"
                  value={branchId}
                  onChange={(event) => {
                    showLoading()
                    setBranch(Number(event.target.value))
                    setPage(1)
                  }}
                >
                  {branches.map((branch) => (
                    <option key={branch.id} value={branch.id}>
                      {branch.name}
                    </option>
                  ))}
                </select>
              </>
            ) : null}
            <label htmlFor="invStatus">Stock:</label>
            <select
              id="invStatus"
              value={status}
              onChange={(event) => {
                showLoading()
                setStatus(event.target.value)
                setPage(1)
              }}
            >
              <option value="">All</option>
              {STOCK_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </div>
        ) : null}

        <div aria-live="polite">
          {branchId !== null && state.phase === 'loading' ? <LoadingState>Loading stock...</LoadingState> : null}
          {state.phase === 'error' ? (
            <ErrorState
              error={state.error}
              onRetry={() => {
                showLoading()
                setReloadKey((key) => key + 1)
              }}
            />
          ) : null}
          {state.phase === 'done' && state.items.length === 0 ? (
            <EmptyState title="No stock found">{status ? 'No medicines match this stock filter.' : 'This branch has no stock rows yet.'}</EmptyState>
          ) : null}
        </div>

        {state.phase === 'done' && state.items.length > 0 ? (
          <>
            <table className="reservation-table">
              <caption className="visually-hidden">Stock at the branch</caption>
              <thead>
                <tr>
                  <th scope="col">Medicine</th>
                  <th scope="col">Strength</th>
                  <th scope="col">In stock</th>
                  <th scope="col">Reserved</th>
                  <th scope="col">Available</th>
                  <th scope="col">Alert at</th>
                  <th scope="col">Price</th>
                  <th scope="col">Expires</th>
                  <th scope="col">Status</th>
                </tr>
              </thead>
              <tbody>
                {state.items.map((row) => (
                  <tr key={row.id}>
                    <td data-label="Medicine">
                      {medicineLabel(row.medicine)}
                      {row.medicine.prescription_required ? <span className="field-hint"> (prescription)</span> : null}
                    </td>
                    <td data-label="Strength">{[row.medicine.strength, row.medicine.dosage_form].filter(Boolean).join(' ')}</td>
                    <td data-label="In stock">{row.stock_quantity}</td>
                    <td data-label="Reserved">{row.reserved_quantity}</td>
                    <td data-label="Available">{row.available_quantity}</td>
                    <td data-label="Alert at">{row.min_threshold}</td>
                    <td data-label="Price">{formatPeso(row.price)}</td>
                    <td data-label="Expires">{row.expiration_date ?? '-'}</td>
                    <td data-label="Status">
                      <StockBadge status={row.status} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            <nav className="notif-pager" aria-label="Pages of stock">
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
          </>
        ) : null}
      </div>
    </div>
  )
}
