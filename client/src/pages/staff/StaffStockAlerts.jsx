import { useEffect, useState } from 'react'
import { getBranchInventory } from '../../api/index.js'
import ErrorState from '../../components/ui/ErrorState.jsx'
import EmptyState from '../../components/ui/EmptyState.jsx'
import LoadingState from '../../components/ui/LoadingState.jsx'
import StockBadge from '../../components/ui/StockBadge.jsx'
import Forbidden from '../Forbidden.jsx'
import { branchLabel, useBranchNames } from '../../hooks/useBranchNames.js'
import { useAutoRefresh } from '../../hooks/useAutoRefresh.js'
import { usePageTitle } from '../../hooks/usePageTitle.js'
import { useStaffBranch } from '../../hooks/useStaffBranch.js'
import { formatDateTime } from '../../utils/format.js'

const LIMIT = 100

function medicineLabel(medicine) {
  const brand = medicine.brand_name ? ` (${medicine.brand_name})` : ''
  return `${medicine.generic_name}${brand} ${[medicine.strength, medicine.dosage_form].filter(Boolean).join(' ')}`.trim()
}

/**
 * Stock alerts, worked out from the branch's real inventory: every medicine whose stock is at or below its alert level (low stock)
 * or at zero (out of stock). Nothing is stored here, so it always matches the Inventory page. Out of stock comes first.
 */
export default function StaffStockAlerts() {
  usePageTitle('Stock alerts - Staff')
  const names = useBranchNames()
  const { isCeo, branchId, branches, branchesFailed, setBranch } = useStaffBranch()
  const [reloadKey, setReloadKey] = useState(0)
  const [state, setState] = useState({ phase: 'loading' })

  useEffect(() => {
    if (branchId === null) return undefined
    const controller = new AbortController()
    const options = { signal: controller.signal }
    Promise.all([
      getBranchInventory(branchId, { status: 'out_of_stock', page: 1, perPage: LIMIT }, options),
      getBranchInventory(branchId, { status: 'low_stock', page: 1, perPage: LIMIT }, options),
    ])
      .then(([out, low]) =>
        setState({
          phase: 'done',
          out: out.data.items ?? [],
          low: low.data.items ?? [],
          outTotal: out.data.meta?.total ?? (out.data.items ?? []).length,
          lowTotal: low.data.meta?.total ?? (low.data.items ?? []).length,
          updatedAt: new Date(),
          stale: false,
        }),
      )
      .catch((error) => {
        if (error?.name === 'CanceledError') return
        setState((current) => (current.phase === 'done' ? { ...current, stale: true } : { phase: 'error', error }))
      })
    return () => controller.abort()
  }, [branchId, reloadKey])

  useAutoRefresh(() => setReloadKey((key) => key + 1), { enabled: state.phase === 'done', intervalMs: 60000 })

  if (state.phase === 'error' && state.error?.status === 403) return <Forbidden />

  const rows = state.phase === 'done' ? [...state.out, ...state.low] : []
  const truncated = state.phase === 'done' && (state.outTotal > state.out.length || state.lowTotal > state.low.length)

  return (
    <div className="legacy-notif legacy-cart">
      <div className="notif-container">
        <div className="notif-header">
          <h1 style={{ fontFamily: 'Bricolage Grotesque', fontSize: '1.8rem', fontWeight: 700, marginBottom: 8, color: 'inherit' }}>Stock alerts</h1>
          <p>{branchId !== null ? `Medicines running low at ${branchLabel(names, branchId)}.` : 'Medicines running low at your branch.'}</p>
        </div>

        {branchId === null && !isCeo ? (
          <EmptyState title="No branch assigned">Your account is not tied to a branch, so there are no alerts to show.</EmptyState>
        ) : null}
        {branchId === null && isCeo && !branchesFailed ? <LoadingState>Loading branches...</LoadingState> : null}
        {branchId === null && isCeo && branchesFailed ? <ErrorState error={{ code: 'NETWORK_ERROR' }} onRetry={() => window.location.reload()} /> : null}

        {isCeo && branchId !== null ? (
          <div className="notif-filter">
            <label htmlFor="alertBranch">Branch:</label>
            <select
              id="alertBranch"
              value={branchId}
              onChange={(event) => {
                setState({ phase: 'loading' })
                setBranch(Number(event.target.value))
              }}
            >
              {branches.map((branch) => (
                <option key={branch.id} value={branch.id}>
                  {branch.name}
                </option>
              ))}
            </select>
          </div>
        ) : null}

        <div aria-live="polite">
          {branchId !== null && state.phase === 'loading' ? <LoadingState>Loading stock alerts...</LoadingState> : null}
          {state.phase === 'error' ? (
            <ErrorState
              error={state.error}
              onRetry={() => {
                setState({ phase: 'loading' })
                setReloadKey((key) => key + 1)
              }}
            />
          ) : null}
          {state.phase === 'done' && state.stale ? <p className="app-notice app-notice--warning">Could not refresh just now. Showing the last alerts we loaded.</p> : null}
          {state.phase === 'done' && rows.length === 0 ? <EmptyState title="No stock alerts">Every medicine at this branch is above its alert level.</EmptyState> : null}
        </div>

        {state.phase === 'done' && rows.length > 0 ? (
          <>
            <p className="field-hint">
              {state.outTotal} out of stock, {state.lowTotal} low. Updated {formatDateTime(state.updatedAt)}.
              {truncated ? ` Showing the first ${LIMIT} of each; the Inventory page lists the rest.` : ''}
            </p>
            <table className="reservation-table">
              <caption className="visually-hidden">Medicines that are low or out of stock</caption>
              <thead>
                <tr>
                  <th scope="col">Medicine</th>
                  <th scope="col">In stock</th>
                  <th scope="col">Reserved</th>
                  <th scope="col">Alert at</th>
                  <th scope="col">Expires</th>
                  <th scope="col">Status</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id}>
                    <td data-label="Medicine">{medicineLabel(row.medicine)}</td>
                    <td data-label="In stock">{row.stock_quantity}</td>
                    <td data-label="Reserved">{row.reserved_quantity}</td>
                    <td data-label="Alert at">{row.min_threshold}</td>
                    <td data-label="Expires">{row.expiration_date ?? '-'}</td>
                    <td data-label="Status">
                      <StockBadge status={row.status} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </>
        ) : null}
      </div>
    </div>
  )
}
