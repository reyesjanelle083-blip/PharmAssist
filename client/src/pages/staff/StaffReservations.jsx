import {
  useEffect,
  useState,
} from 'react'

import { Link } from 'react-router-dom'

import {
  listReservations,
} from '../../api/index.js'

import EmptyState from '../../components/ui/EmptyState.jsx'
import ErrorState from '../../components/ui/ErrorState.jsx'
import LoadingState from '../../components/ui/LoadingState.jsx'
import StatusBadge from '../../components/ui/StatusBadge.jsx'
import { useAuth } from '../../context/useAuth.js'
import {
  branchLabel,
  useBranchNames,
} from '../../hooks/useBranchNames.js'
import { useAutoRefresh } from '../../hooks/useAutoRefresh.js'
import { usePageTitle } from '../../hooks/usePageTitle.js'
import {
  formatDateTime,
  formatPeso,
} from '../../utils/format.js'
import {
  STATUS_OPTIONS,
} from '../../utils/status.js'

import Forbidden from '../Forbidden.jsx'

import '../../styles/staff-reservations.css'

const PER_PAGE = 10

function statusDescription(status) {
  switch (status) {
    case 'pending':
      return 'Waiting for branch review.'

    case 'approved':
      return 'Approved and waiting for customer pickup.'

    case 'completed':
      return 'Medicine was successfully released to the customer.'

    case 'rejected':
      return 'The reservation was rejected by authorized branch staff.'

    case 'cancelled':
      return 'The reservation was cancelled by the customer.'

    case 'expired':
      return 'The reservation hold period has ended.'

    default:
      return 'All reservation records matching the current filters.'
  }
}

function statusLabel(status) {
  return (
    STATUS_OPTIONS.find(
      (option) =>
        option.value === status,
    )?.label ??
    status
  )
}

export default function StaffReservations() {
  usePageTitle(
    'Reservations - Staff',
  )

  const { role } = useAuth()

  const names =
    useBranchNames()

  const isCeo =
    role === 'ceo'

  const [
    status,
    setStatus,
  ] = useState('pending')

  const [
    branch,
    setBranch,
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
    items: [],
    meta: null,
    updatedAt: null,
    stale: false,
  })

  useEffect(() => {
    const controller =
      new AbortController()

    listReservations(
      {
        status,
        page,
        perPage: PER_PAGE,

        branchId:
          isCeo
            ? branch
            : undefined,
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

          stale: false,
        })
      })
      .catch((caught) => {
        if (
          caught?.name ===
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
                  error: caught,
                  items: [],
                  meta: null,
                  updatedAt: null,
                  stale: false,
                },
        )
      })

    return () =>
      controller.abort()
  }, [
    status,
    branch,
    page,
    reloadKey,
    isCeo,
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

      intervalMs: 20000,
    },
  )

  function showLoading() {
    setState(
      (current) => ({
        ...current,
        phase: 'loading',
      }),
    )
  }

  const {
    phase,
    items,
    meta,
    error,
    updatedAt,
    stale,
  } = state

  const lastPage =
    meta?.last_page ??
    1

  const total =
    meta?.total ??
    items.length

  if (
    phase === 'error' &&
    error?.status === 403
  ) {
    return <Forbidden />
  }

  return (
    <div className="staff-reservations-page">
      {/* HEADER */}

      <section className="staff-reservations-hero">
        <div>
          <span className="staff-reservations-hero__eyebrow">
            Reservations
          </span>

          <h1>
            Manage customer reservations
          </h1>

          <p>
            {isCeo
              ? 'Monitor reservations across pharmacy branches. CEO access is read-only.'
              : 'Review reservations for your branch, monitor their status, and open a reservation to perform permitted actions.'}
          </p>
        </div>

        <span className="staff-reservations-hero__icon">
          <i
            className="bi bi-card-list"
            aria-hidden="true"
          ></i>
        </span>
      </section>

      {/* FILTERS */}

      <section
        className="staff-reservations-filter"
        aria-label="Reservation filters"
      >
        <div className="staff-reservations-filter__intro">
          <span>
            <i
              className="bi bi-funnel"
              aria-hidden="true"
            ></i>
          </span>

          <div>
            <strong>
              Filter reservations
            </strong>

            <small>
              Newest reservations are shown first.
            </small>
          </div>
        </div>

        <div className="staff-reservations-filter__controls">
          <div className="staff-reservations-filter__field">
            <label htmlFor="staffStatus">
              Status
            </label>

            <select
              id="staffStatus"
              value={status}
              onChange={(event) => {
                showLoading()

                setStatus(
                  event.target.value,
                )

                setPage(1)
              }}
            >
              <option value="">
                All statuses
              </option>

              {STATUS_OPTIONS.map(
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

          {isCeo ? (
            <div className="staff-reservations-filter__field">
              <label htmlFor="staffBranch">
                Branch
              </label>

              <select
                id="staffBranch"
                value={branch}
                onChange={(event) => {
                  showLoading()

                  setBranch(
                    event.target.value,
                  )

                  setPage(1)
                }}
              >
                <option value="">
                  All branches
                </option>

                {Object.entries(
                  names,
                ).map(
                  ([
                    id,
                    name,
                  ]) => (
                    <option
                      key={id}
                      value={id}
                    >
                      {name}
                    </option>
                  ),
                )}
              </select>
            </div>
          ) : null}

          <button
            type="button"
            className="staff-reservations-refresh"
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

      {/* SUMMARY */}

      {phase === 'done' ? (
        <section className="staff-reservations-summary">
          <div>
            <span className="staff-reservations-summary__icon">
              <i
                className="bi bi-receipt"
                aria-hidden="true"
              ></i>
            </span>

            <div>
              <strong>
                {total}
              </strong>

              <span>
                {status
                  ? `${statusLabel(
                      status,
                    )} reservation${
                      total === 1
                        ? ''
                        : 's'
                    }`
                  : `reservation${
                      total === 1
                        ? ''
                        : 's'
                    }`}
              </span>
            </div>
          </div>

          <p>
            {statusDescription(
              status,
            )}
          </p>

          {updatedAt ? (
            <span className="staff-reservations-summary__updated">
              <i
                className="bi bi-clock"
                aria-hidden="true"
              ></i>

              Updated{' '}
              {formatDateTime(
                updatedAt,
              )}
            </span>
          ) : null}
        </section>
      ) : null}

      {/* LOAD / ERROR / EMPTY */}

      <div aria-live="polite">
        {phase ===
        'loading' ? (
          <LoadingState>
            Loading reservations...
          </LoadingState>
        ) : null}

        {phase ===
        'error' ? (
          <ErrorState
            error={error}
            onRetry={() => {
              showLoading()

              setReloadKey(
                (key) =>
                  key + 1,
              )
            }}
          />
        ) : null}

        {phase ===
          'done' &&
        stale ? (
          <p className="app-notice app-notice--warning">
            Could not refresh just now.
            Showing the last reservation
            list that loaded successfully.
          </p>
        ) : null}

        {phase ===
          'done' &&
        items.length ===
          0 ? (
          <EmptyState
            title="No reservations found"
          >
            {status ===
            'pending'
              ? 'There are no pending reservations right now.'
              : 'Try another status or branch filter.'}
          </EmptyState>
        ) : null}
      </div>

      {/* TABLE */}

      {phase ===
        'done' &&
      items.length >
        0 ? (
        <>
          <div className="staff-reservations-list-heading">
            <div>
              <span>
                Reservation list
              </span>

              <h2>
                {status
                  ? `${statusLabel(
                      status,
                    )} reservations`
                  : 'All reservations'}
              </h2>
            </div>

            <small>
              Page{' '}
              {meta?.page ??
                page}{' '}
              of {lastPage}
            </small>
          </div>

          <div className="staff-reservations-table-wrap">
            <table className="staff-reservations-table">
              <caption className="visually-hidden">
                Staff reservation list,
                newest first
              </caption>

              <thead>
                <tr>
                  <th scope="col">
                    Claim code
                  </th>

                  {isCeo ? (
                    <th scope="col">
                      Branch
                    </th>
                  ) : null}

                  <th
                    scope="col"
                    className="staff-reservations-table__center"
                  >
                    Items
                  </th>

                  <th scope="col">
                    Total
                  </th>

                  <th scope="col">
                    Created
                  </th>

                  <th scope="col">
                    Hold until
                  </th>

                  <th scope="col">
                    Status
                  </th>

                  <th
                    scope="col"
                    className="staff-reservations-table__action-heading"
                  >
                    Action
                  </th>
                </tr>
              </thead>

              <tbody>
                {items.map(
                  (row) => (
                    <tr key={row.id}>
                      <td
                        data-label="Claim code"
                        className="staff-reservations-table__claim"
                      >
                        <span className="staff-reservation-table-icon">
                          <i
                            className="bi bi-receipt"
                            aria-hidden="true"
                          ></i>
                        </span>

                        <strong>
                          {
                            row.claim_code
                          }
                        </strong>
                      </td>

                      {isCeo ? (
                        <td data-label="Branch">
                          {branchLabel(
                            names,
                            row.branch_id,
                          )}
                        </td>
                      ) : null}

                      <td
                        data-label="Items"
                        className="staff-reservations-table__center"
                      >
                        <span className="staff-reservations-item-count">
                          {
                            row.item_count
                          }
                        </span>
                      </td>

                      <td data-label="Total">
                        <strong className="staff-reservations-price">
                          {formatPeso(
                            row.total_price,
                          )}
                        </strong>
                      </td>

                      <td data-label="Created">
                        <span className="staff-reservations-date">
                          {formatDateTime(
                            row.created_at,
                          )}
                        </span>
                      </td>

                      <td data-label="Hold until">
                        <span className="staff-reservations-date">
                          {formatDateTime(
                            row.expires_at,
                          )}
                        </span>
                      </td>

                      <td data-label="Status">
                        <StatusBadge
                          status={
                            row.status
                          }
                        />
                      </td>

                      <td
                        data-label="Action"
                        className="staff-reservations-table__action"
                      >
                        <Link
                          to={`/staff/reservations/${row.id}`}
                          className="staff-reservation-open"
                        >
                          Open

                          <i
                            className="bi bi-arrow-right"
                            aria-hidden="true"
                          ></i>

                          <span className="visually-hidden">
                            {' '}
                            reservation{' '}
                            {
                              row.claim_code
                            }
                          </span>
                        </Link>
                      </td>
                    </tr>
                  ),
                )}
              </tbody>
            </table>
          </div>

          {/* PAGINATION */}

          {lastPage > 1 ? (
            <nav
              className="staff-reservations-pager"
              aria-label="Pages of reservations"
            >
              <button
                type="button"
                onClick={() => {
                  showLoading()

                  setPage(
                    (current) =>
                      Math.max(
                        1,
                        current - 1,
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
                {meta?.page ??
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
                        current + 1,
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
    </div>
  )
}