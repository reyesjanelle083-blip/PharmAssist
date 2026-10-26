import {
  useEffect,
  useState,
} from 'react'

import {
  getBranchInventory,
} from '../../api/index.js'

import EmptyState from '../../components/ui/EmptyState.jsx'
import ErrorState from '../../components/ui/ErrorState.jsx'
import LoadingState from '../../components/ui/LoadingState.jsx'
import StockBadge from '../../components/ui/StockBadge.jsx'
import {
  branchLabel,
  useBranchNames,
} from '../../hooks/useBranchNames.js'
import { useAutoRefresh } from '../../hooks/useAutoRefresh.js'
import { usePageTitle } from '../../hooks/usePageTitle.js'
import { useStaffBranch } from '../../hooks/useStaffBranch.js'
import { formatDateTime } from '../../utils/format.js'
import Forbidden from '../Forbidden.jsx'

import '../../styles/staff-stock.css'

const LIMIT = 100

function medicineLabel(medicine) {
  const brand =
    medicine.brand_name
      ? ` (${medicine.brand_name})`
      : ''

  return `${medicine.generic_name}${brand}`
}

function medicineDetail(medicine) {
  return [
    medicine.strength,
    medicine.dosage_form,
  ]
    .filter(Boolean)
    .join(' ')
}

export default function StaffStockAlerts() {
  usePageTitle(
    'Stock alerts - Staff',
  )

  const names =
    useBranchNames()

  const {
    isCeo,
    branchId,
    branches,
    branchesFailed,
    setBranch,
  } = useStaffBranch()

  const [
    reloadKey,
    setReloadKey,
  ] = useState(0)

  const [
    state,
    setState,
  ] = useState({
    phase: 'loading',
  })

  useEffect(() => {
    if (branchId === null) {
      return undefined
    }

    const controller =
      new AbortController()

    const options = {
      signal:
        controller.signal,
    }

    Promise.all([
      getBranchInventory(
        branchId,
        {
          status:
            'out_of_stock',
          page: 1,
          perPage: LIMIT,
        },
        options,
      ),

      getBranchInventory(
        branchId,
        {
          status:
            'low_stock',
          page: 1,
          perPage: LIMIT,
        },
        options,
      ),
    ])
      .then(([out, low]) => {
        setState({
          phase: 'done',

          out:
            out.data.items ??
            [],

          low:
            low.data.items ??
            [],

          outTotal:
            out.data.meta
              ?.total ??
            out.data.items
              ?.length ??
            0,

          lowTotal:
            low.data.meta
              ?.total ??
            low.data.items
              ?.length ??
            0,

          updatedAt:
            new Date(),

          stale: false,
        })
      })
      .catch((error) => {
        if (
          error?.name ===
          'CanceledError'
        ) {
          return
        }

        setState(
          (current) =>
            current.phase ===
            'done'
              ? {
                  ...current,
                  stale: true,
                }
              : {
                  phase: 'error',
                  error,
                },
        )
      })

    return () =>
      controller.abort()
  }, [
    branchId,
    reloadKey,
  ])

  useAutoRefresh(
    () =>
      setReloadKey(
        (key) =>
          key + 1,
      ),
    {
      enabled:
        state.phase ===
        'done',

      intervalMs: 60000,
    },
  )

  if (
    state.phase ===
      'error' &&
    state.error?.status === 403
  ) {
    return <Forbidden />
  }

  const rows =
    state.phase ===
    'done'
      ? [
          ...state.out,
          ...state.low,
        ]
      : []

  const totalAlerts =
    state.phase ===
    'done'
      ? state.outTotal +
        state.lowTotal
      : 0

  const truncated =
    state.phase ===
      'done' &&
    (state.outTotal >
      state.out.length ||
      state.lowTotal >
        state.low.length)

  const currentBranch =
    branchId !== null
      ? branchLabel(
          names,
          branchId,
        )
      : 'your branch'

  return (
    <div className="staff-stock-page">
      <section className="staff-stock-hero staff-stock-hero--alerts">
        <div>
          <span className="staff-stock-hero__eyebrow">
            Stock Alerts
          </span>

          <h1>
            Medicines needing attention
          </h1>

          <p>
            Monitor low-stock and
            out-of-stock medicines at{' '}
            {currentBranch}.
          </p>
        </div>

        <span className="staff-stock-hero__icon staff-stock-hero__icon--alert">
          <i
            className="bi bi-exclamation-triangle"
            aria-hidden="true"
          ></i>
        </span>
      </section>

      {branchId === null &&
      !isCeo ? (
        <EmptyState title="No branch assigned">
          Your account is not tied
          to a branch, so there are
          no alerts to show.
        </EmptyState>
      ) : null}

      {branchId === null &&
      isCeo &&
      !branchesFailed ? (
        <LoadingState>
          Loading branches...
        </LoadingState>
      ) : null}

      {branchId === null &&
      isCeo &&
      branchesFailed ? (
        <ErrorState
          error={{
            code: 'NETWORK_ERROR',
          }}
          onRetry={() =>
            window.location.reload()
          }
        />
      ) : null}

      {branchId !== null ? (
        <>
          <section className="staff-stock-filter">
            <div className="staff-stock-filter__intro">
              <span>
                <i
                  className="bi bi-bell"
                  aria-hidden="true"
                ></i>
              </span>

              <div>
                <strong>
                  Stock alert monitor
                </strong>

                <small>
                  Out-of-stock
                  medicines are listed
                  before low-stock
                  medicines.
                </small>
              </div>
            </div>

            <div className="staff-stock-filter__controls">
              {isCeo ? (
                <div className="staff-stock-filter__field">
                  <label htmlFor="alertBranch">
                    Branch
                  </label>

                  <select
                    id="alertBranch"
                    value={
                      branchId
                    }
                    onChange={(
                      event,
                    ) => {
                      setState({
                        phase:
                          'loading',
                      })

                      setBranch(
                        Number(
                          event
                            .target
                            .value,
                        ),
                      )
                    }}
                  >
                    {branches.map(
                      (branch) => (
                        <option
                          key={
                            branch.id
                          }
                          value={
                            branch.id
                          }
                        >
                          {
                            branch.name
                          }
                        </option>
                      ),
                    )}
                  </select>
                </div>
              ) : null}

              <button
                type="button"
                className="staff-stock-refresh"
                onClick={() =>
                  setReloadKey(
                    (key) =>
                      key + 1,
                  )
                }
              >
                <i
                  className="bi bi-arrow-clockwise"
                  aria-hidden="true"
                ></i>

                Refresh
              </button>
            </div>
          </section>

          {state.phase ===
          'done' ? (
            <div className="staff-alert-metrics">
              <article className="staff-alert-metric staff-alert-metric--all">
                <span>
                  <i
                    className="bi bi-bell"
                    aria-hidden="true"
                  ></i>
                </span>

                <div>
                  <strong>
                    {totalAlerts}
                  </strong>

                  <small>
                    Total alerts
                  </small>
                </div>
              </article>

              <article className="staff-alert-metric staff-alert-metric--out">
                <span>
                  <i
                    className="bi bi-x-circle"
                    aria-hidden="true"
                  ></i>
                </span>

                <div>
                  <strong>
                    {
                      state.outTotal
                    }
                  </strong>

                  <small>
                    Out of stock
                  </small>
                </div>
              </article>

              <article className="staff-alert-metric staff-alert-metric--low">
                <span>
                  <i
                    className="bi bi-exclamation-triangle"
                    aria-hidden="true"
                  ></i>
                </span>

                <div>
                  <strong>
                    {
                      state.lowTotal
                    }
                  </strong>

                  <small>
                    Low stock
                  </small>
                </div>
              </article>

              <div className="staff-alert-updated">
                <span>
                  Last updated
                </span>

                <strong>
                  {formatDateTime(
                    state.updatedAt,
                  )}
                </strong>

                <small>
                  {currentBranch}
                </small>
              </div>
            </div>
          ) : null}

          <div aria-live="polite">
            {branchId !== null &&
            state.phase ===
              'loading' ? (
              <LoadingState>
                Loading stock alerts...
              </LoadingState>
            ) : null}

            {state.phase ===
            'error' ? (
              <ErrorState
                error={
                  state.error
                }
                onRetry={() => {
                  setState({
                    phase:
                      'loading',
                  })

                  setReloadKey(
                    (key) =>
                      key + 1,
                  )
                }}
              />
            ) : null}

            {state.phase ===
              'done' &&
            state.stale ? (
              <p className="app-notice app-notice--warning">
                Could not refresh
                just now. Showing the
                last stock alerts
                loaded successfully.
              </p>
            ) : null}

            {state.phase ===
              'done' &&
            rows.length === 0 ? (
              <EmptyState title="No stock alerts">
                Every medicine at
                this branch is above
                its alert level.
              </EmptyState>
            ) : null}
          </div>

          {state.phase ===
            'done' &&
          rows.length >
            0 ? (
            <>
              <div className="staff-stock-list-heading">
                <div>
                  <span>
                    Priority list
                  </span>

                  <h2>
                    Stock alerts
                  </h2>
                </div>

                <small>
                  Out of stock first
                </small>
              </div>

              <div className="staff-stock-table-wrap">
                <table className="staff-stock-table staff-alert-table">
                  <caption className="visually-hidden">
                    Medicines that
                    are low or out of
                    stock
                  </caption>

                  <thead>
                    <tr>
                      <th scope="col">
                        Medicine
                      </th>

                      <th
                        scope="col"
                        className="staff-stock-table__number"
                      >
                        In stock
                      </th>

                      <th
                        scope="col"
                        className="staff-stock-table__number"
                      >
                        Reserved
                      </th>

                      <th
                        scope="col"
                        className="staff-stock-table__number"
                      >
                        Available
                      </th>

                      <th
                        scope="col"
                        className="staff-stock-table__number"
                      >
                        Alert at
                      </th>

                      <th scope="col">
                        Expires
                      </th>

                      <th scope="col">
                        Status
                      </th>
                    </tr>
                  </thead>

                  <tbody>
                    {rows.map(
                      (row) => (
                        <tr
                          key={row.id}
                          className={
                            row.status ===
                            'out_of_stock'
                              ? 'staff-alert-row staff-alert-row--out'
                              : 'staff-alert-row staff-alert-row--low'
                          }
                        >
                          <td
                            data-label="Medicine"
                            className="staff-stock-table__medicine"
                          >
                            <span className="staff-stock-medicine-icon">
                              <i
                                className="bi bi-capsule-pill"
                                aria-hidden="true"
                              ></i>
                            </span>

                            <div>
                              <strong>
                                {medicineLabel(
                                  row.medicine,
                                )}
                              </strong>

                              <span>
                                {medicineDetail(
                                  row.medicine,
                                )}
                              </span>
                            </div>
                          </td>

                          <td
                            data-label="In stock"
                            className="staff-stock-table__number"
                          >
                            {
                              row.stock_quantity
                            }
                          </td>

                          <td
                            data-label="Reserved"
                            className="staff-stock-table__number"
                          >
                            {
                              row.reserved_quantity
                            }
                          </td>

                          <td
                            data-label="Available"
                            className="staff-stock-table__number"
                          >
                            <strong className="staff-stock-available">
                              {
                                row.available_quantity
                              }
                            </strong>
                          </td>

                          <td
                            data-label="Alert at"
                            className="staff-stock-table__number"
                          >
                            {
                              row.min_threshold
                            }
                          </td>

                          <td data-label="Expires">
                            {
                              row.expiration_date ??
                              '—'
                            }
                          </td>

                          <td data-label="Status">
                            <StockBadge
                              status={
                                row.status
                              }
                            />
                          </td>
                        </tr>
                      ),
                    )}
                  </tbody>
                </table>
              </div>

              {truncated ? (
                <p className="staff-stock-truncated">
                  <i
                    className="bi bi-info-circle"
                    aria-hidden="true"
                  ></i>

                  Showing the first{' '}
                  {LIMIT} records
                  from each alert
                  group. Open the
                  Inventory page for
                  the complete stock
                  list.
                </p>
              ) : null}
            </>
          ) : null}
        </>
      ) : null}
    </div>
  )
}