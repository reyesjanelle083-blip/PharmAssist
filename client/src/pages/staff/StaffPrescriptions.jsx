import {
  useEffect,
  useState,
} from 'react'

import { Link } from 'react-router-dom'

import {
  getReservation,
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
import { usePageTitle } from '../../hooks/usePageTitle.js'
import { formatDateTime } from '../../utils/format.js'
import { STATUS_OPTIONS } from '../../utils/status.js'
import Forbidden from '../Forbidden.jsx'

import '../../styles/staff-prescriptions.css'

const PER_PAGE = 10

const OCR_INFO = {
  success: {
    label: 'Text detected',
    icon: 'bi bi-check-circle-fill',
    tone: 'success',
  },

  low_confidence: {
    label: 'Low confidence',
    icon: 'bi bi-exclamation-triangle-fill',
    tone: 'warning',
  },

  unavailable: {
    label: 'Manual review',
    icon: 'bi bi-eye-slash-fill',
    tone: 'warning',
  },

  not_processed: {
    label: 'Not processed',
    icon: 'bi bi-eye-slash-fill',
    tone: 'neutral',
  },
}

function confidencePercent(value) {
  if (
    value === null ||
    value === undefined ||
    value === ''
  ) {
    return null
  }

  const number = Number(value)

  if (!Number.isFinite(number)) {
    return null
  }

  return Math.max(
    0,
    Math.min(
      100,
      Math.round(
        number <= 1
          ? number * 100
          : number,
      ),
    ),
  )
}

export default function StaffPrescriptions() {
  usePageTitle(
    'Prescription review - Staff',
  )

  const { role } = useAuth()

  const names = useBranchNames()

  const isCeo =
    role === 'ceo'

  const [status, setStatus] =
    useState('pending')

  const [branch, setBranch] =
    useState('')

  const [page, setPage] =
    useState(1)

  const [
    reloadKey,
    setReloadKey,
  ] = useState(0)

  const [state, setState] =
    useState({
      phase: 'loading',
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
      .then(async ({ data }) => {
        const rows =
          data.items ?? []

        const details =
          await Promise.all(
            rows.map((row) =>
              getReservation(
                row.id,
                {
                  signal:
                    controller.signal,
                },
              )
                .then(
                  (response) =>
                    response.data,
                )
                .catch(
                  (error) => {
                    if (
                      error?.name ===
                      'CanceledError'
                    ) {
                      throw error
                    }

                    return null
                  },
                ),
            ),
          )

        if (
          controller.signal.aborted
        ) {
          return
        }

        const withPrescription =
          details.filter(
            (detail) =>
              detail
                ?.prescription_uploaded,
          )

        setState({
          phase: 'done',

          rows:
            withPrescription,

          checked:
            rows.length,

          unreadable:
            details.filter(
              (detail) =>
                detail === null,
            ).length,

          meta:
            data.meta ??
            null,

          updatedAt:
            new Date(),
        })
      })
      .catch((error) => {
        if (
          error?.name ===
          'CanceledError'
        ) {
          return
        }

        setState({
          phase: 'error',
          error,
        })
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
    state.meta?.last_page ?? 1

  return (
    <div className="staff-prescriptions-page">
      <section className="staff-rx-header">
        <div>
          <span className="staff-rx-header__eyebrow">
            Prescription Review
          </span>

          <h1>
            Review uploaded prescriptions
          </h1>

          <p>
            Compare the customer&apos;s original
            prescription photo with the text
            automatically read by PharmAssist.
            The original image remains the final
            reference for verification.
          </p>
        </div>

        <div className="staff-rx-header__icon">
          <i
            className="bi bi-file-earmark-medical"
            aria-hidden="true"
          ></i>
        </div>
      </section>

      <section
        className="staff-rx-filter"
        aria-label="Prescription filters"
      >
        <div className="staff-rx-filter__heading">
          <span>
            <i
              className="bi bi-funnel"
              aria-hidden="true"
            ></i>
          </span>

          <div>
            <strong>
              Filter prescription queue
            </strong>

            <small>
              Pending prescriptions are shown
              first by default.
            </small>
          </div>
        </div>

        <div className="staff-rx-filter__controls">
          <div>
            <label htmlFor="rxStatus">
              Reservation status
            </label>

            <select
              id="rxStatus"
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
            <div>
              <label htmlFor="rxBranch">
                Branch
              </label>

              <select
                id="rxBranch"
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
            className="staff-rx-refresh"
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

      <div aria-live="polite">
        {state.phase ===
        'loading' ? (
          <LoadingState>
            Loading prescriptions...
          </LoadingState>
        ) : null}

        {state.phase ===
        'error' ? (
          <ErrorState
            error={state.error}
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
        state.unreadable >
          0 ? (
          <p className="app-notice app-notice--warning">
            {state.unreadable}{' '}
            reservation(s) on this
            page could not be opened
            and are temporarily not
            shown.
          </p>
        ) : null}

        {state.phase ===
          'done' &&
        state.rows.length ===
          0 ? (
          <EmptyState title="No prescriptions to review">
            {state.checked === 0
              ? 'There are no reservations with this status.'
              : 'None of the reservations on this page has a prescription photo.'}
          </EmptyState>
        ) : null}
      </div>

      {state.phase ===
        'done' &&
      state.rows.length >
        0 ? (
        <>
          <div className="staff-rx-results-heading">
            <div>
              <span>
                Review queue
              </span>

              <h2>
                Prescription submissions
              </h2>
            </div>

            <small>
              Checked{' '}
              {state.checked}{' '}
              reservation(s)
              {' · '}
              Updated{' '}
              {formatDateTime(
                state.updatedAt,
              )}
            </small>
          </div>

          <div className="staff-rx-table-wrap">
            <table className="staff-rx-table">
              <caption className="visually-hidden">
                Prescription review queue
              </caption>

              <thead>
                <tr>
                  <th scope="col">
                    Claim Code
                  </th>

                  {isCeo ? (
                    <th scope="col">
                      Branch
                    </th>
                  ) : null}

                  <th scope="col">
                    Medicines
                  </th>

                  <th scope="col">
                    OCR Status
                  </th>

                  <th
                    scope="col"
                    className="staff-rx-table__center"
                  >
                    Confidence
                  </th>

                  <th scope="col">
                    Reservation
                  </th>

                  <th scope="col">
                    Submitted
                  </th>

                  <th
                    scope="col"
                    className="staff-rx-table__action-heading"
                  >
                    Action
                  </th>
                </tr>
              </thead>

              <tbody>
                {state.rows.map(
                  (reservation) => {
                    const ocr =
                      OCR_INFO[
                        reservation.ocr_status
                      ] ??
                      OCR_INFO.not_processed

                    const confidence =
                      confidencePercent(
                        reservation.ocr_confidence,
                      )

                    const hasText =
                      Boolean(
                        reservation.ocr_text?.trim(),
                      )

                    const firstMedicine =
                      reservation.items?.[0]

                    const extraMedicines =
                      Math.max(
                        0,
                        (reservation.items?.length ?? 0) - 1,
                      )

                    return (
                      <tr key={reservation.id}>
                        <td
                          data-label="Claim Code"
                          className="staff-rx-table__claim"
                        >
                          <span className="staff-rx-table__claim-icon">
                            <i
                              className="bi bi-file-medical"
                              aria-hidden="true"
                            ></i>
                          </span>

                          <strong>
                            {reservation.claim_code}
                          </strong>
                        </td>

                        {isCeo ? (
                          <td data-label="Branch">
                            {branchLabel(
                              names,
                              reservation.branch_id,
                            )}
                          </td>
                        ) : null}

                        <td
                          data-label="Medicines"
                          className="staff-rx-table__medicines"
                        >
                          {firstMedicine ? (
                            <>
                              <strong>
                                {firstMedicine.medicine_name}
                              </strong>

                              <span>
                                × {firstMedicine.quantity}

                                {extraMedicines > 0
                                  ? ` · +${extraMedicines} more`
                                  : ''}
                              </span>
                            </>
                          ) : (
                            '—'
                          )}
                        </td>

                        <td data-label="OCR Status">
                          <span
                            className={`staff-rx-ocr-badge staff-rx-ocr-badge--${ocr.tone}`}
                          >
                            <i
                              className={ocr.icon}
                              aria-hidden="true"
                            ></i>

                            {ocr.label}
                          </span>

                          {hasText ? (
                            <span className="staff-rx-table__text-available">
                              Text available
                            </span>
                          ) : null}
                        </td>

                        <td
                          data-label="Confidence"
                          className="staff-rx-table__center"
                        >
                          {confidence !== null &&
                          hasText ? (
                            <span
                              className={
                                confidence >= 80
                                  ? 'staff-rx-table__confidence staff-rx-table__confidence--good'
                                  : confidence >= 50
                                    ? 'staff-rx-table__confidence staff-rx-table__confidence--medium'
                                    : 'staff-rx-table__confidence staff-rx-table__confidence--low'
                              }
                            >
                              {confidence}%
                            </span>
                          ) : (
                            <span className="staff-rx-table__dash">
                              —
                            </span>
                          )}
                        </td>

                        <td data-label="Reservation">
                          <StatusBadge
                            status={reservation.status}
                          />
                        </td>

                        <td data-label="Submitted">
                          <span className="staff-rx-table__date">
                            {formatDateTime(
                              reservation.created_at,
                            )}
                          </span>
                        </td>

                        <td
                          data-label="Action"
                          className="staff-rx-table__action"
                        >
                          <Link
                            to={`/staff/reservations/${reservation.id}`}
                            className="staff-rx-review-button"
                          >
                            Review

                            <i
                              className="bi bi-arrow-right"
                              aria-hidden="true"
                            ></i>

                            <span className="visually-hidden">
                              {' '}
                              prescription for{' '}
                              {reservation.claim_code}
                            </span>
                          </Link>
                        </td>
                      </tr>
                    )
                  },
                )}
              </tbody>
            </table>
          </div>

          {lastPage > 1 ? (
            <nav
              className="staff-rx-pager"
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
                {state.meta?.page ??
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

      <p className="staff-rx-queue-note">
        <i
          className="bi bi-info-circle"
          aria-hidden="true"
        ></i>

        This page checks the
        reservations on the current
        page to find prescription
        uploads. A dedicated
        prescription queue endpoint
        is not yet available.
      </p>
    </div>
  )
}