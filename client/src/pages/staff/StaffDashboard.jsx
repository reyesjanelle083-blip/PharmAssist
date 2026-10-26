import {
  useEffect,
  useMemo,
  useState,
} from 'react'

import { Link } from 'react-router-dom'

import {
  getBranchInventory,
  getReservation,
  listReservations,
} from '../../api/index.js'

import StatusBadge from '../../components/ui/StatusBadge.jsx'
import { useAuth } from '../../context/useAuth.js'
import {
  branchLabel,
  useBranchNames,
} from '../../hooks/useBranchNames.js'
import { useAutoRefresh } from '../../hooks/useAutoRefresh.js'
import { usePageTitle } from '../../hooks/usePageTitle.js'
import { useStaffBranch } from '../../hooks/useStaffBranch.js'
import {
  formatDateTime,
  formatPeso,
} from '../../utils/format.js'
import {
  mayDo,
  ROLE_LABELS,
} from '../../utils/staff.js'

import '../../styles/staff-dashboard.css'

const RESERVATION_PREVIEW = 5
const PENDING_SCAN = 10

function greeting() {
  const hour = new Date().getHours()

  if (hour < 12) return 'Good morning'
  if (hour < 18) return 'Good afternoon'

  return 'Good evening'
}

function metricTotal(response) {
  return (
    response?.data?.meta?.total ??
    response?.data?.items?.length ??
    0
  )
}

function OverviewCard({
  icon,
  title,
  value,
  label,
  description,
  to,
  tone = 'blue',
  loading = false,
  failed = false,
  note,
}) {
  return (
    <article
      className={`staff-overview-card staff-overview-card--${tone}`}
    >
      <div className="staff-overview-card__top">
        <span className="staff-overview-card__icon">
          <i
            className={icon}
            aria-hidden="true"
          ></i>
        </span>

        <span className="staff-overview-card__title">
          {title}
        </span>
      </div>

      <div className="staff-overview-card__metric">
        <strong>
          {loading
            ? '…'
            : failed
              ? '—'
              : value}
        </strong>

        <span>{label}</span>
      </div>

      <p className="staff-overview-card__description">
        {description}
      </p>

      {note ? (
        <p className="staff-overview-card__note">
          <i
            className="bi bi-info-circle"
            aria-hidden="true"
          ></i>

          {note}
        </p>
      ) : null}

      <Link
        to={to}
        className="staff-overview-card__link"
      >
        Open {title}

        <i
          className="bi bi-arrow-right"
          aria-hidden="true"
        ></i>
      </Link>
    </article>
  )
}

function AttentionItem({
  icon,
  title,
  description,
  count,
  to,
  tone = '',
}) {
  return (
    <Link
      to={to}
      className={`staff-attention-item ${
        tone
          ? `staff-attention-item--${tone}`
          : ''
      }`}
    >
      <span className="staff-attention-item__icon">
        <i
          className={icon}
          aria-hidden="true"
        ></i>
      </span>

      <span className="staff-attention-item__copy">
        <strong>{title}</strong>
        <small>{description}</small>
      </span>

      {count !== null &&
      count !== undefined ? (
        <span className="staff-attention-item__count">
          {count}
        </span>
      ) : null}

      <i
        className="bi bi-chevron-right staff-attention-item__arrow"
        aria-hidden="true"
      ></i>
    </Link>
  )
}

export default function StaffDashboard() {
  usePageTitle('Staff Dashboard')

  const {
    user,
    role,
  } = useAuth()

  const names = useBranchNames()

  const {
    isCeo,
    branchId,
    branches,
    branchesFailed,
    setBranch,
  } = useStaffBranch()

  const [reloadKey, setReloadKey] =
    useState(0)

  const [
    reservationState,
    setReservationState,
  ] = useState({
    phase: 'loading',
  })

  const [
    inventoryState,
    setInventoryState,
  ] = useState({
    phase: 'loading',
  })

  const personName =
    user?.first_name?.trim() ||
    'there'

  const branchName =
    branchId === null
      ? 'No branch selected'
      : branchLabel(
          names,
          branchId,
        )

  /*
   * Reservation overview:
   * - exact totals come from pagination metadata
   * - recent reservations are the newest five
   * - prescription preview inspects the newest
   *   pending reservations only, because there
   *   is not yet a dedicated prescription queue
   *   endpoint.
   */
  useEffect(() => {
    if (
      isCeo &&
      branchId === null
    ) {
      return undefined
    }

    const controller =
      new AbortController()

    const scopeBranch =
      isCeo
        ? branchId
        : undefined

    Promise.all([
      listReservations(
        {
          status: 'pending',
          page: 1,
          perPage:
            PENDING_SCAN,
          branchId:
            scopeBranch,
        },
        {
          signal:
            controller.signal,
        },
      ),

      listReservations(
        {
          status: 'approved',
          page: 1,
          perPage: 1,
          branchId:
            scopeBranch,
        },
        {
          signal:
            controller.signal,
        },
      ),

      listReservations(
        {
          status: 'completed',
          page: 1,
          perPage: 1,
          branchId:
            scopeBranch,
        },
        {
          signal:
            controller.signal,
        },
      ),

      listReservations(
        {
          status: '',
          page: 1,
          perPage:
            RESERVATION_PREVIEW,
          branchId:
            scopeBranch,
        },
        {
          signal:
            controller.signal,
        },
      ),
    ])
      .then(
        async ([
          pending,
          approved,
          completed,
          recent,
        ]) => {
          const pendingRows =
            pending.data.items ??
            []

          const details =
            await Promise.all(
              pendingRows.map(
                (row) =>
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
            controller.signal
              .aborted
          ) {
            return
          }

          const rxRows =
            details.filter(
              (detail) =>
                detail
                  ?.prescription_uploaded,
            )

          const manualRx =
            rxRows.filter(
              (detail) =>
                detail.ocr_status !==
                'success',
            )

          setReservationState({
            phase: 'done',

            pending:
              metricTotal(
                pending,
              ),

            approved:
              metricTotal(
                approved,
              ),

            completed:
              metricTotal(
                completed,
              ),

            recent:
              recent.data.items ??
              [],

            rxCount:
              rxRows.length,

            manualRx:
              manualRx.length,

            scannedPending:
              pendingRows.length,

            pendingTotal:
              metricTotal(
                pending,
              ),

            updatedAt:
              new Date(),

            refreshing: false,
          })
        },
      )
      .catch((error) => {
        if (
          error?.name ===
          'CanceledError'
        ) {
          return
        }

        setReservationState(
          (current) =>
            current.phase ===
            'done'
              ? {
                  ...current,
                  refreshing: false,
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
    isCeo,
    branchId,
    reloadKey,
  ])

  /*
   * Inventory overview for the same branch.
   * These totals come directly from the existing
   * inventory endpoint and therefore match the
   * Inventory and Stock Alerts pages.
   */
  useEffect(() => {
    if (branchId === null) {
      return undefined
    }

    const controller =
      new AbortController()

    Promise.all([
      getBranchInventory(
        branchId,
        {
          page: 1,
          perPage: 1,
        },
        {
          signal:
            controller.signal,
        },
      ),

      getBranchInventory(
        branchId,
        {
          status: 'available',
          page: 1,
          perPage: 1,
        },
        {
          signal:
            controller.signal,
        },
      ),

      getBranchInventory(
        branchId,
        {
          status: 'low_stock',
          page: 1,
          perPage: 5,
        },
        {
          signal:
            controller.signal,
        },
      ),

      getBranchInventory(
        branchId,
        {
          status:
            'out_of_stock',
          page: 1,
          perPage: 5,
        },
        {
          signal:
            controller.signal,
        },
      ),
    ])
      .then(
        ([
          all,
          available,
          low,
          out,
        ]) => {
          setInventoryState({
            phase: 'done',

            total:
              metricTotal(all),

            available:
              metricTotal(
                available,
              ),

            low:
              metricTotal(low),

            out:
              metricTotal(out),

            lowRows:
              low.data.items ??
              [],

            outRows:
              out.data.items ??
              [],

            updatedAt:
              new Date(),

            refreshing: false,
          })
        },
      )
      .catch((error) => {
        if (
          error?.name ===
          'CanceledError'
        ) {
          return
        }

        setInventoryState(
          (current) =>
            current.phase ===
            'done'
              ? {
                  ...current,
                  refreshing: false,
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
        (current) =>
          current + 1,
      ),
    {
      enabled:
        reservationState.phase ===
          'done' ||
        inventoryState.phase ===
          'done',

      intervalMs: 30000,
    },
  )

  const reservationLoading =
    reservationState.phase ===
    'loading'

  const reservationFailed =
    reservationState.phase ===
    'error'

  const inventoryLoading =
    inventoryState.phase ===
    'loading'

  const inventoryFailed =
    inventoryState.phase ===
    'error'

  const reservationMetric =
    role ===
    'pharmacy_assistant'
      ? reservationState.approved ??
        0
      : reservationState.pending ??
        0

  const reservationMetricLabel =
    role ===
    'pharmacy_assistant'
      ? 'approved for pickup'
      : 'pending review'

  const attentionItems =
    useMemo(() => {
      if (
        reservationState.phase !==
          'done' &&
        inventoryState.phase !==
          'done'
      ) {
        return []
      }

      const rows = []

      if (
        reservationState.phase ===
          'done' &&
        reservationState.pending >
          0
      ) {
        rows.push({
          icon:
            'bi bi-hourglass-split',

          title:
            'Pending reservations',

          description:
            isCeo
              ? 'Waiting for branch review.'
              : mayDo(
                    role,
                    'approve',
                  )
                ? 'Waiting for approval or rejection.'
                : 'Waiting for the authorized branch staff to review.',

          count:
            reservationState.pending,

          to:
            '/staff/reservations',

          tone:
            'warning',
        })
      }

      if (
        reservationState.phase ===
          'done' &&
        reservationState.approved >
          0
      ) {
        rows.push({
          icon:
            'bi bi-bag-check',

          title:
            'Approved reservations',

          description:
            isCeo
              ? 'Approved and waiting for customer pickup.'
              : mayDo(
                    role,
                    'complete',
                  )
                ? 'Ready for claim-code verification and handover.'
                : 'Approved and waiting for pickup.',

          count:
            reservationState.approved,

          to:
            '/staff/reservations',

          tone:
            'success',
        })
      }

      if (
        reservationState.phase ===
          'done' &&
        reservationState.manualRx >
          0
      ) {
        rows.push({
          icon:
            'bi bi-file-medical',

          title:
            'Prescriptions needing careful review',

          description:
            'OCR was unavailable or was not fully successful in the latest pending queue.',

          count:
            reservationState.manualRx,

          to:
            '/staff/prescriptions',

          tone:
            'warning',
        })
      }

      if (
        inventoryState.phase ===
          'done' &&
        inventoryState.out >
          0
      ) {
        rows.push({
          icon:
            'bi bi-x-circle',

          title:
            'Out-of-stock medicines',

          description:
            'These medicines currently have no free stock at this branch.',

          count:
            inventoryState.out,

          to:
            '/staff/stock-alerts',

          tone:
            'danger',
        })
      }

      if (
        inventoryState.phase ===
          'done' &&
        inventoryState.low >
          0
      ) {
        rows.push({
          icon:
            'bi bi-exclamation-triangle',

          title:
            'Low-stock medicines',

          description:
            'These medicines are at or below their stock alert level.',

          count:
            inventoryState.low,

          to:
            '/staff/stock-alerts',

          tone:
            'warning',
        })
      }

      return rows
    }, [
      reservationState,
      inventoryState,
      role,
      isCeo,
    ])

  const lastUpdated =
    reservationState.updatedAt ??
    inventoryState.updatedAt ??
    null

  const refreshing = false

  return (
    <div className="staff-dashboard">
      {/* ===================================================
          HERO
      ==================================================== */}

      <section className="staff-dashboard-hero">
        <div className="staff-dashboard-hero__copy">
          <span className="staff-dashboard-hero__eyebrow">
            Staff Portal
          </span>

          <h1>
            {isCeo
              ? 'PharmAssist Operations Overview'
              : `${greeting()}, ${personName}`}
          </h1>

          <p>
            {isCeo
              ? 'Monitor reservations, prescriptions, inventory, and stock alerts for the selected pharmacy branch.'
              : `Here is what is happening at ${branchName} right now.`}
          </p>

          <div className="staff-dashboard-hero__meta">
            <span>
              <i
                className="bi bi-person-badge"
                aria-hidden="true"
              ></i>

              {ROLE_LABELS[role] ??
                'Staff'}
            </span>

            <span>
              <i
                className="bi bi-shop"
                aria-hidden="true"
              ></i>

              {branchName}
            </span>

            {lastUpdated ? (
              <span>
                <i
                  className="bi bi-clock"
                  aria-hidden="true"
                ></i>

                Updated{' '}
                {formatDateTime(
                  lastUpdated,
                )}
              </span>
            ) : null}
          </div>
        </div>

        <div className="staff-dashboard-hero__actions">
          {isCeo ? (
            <div className="staff-dashboard-branch-picker">
              <label htmlFor="dashboardBranch">
                Viewing branch
              </label>

              <select
                id="dashboardBranch"
                value={
                  branchId ?? ''
                }
                onChange={(
                  event,
                ) =>
                  setBranch(
                    Number(
                      event.target
                        .value,
                    ),
                  )
                }
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

              <small>
                Dashboard metrics use
                the selected branch.
                Reservations can still
                be viewed across all
                branches on the full
                Reservations page.
              </small>
            </div>
          ) : null}

          <button
            type="button"
            className="staff-dashboard-refresh"
            onClick={() =>
              setReloadKey(
                (current) =>
                  current + 1,
              )
            }
            disabled={refreshing}
          >
            <i
              className="bi bi-arrow-clockwise"
              aria-hidden="true"
            ></i>

            {refreshing
              ? 'Refreshing...'
              : 'Refresh dashboard'}
          </button>
        </div>
      </section>

      {branchesFailed ? (
        <p className="app-notice app-notice--warning">
          The branch list could not
          refresh. The current branch
          selection may be incomplete.
        </p>
      ) : null}

      {reservationState.stale ||
      inventoryState.stale ? (
        <p className="app-notice app-notice--warning">
          Some dashboard information
          could not refresh just now.
          Showing the latest data that
          was loaded successfully.
        </p>
      ) : null}

      {/* ===================================================
          PAGE OVERVIEW
      ==================================================== */}

      <section
        className="staff-dashboard-section"
        aria-labelledby="page-overview-heading"
      >
        <div className="staff-dashboard-section__heading">
          <div>
            <span>
              Your workspace
            </span>

            <h2 id="page-overview-heading">
              Page overview
            </h2>

            <p>
              A quick summary of the
              staff pages you manage.
            </p>
          </div>
        </div>

        <div className="staff-overview-grid">
          <OverviewCard
            icon="bi bi-card-list"
            title="Reservations"
            value={
              reservationMetric
            }
            label={
              reservationMetricLabel
            }
            description={
              isCeo
                ? 'View reservations for the selected branch. CEO access is read-only.'
                : role ===
                    'pharmacy_assistant'
                  ? 'Monitor approved reservations and complete handovers using the customer claim code.'
                  : 'Review reservation requests, track their status, and handle permitted actions.'
            }
            to="/staff/reservations"
            tone="blue"
            loading={
              reservationLoading
            }
            failed={
              reservationFailed
            }
          />

          <OverviewCard
            icon="bi bi-file-medical"
            title="Prescription Review"
            value={
              reservationState
                .rxCount ?? 0
            }
            label="Rx in latest pending queue"
            description="Compare uploaded prescription photos with the OCR text stored by PharmAssist."
            to="/staff/prescriptions"
            tone="purple"
            loading={
              reservationLoading
            }
            failed={
              reservationFailed
            }
            note={
              reservationState.phase ===
                'done' &&
              reservationState.pendingTotal >
                reservationState.scannedPending
                ? `This preview checks the latest ${reservationState.scannedPending} of ${reservationState.pendingTotal} pending reservations.`
                : 'Prescription totals are based on the pending reservations currently checked by the review queue.'
            }
          />

          <OverviewCard
            icon="bi bi-boxes"
            title="Inventory"
            value={
              inventoryState.total ??
              0
            }
            label="medicine records"
            description="Check current medicine stock and availability for the selected pharmacy branch."
            to="/staff/inventory"
            tone="green"
            loading={
              inventoryLoading
            }
            failed={
              inventoryFailed
            }
            note={
              inventoryState.phase ===
              'done'
                ? `${inventoryState.available} currently available.`
                : undefined
            }
          />

          <OverviewCard
            icon="bi bi-exclamation-triangle"
            title="Stock Alerts"
            value={
              inventoryState.phase ===
              'done'
                ? inventoryState.low +
                  inventoryState.out
                : 0
            }
            label="items needing attention"
            description="See medicines that are low in stock or completely out of stock."
            to="/staff/stock-alerts"
            tone="orange"
            loading={
              inventoryLoading
            }
            failed={
              inventoryFailed
            }
            note={
              inventoryState.phase ===
              'done'
                ? `${inventoryState.out} out of stock · ${inventoryState.low} low stock`
                : undefined
            }
          />
        </div>
      </section>

      {/* ===================================================
          ATTENTION + RECENT
      ==================================================== */}

      <div className="staff-dashboard-columns">
        <section
          className="staff-dashboard-panel"
          aria-labelledby="attention-heading"
        >
          <div className="staff-dashboard-panel__heading">
            <div>
              <span>
                Priority
              </span>

              <h2 id="attention-heading">
                Needs attention
              </h2>
            </div>

            <i
              className="bi bi-bell"
              aria-hidden="true"
            ></i>
          </div>

          {reservationLoading &&
          inventoryLoading ? (
            <div className="staff-dashboard-loading">
              <i
                className="bi bi-arrow-repeat"
                aria-hidden="true"
              ></i>

              Loading current
              activity...
            </div>
          ) : attentionItems.length >
            0 ? (
            <div className="staff-attention-list">
              {attentionItems.map(
                (item) => (
                  <AttentionItem
                    key={
                      item.title
                    }
                    {...item}
                  />
                ),
              )}
            </div>
          ) : (
            <div className="staff-dashboard-clear">
              <span>
                <i
                  className="bi bi-check2-circle"
                  aria-hidden="true"
                ></i>
              </span>

              <div>
                <strong>
                  Nothing urgent
                </strong>

                <p>
                  There are no current
                  pending or stock alerts
                  in the dashboard data.
                </p>
              </div>
            </div>
          )}
        </section>

        <section
          className="staff-dashboard-panel"
          aria-labelledby="recent-heading"
        >
          <div className="staff-dashboard-panel__heading">
            <div>
              <span>
                Activity
              </span>

              <h2 id="recent-heading">
                Recent reservations
              </h2>
            </div>

            <Link
              to="/staff/reservations"
              className="staff-dashboard-panel__view-all"
            >
              View all
              <i
                className="bi bi-arrow-right"
                aria-hidden="true"
              ></i>
            </Link>
          </div>

          {reservationLoading ? (
            <div className="staff-dashboard-loading">
              <i
                className="bi bi-arrow-repeat"
                aria-hidden="true"
              ></i>

              Loading recent
              reservations...
            </div>
          ) : null}

          {reservationFailed ? (
            <div className="staff-dashboard-inline-error">
              <i
                className="bi bi-exclamation-circle"
                aria-hidden="true"
              ></i>

              <div>
                <strong>
                  Reservations could
                  not be loaded
                </strong>

                <button
                  type="button"
                  onClick={() =>
                    setReloadKey(
                      (current) =>
                        current + 1,
                    )
                  }
                >
                  Try again
                </button>
              </div>
            </div>
          ) : null}

          {reservationState.phase ===
            'done' &&
          reservationState.recent
            .length === 0 ? (
            <div className="staff-dashboard-clear">
              <span>
                <i
                  className="bi bi-inbox"
                  aria-hidden="true"
                ></i>
              </span>

              <div>
                <strong>
                  No reservations yet
                </strong>

                <p>
                  Recent reservations
                  will appear here.
                </p>
              </div>
            </div>
          ) : null}

          {reservationState.phase ===
            'done' &&
          reservationState.recent
            .length > 0 ? (
            <div className="staff-recent-list">
              {reservationState.recent.map(
                (reservation) => (
                  <Link
                    key={
                      reservation.id
                    }
                    to={`/staff/reservations/${reservation.id}`}
                    className="staff-recent-item"
                  >
                    <span className="staff-recent-item__icon">
                      <i
                        className="bi bi-receipt"
                        aria-hidden="true"
                      ></i>
                    </span>

                    <span className="staff-recent-item__main">
                      <strong>
                        {
                          reservation.claim_code
                        }
                      </strong>

                      <small>
                        {
                          reservation.item_count
                        }{' '}
                        {reservation.item_count ===
                        1
                          ? 'item'
                          : 'items'}
                        {' · '}
                        {formatPeso(
                          reservation.total_price,
                        )}
                      </small>

                      <small>
                        {formatDateTime(
                          reservation.created_at,
                        )}
                      </small>
                    </span>

                    <StatusBadge
                      status={
                        reservation.status
                      }
                    />

                    <i
                      className="bi bi-chevron-right staff-recent-item__arrow"
                      aria-hidden="true"
                    ></i>
                  </Link>
                ),
              )}
            </div>
          ) : null}
        </section>
      </div>

      {/* ===================================================
          ROLE CAPABILITIES
      ==================================================== */}

      <section className="staff-role-panel">
        <div className="staff-role-panel__icon">
          <i
            className="bi bi-shield-check"
            aria-hidden="true"
          ></i>
        </div>

        <div>
          <span>
            Your access
          </span>

          <h2>
            {ROLE_LABELS[role] ??
              'Staff'}
          </h2>

          <p>
            {role ===
            'pharmacy_technician'
              ? 'You can review branch reservations, approve or reject pending requests, complete approved pickups using the claim code, review prescriptions, and view inventory and stock alerts.'
              : role ===
                  'pharmacy_assistant'
                ? 'You can view branch reservations, complete approved pickups using the claim code, review prescriptions, and view inventory and stock alerts. Approval and rejection are handled by authorized roles.'
                : role ===
                    'branch_manager'
                  ? 'You can review branch reservations, approve or reject pending requests, complete approved pickups, review prescriptions, and monitor branch inventory and stock alerts.'
                  : 'You can monitor reservations, prescriptions, inventory, and stock alerts across branches. Reservation status actions remain read-only for the CEO role.'}
          </p>
        </div>
      </section>
    </div>
  )
}