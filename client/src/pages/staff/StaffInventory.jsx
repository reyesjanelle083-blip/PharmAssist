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
import { usePageTitle } from '../../hooks/usePageTitle.js'
import { useStaffBranch } from '../../hooks/useStaffBranch.js'
import { formatPeso } from '../../utils/format.js'
import Forbidden from '../Forbidden.jsx'

import '../../styles/staff-stock.css'

const PER_PAGE = 20

const STOCK_OPTIONS = [
  {
    value: 'available',
    label: 'Available',
  },
  {
    value: 'low_stock',
    label: 'Low stock',
  },
  {
    value: 'out_of_stock',
    label: 'Out of stock',
  },
]

function medicineLabel(medicine) {
  const brand =
    medicine.brand_name
      ? ` (${medicine.brand_name})`
      : ''

  return `${medicine.generic_name}${brand}`
}

function strengthLabel(medicine) {
  return [
    medicine.strength,
    medicine.dosage_form,
  ]
    .filter(Boolean)
    .join(' ')
}

export default function StaffInventory() {
  usePageTitle(
    'Inventory - Staff',
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
    status,
    setStatus,
  ] = useState('')

  const [
    page,
    setPage,
  ] = useState(1)

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

    getBranchInventory(
      branchId,
      {
        status,
        page,
        perPage: PER_PAGE,
      },
      {
        signal:
          controller.signal,
      },
    )
      .then(({ data }) => {
        setState({
          phase: 'done',
          items:
            data.items ??
            [],
          meta:
            data.meta ??
            null,
          updatedAt:
            new Date(),
        })
      })
      .catch((error) => {
        if (
          error?.name !==
          'CanceledError'
        ) {
          setState({
            phase: 'error',
            error,
          })
        }
      })

    return () =>
      controller.abort()
  }, [
    branchId,
    status,
    page,
    reloadKey,
  ])

  function showLoading() {
    setState({
      phase: 'loading',
    })
  }

  if (
    state.phase ===
      'error' &&
    state.error?.status === 403
  ) {
    return <Forbidden />
  }

  const lastPage =
    state.meta?.last_page ??
    1

  const total =
    state.meta?.total ??
    state.items?.length ??
    0

  const currentBranch =
    branchId !== null
      ? branchLabel(
          names,
          branchId,
        )
      : 'your branch'

  return (
    <div className="staff-stock-page">
      <section className="staff-stock-hero">
        <div>
          <span className="staff-stock-hero__eyebrow">
            Inventory
          </span>

          <h1>
            Medicine inventory
          </h1>

          <p>
            Review current stock,
            reserved quantities,
            available quantities,
            pricing, expiry dates,
            and stock status for{' '}
            {currentBranch}.
          </p>
        </div>

        <span className="staff-stock-hero__icon">
          <i
            className="bi bi-boxes"
            aria-hidden="true"
          ></i>
        </span>
      </section>

      {branchId === null &&
      !isCeo ? (
        <EmptyState title="No branch assigned">
          Your account is not tied
          to a branch, so there is
          no stock to show.
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
          <section
            className="staff-stock-filter"
            aria-label="Inventory filters"
          >
            <div className="staff-stock-filter__intro">
              <span>
                <i
                  className="bi bi-funnel"
                  aria-hidden="true"
                ></i>
              </span>

              <div>
                <strong>
                  Filter inventory
                </strong>

                <small>
                  View all medicines
                  or focus on a stock
                  condition.
                </small>
              </div>
            </div>

            <div className="staff-stock-filter__controls">
              {isCeo ? (
                <div className="staff-stock-filter__field">
                  <label htmlFor="invBranch">
                    Branch
                  </label>

                  <select
                    id="invBranch"
                    value={
                      branchId
                    }
                    onChange={(
                      event,
                    ) => {
                      showLoading()

                      setBranch(
                        Number(
                          event
                            .target
                            .value,
                        ),
                      )

                      setPage(1)
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

              <div className="staff-stock-filter__field">
                <label htmlFor="invStatus">
                  Stock status
                </label>

                <select
                  id="invStatus"
                  value={status}
                  onChange={(
                    event,
                  ) => {
                    showLoading()

                    setStatus(
                      event.target
                        .value,
                    )

                    setPage(1)
                  }}
                >
                  <option value="">
                    All stock
                  </option>

                  {STOCK_OPTIONS.map(
                    (option) => (
                      <option
                        key={
                          option.value
                        }
                        value={
                          option.value
                        }
                      >
                        {
                          option.label
                        }
                      </option>
                    ),
                  )}
                </select>
              </div>

              <button
                type="button"
                className="staff-stock-refresh"
                onClick={() => {
                  showLoading()

                  setReloadKey(
                    (key) =>
                      key + 1,
                  )
                }}
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
            <section className="staff-stock-summary">
              <div>
                <span className="staff-stock-summary__icon">
                  <i
                    className="bi bi-capsule"
                    aria-hidden="true"
                  ></i>
                </span>

                <div>
                  <strong>
                    {total}
                  </strong>

                  <span>
                    {status
                      ? `${
                          STOCK_OPTIONS.find(
                            (
                              option,
                            ) =>
                              option.value ===
                              status,
                          )?.label ??
                          status
                        } record${
                          total ===
                          1
                            ? ''
                            : 's'
                        }`
                      : `medicine record${
                          total ===
                          1
                            ? ''
                            : 's'
                        }`}
                  </span>
                </div>
              </div>

              <p>
                Stock at{' '}
                <strong>
                  {currentBranch}
                </strong>
              </p>

              <span className="staff-stock-summary__view">
                <i
                  className="bi bi-eye"
                  aria-hidden="true"
                ></i>

                View only
              </span>
            </section>
          ) : null}

          <div aria-live="polite">
            {state.phase ===
            'loading' ? (
              <LoadingState>
                Loading stock...
              </LoadingState>
            ) : null}

            {state.phase ===
            'error' ? (
              <ErrorState
                error={
                  state.error
                }
                onRetry={() => {
                  showLoading()

                  setReloadKey(
                    (key) =>
                      key + 1,
                  )
                }}
              />
            ) : null}

            {state.phase ===
              'done' &&
            state.items.length ===
              0 ? (
              <EmptyState title="No stock found">
                {status
                  ? 'No medicines match this stock filter.'
                  : 'This branch has no stock rows yet.'}
              </EmptyState>
            ) : null}
          </div>

          {state.phase ===
            'done' &&
          state.items.length >
            0 ? (
            <>
              <div className="staff-stock-list-heading">
                <div>
                  <span>
                    Inventory list
                  </span>

                  <h2>
                    {status
                      ? `${
                          STOCK_OPTIONS.find(
                            (
                              option,
                            ) =>
                              option.value ===
                              status,
                          )?.label ??
                          status
                        } medicines`
                      : 'All medicines'}
                  </h2>
                </div>

                <small>
                  Page{' '}
                  {state.meta
                    ?.page ??
                    page}{' '}
                  of {lastPage}
                </small>
              </div>

              <div className="staff-stock-table-wrap">
                <table className="staff-stock-table">
                  <caption className="visually-hidden">
                    Medicine inventory
                    at the selected
                    branch
                  </caption>

                  <thead>
                    <tr>
                      <th scope="col">
                        Medicine
                      </th>

                      <th scope="col">
                        Strength
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
                        Price
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
                    {state.items.map(
                      (row) => (
                        <tr key={row.id}>
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
                                {row
                                  .medicine
                                  .prescription_required
                                  ? 'Prescription required'
                                  : 'No prescription required'}
                              </span>
                            </div>
                          </td>

                          <td data-label="Strength">
                            {strengthLabel(
                              row.medicine,
                            ) || '—'}
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

                          <td data-label="Price">
                            <strong>
                              {formatPeso(
                                row.price,
                              )}
                            </strong>
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

              {lastPage > 1 ? (
                <nav
                  className="staff-stock-pager"
                  aria-label="Pages of stock"
                >
                  <button
                    type="button"
                    onClick={() => {
                      showLoading()

                      setPage(
                        (current) =>
                          Math.max(
                            1,
                            current -
                              1,
                          ),
                      )
                    }}
                    disabled={
                      page <= 1
                    }
                  >
                    <i
                      className="bi bi-chevron-left"
                      aria-hidden="true"
                    ></i>

                    Previous
                  </button>

                  <span>
                    Page{' '}
                    {state.meta
                      ?.page ??
                      page}{' '}
                    of {lastPage}
                  </span>

                  <button
                    type="button"
                    onClick={() => {
                      showLoading()

                      setPage(
                        (current) =>
                          Math.min(
                            lastPage,
                            current +
                              1,
                          ),
                      )
                    }}
                    disabled={
                      page >=
                      lastPage
                    }
                  >
                    Next

                    <i
                      className="bi bi-chevron-right"
                      aria-hidden="true"
                    ></i>
                  </button>
                </nav>
              ) : null}
            </>
          ) : null}
        </>
      ) : null}
    </div>
  )
}