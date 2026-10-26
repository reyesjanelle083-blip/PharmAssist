import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { getMedicines, searchAvailability } from '../api/index.js'
import Button from '../components/ui/Button.jsx'
import EmptyState from '../components/ui/EmptyState.jsx'
import ErrorState from '../components/ui/ErrorState.jsx'
import LoadingState from '../components/ui/LoadingState.jsx'
import PageHeader from '../components/ui/PageHeader.jsx'
import SelectField from '../components/ui/SelectField.jsx'
import StockBadge from '../components/ui/StockBadge.jsx'
import { useSearch } from '../context/useSearch.js'
import { useAuth } from '../context/useAuth.js'
import { useCart } from '../context/useCart.js'
import { useBranches } from '../hooks/useBranches.js'
import { useCategoryOptions } from '../hooks/useCategoryOptions.js'
import { usePageTitle } from '../hooks/usePageTitle.js'
import { displayName, RX_OPTIONS } from '../utils/catalog.js'
import { straightLineKm } from '../utils/geo.js'
import { isStaffRole } from '../utils/staff.js'
import MedicineScanner from '../components/MedicineScanner.jsx'
import MedicineLabelScanner from '../components/MedicineLabelScanner.jsx'

const PER_PAGE = 12
const SHOWN_BRANCHES = 3

function Availability({ medicine, branchId, branches, origin }) {
  const info = medicine.availability

  if (!info) return null

  const byId = new Map(
    branches.map((branch) => [Number(branch.id), branch]),
  )

  if (info.branch_count === 0) {
    return (
      <div className="card-availability">
        <StockBadge status="out_of_stock" />
        <p className="field-hint">Not in stock at any branch right now.</p>
      </div>
    )
  }

  const rows = info.branches.map((row) => {
    const branch = byId.get(Number(row.branch_id))

    const km =
      origin && branch
        ? straightLineKm(origin, {
            lat: branch.latitude,
            lng: branch.longitude,
          })
        : null

    return {
      ...row,
      name: branch?.name ?? `Branch #${row.branch_id}`,
      km,
    }
  })

  if (origin) {
    rows.sort(
      (a, b) =>
        (a.km ?? Number.POSITIVE_INFINITY) -
        (b.km ?? Number.POSITIVE_INFINITY),
    )
  }

  if (branchId) {
    const here = rows.find(
      (row) => Number(row.branch_id) === Number(branchId),
    )

    const others = info.branch_count - (here ? 1 : 0)

    return (
      <div className="card-availability">
        <div className="catalog-stock-heading">
          {here ? <StockBadge status={here.status} /> : null}
        </div>

        <p className="field-hint catalog-stock-primary">
          {here
            ? `${here.available_quantity} in stock at ${here.name}`
            : 'Not currently available at this branch.'}
        </p>

        {others > 0 ? (
          <p className="field-hint">
            Also available at {others}{' '}
            {others === 1 ? 'other branch' : 'other branches'}.
          </p>
        ) : null}
      </div>
    )
  }

  const overall = info.branches.every((row) => row.status === 'low_stock')
    ? 'low_stock'
    : 'available'

  const total = branches.length
  const showTotal = total >= info.branch_count && total > 0

  const nearest = origin ? rows.find((row) => row.km !== null) : null

  return (
    <div className="card-availability">
      <StockBadge status={overall} />

      <p className="field-hint catalog-stock-primary">
        In stock at {info.branch_count}
        {showTotal ? ` of ${total}` : ''}{' '}
        {(showTotal ? total : info.branch_count) === 1 ? 'branch' : 'branches'}
        {' · '}
        {info.total_available} available in total
      </p>

      {nearest ? (
        <p className="field-hint catalog-nearest">
          <i className="bi bi-geo-alt-fill" aria-hidden="true"></i>{' '}
          Nearest: <strong>{nearest.name}</strong>, about{' '}
          {nearest.km < 10 ? nearest.km.toFixed(1) : Math.round(nearest.km)} km
          away
          <span className="catalog-distance-note"> (straight-line estimate)</span>
        </p>
      ) : null}

      <ul className="card-branch-list">
        {rows.slice(0, SHOWN_BRANCHES).map((row) => (
          <li key={row.branch_id}>
            <span>{row.name}</span>
            <strong>{row.available_quantity} available</strong>
          </li>
        ))}

        {rows.length > SHOWN_BRANCHES ? (
          <li className="card-branch-more">
            + {rows.length - SHOWN_BRANCHES} more{' '}
            {rows.length - SHOWN_BRANCHES === 1 ? 'branch' : 'branches'}
          </li>
        ) : null}
      </ul>
    </div>
  )
}

export default function Medicines() {
  usePageTitle('Medicines')

  const navigate = useNavigate()

  const { origin, searchForMedicine } = useSearch()
  const { role } = useAuth()
  const cart = useCart()

  const categoryOptions = useCategoryOptions()
  const branches = useBranches()

  const [typed, setTyped] = useState('')
  const [scannerOpen, setScannerOpen] = useState(false)
  const [labelScannerOpen, setLabelScannerOpen] = useState(false)

  const [filters, setFilters] = useState({
    q: '',
    category: '',
    rx: '',
    branch: '',
  })

  const [page, setPage] = useState(1)
  const [reloadKey, setReloadKey] = useState(0)

  const [state, setState] = useState({
    phase: 'loading',
  })

  const [reservingId, setReservingId] = useState(null)
  const [reserveError, setReserveError] = useState('')

  const heading = useRef(null)

  useEffect(() => {
    const controller = new AbortController()

    getMedicines(
      {
        q: filters.q || undefined,
        category: filters.category || undefined,
        rx: filters.rx || undefined,
        branchId: filters.branch || undefined,
        include: 'availability',
        page,
        per_page: PER_PAGE,
      },
      {
        signal: controller.signal,
      },
    )
      .then(({ data }) => {
        setState({
          phase: 'done',
          items: data.items ?? [],
          meta: data.meta ?? null,
        })
      })
      .catch((error) => {
        if (error?.name !== 'CanceledError') {
          setState({
            phase: 'error',
            error,
          })
        }
      })

    return () => controller.abort()
  }, [filters, page, reloadKey])

  function change(next) {
    setState({ phase: 'loading' })
    setReserveError('')
    setFilters((current) => ({
      ...current,
      ...next,
    }))
    setPage(1)
  }

  function submit(event) {
    event.preventDefault()

    change({
      q: typed.trim(),
    })
  }

  function clear() {
    setTyped('')
    change({
      q: '',
      category: '',
      rx: '',
      branch: '',
    })
  }

  function searchScannedMedicine(label) {
  const query = label.trim()

  setTyped(query)

  change({
    q: query,
    category: '',
    rx: '',
    branch: '',
  })
}

function openLabelScanner() {
  if (!role) {
    navigate('/login', {
      state: {
        notice: 'login_required',
        from: '/medicines',
      },
    })

    return
  }

  setLabelScannerOpen(true)
}

  function goTo(nextPage) {
    setState({ phase: 'loading' })
    setPage(nextPage)

    requestAnimationFrame(() => {
      heading.current?.focus()
    })
  }

  const filtered =
    filters.q !== '' ||
    filters.category !== '' ||
    filters.rx !== '' ||
    filters.branch !== ''

  const branchOptions = branches.map((branch) => ({
    value: String(branch.id),
    label: branch.name,
  }))

  const selectedBranch = branches.find(
    (branch) => String(branch.id) === filters.branch,
  )

  const branchName = selectedBranch?.name ?? ''
  const meta = state.meta
  const lastPage = meta?.last_page ?? 1

  async function reserveFromSelectedBranch(medicine) {
    if (!filters.branch) {
      searchForMedicine(medicine.id, displayName(medicine))
      return
    }

    const branchId = Number(filters.branch)

    const reservePath =
      `/reserve?branch=${branchId}` + `&medicine=${medicine.id}`

    if (role !== 'customer') {
      navigate('/login', {
        state: {
          notice: 'login_required',
          from: reservePath,
        },
      })
      return
    }

    setReservingId(medicine.id)
    setReserveError('')

    try {
      const { data } = await searchAvailability({
        medicineId: medicine.id,
        limit: 100,
      })

      const row = (data.items ?? []).find(
        (item) =>
          Number(item.branch_id) === branchId &&
          Number(item.available_quantity) > 0,
      )

      if (!row) {
        setReserveError(
          `${displayName(medicine)} is no longer available at ${branchName}. Please refresh the list or choose another branch.`,
        )
        return
      }

      cart.add(row, {
        go: true,
      })
    } catch {
      setReserveError(
        'We could not confirm the latest stock for this medicine. Please try again.',
      )
    } finally {
      setReservingId(null)
    }
  }

  return (
    <div className="legacy-home medicines-page">
      <section
        className="branch-container"
        aria-labelledby="medicines-heading"
      >
        <PageHeader
          headingRef={heading}
          title={<span id="medicines-heading">Medicines</span>}
          subtitle="Browse medicines, compare branch availability, and reserve from your preferred branch."
        />

        <MedicineScanner
          open={scannerOpen}
          onClose={() => setScannerOpen(false)}
          onUseDetection={searchScannedMedicine}
        />

        <MedicineLabelScanner
          open={labelScannerOpen}
          onClose={() => setLabelScannerOpen(false)}
          onUseMedicine={searchScannedMedicine}
        />

        <form
          className="catalog-filter-panel"
          onSubmit={submit}
          role="search"
          aria-label="Filter the medicine list"
        >
          <div className="catalog-filter-top">
            <div className="catalog-filter-copy">
              <h2>Find the medicine you need</h2>
              <p>
                Search by name, then narrow the results by category,
                prescription requirement, or pharmacy branch.
              </p>
            </div>

            <div className="catalog-filter-actions">
              <Button
                type="button"
                variant="primary"
                className="catalog-scan-button"
                onClick={() => setScannerOpen(true)}
              >
                <i className="bi bi-camera" aria-hidden="true"></i>{' '}
                Scan medicine
              </Button>

              {role !== 'ceo' ? (
                <Button
                  type="button"
                  className="catalog-label-scan-button"
                  onClick={openLabelScanner}
                >
                  <i
                    className="bi bi-card-text"
                    aria-hidden="true"
                  ></i>{' '}
                  Scan label
                </Button>
              ) : null}

              {(filtered || typed !== '') && (
                <Button
                  type="button"
                  onClick={clear}
                  className="catalog-clear-button"
                >
                  <i
                    className="bi bi-arrow-counterclockwise"
                    aria-hidden="true"
                  ></i>{' '}
                  Clear filters
                </Button>
              )}
            </div>
          </div>

          <div className="catalog-toolbar">
            <div className="catalog-search-box">
              <label htmlFor="medicine-search-box" className="catalog-label">
                Search medicine
              </label>

              <div className="catalog-search-input-wrap">
                <i className="bi bi-search" aria-hidden="true"></i>
                <input
                  id="medicine-search-box"
                  type="search"
                  name="q"
                  value={typed}
                  maxLength={100}
                  autoComplete="off"
                  placeholder="Generic or brand name"
                  onChange={(event) => setTyped(event.target.value)}
                />
              </div>
            </div>

            <Button type="submit" variant="primary" className="catalog-search-button">
              <i className="bi bi-search" aria-hidden="true"></i>{' '}
              Search
            </Button>
          </div>

          <div className="catalog-filter-grid-v2">
            <div className="catalog-filter-card">
              <div className="catalog-filter-card__icon">
                <i className="bi bi-tag"></i>
              </div>
              <div className="catalog-filter-card__body">
                <SelectField
                  label="Category"
                  value={filters.category}
                  onChange={(value) =>
                    change({
                      category: value,
                    })
                  }
                  options={categoryOptions}
                  placeholder="All categories"
                />
              </div>
            </div>

            <div className="catalog-filter-card">
              <div className="catalog-filter-card__icon">
                <i className="bi bi-file-medical"></i>
              </div>
              <div className="catalog-filter-card__body">
                <SelectField
                  label="Prescription"
                  value={filters.rx}
                  onChange={(value) =>
                    change({
                      rx: value,
                    })
                  }
                  options={RX_OPTIONS}
                  placeholder="Any"
                />
              </div>
            </div>

            <div className="catalog-filter-card">
              <div className="catalog-filter-card__icon">
                <i className="bi bi-shop"></i>
              </div>
              <div className="catalog-filter-card__body">
                <SelectField
                  label="Branch"
                  value={filters.branch}
                  onChange={(value) =>
                    change({
                      branch: value,
                    })
                  }
                  options={branchOptions}
                  placeholder="All branches"
                />
                <p className="catalog-filter-help">
                  Choose a branch if you want to reserve directly from that
                  branch’s available stock.
                </p>
              </div>
            </div>
          </div>

          <div className="catalog-filter-pills">
            <span className="catalog-pill">
              <i className="bi bi-capsule-pill" aria-hidden="true"></i>{' '}
              {meta?.total ?? state.items?.length ?? 0} medicines
            </span>

            <span className="catalog-pill">
              <i className="bi bi-building" aria-hidden="true"></i>{' '}
              {branchName ? `Branch: ${branchName}` : 'All branches'}
            </span>

            <span className="catalog-pill">
              <i className="bi bi-shield-check" aria-hidden="true"></i>{' '}
              {filters.rx
                ? filters.rx === 'required'
                  ? 'Prescription required'
                  : 'No prescription needed'
                : 'Any prescription type'}
            </span>
          </div>
        </form>

        {reserveError ? (
          <div className="catalog-reserve-error" role="alert">
            <i className="bi bi-exclamation-circle-fill" aria-hidden="true"></i>

            <span>{reserveError}</span>

            <button
              type="button"
              aria-label="Close message"
              onClick={() => setReserveError('')}
            >
              <i className="bi bi-x-lg" aria-hidden="true"></i>
            </button>
          </div>
        ) : null}

        <div aria-live="polite" className="search-results">
          {state.phase === 'loading' ? (
            <LoadingState>Loading medicines...</LoadingState>
          ) : null}

          {state.phase === 'error' ? (
            <ErrorState
              error={state.error}
              onRetry={() => {
                setState({ phase: 'loading' })
                setReloadKey((current) => current + 1)
              }}
            />
          ) : null}

          {state.phase === 'done' && state.items.length === 0 ? (
            <EmptyState
              icon="bi bi-search"
              title="No medicines found"
              actions={
                filtered ? <Button onClick={clear}>Clear filters</Button> : null
              }
            >
              Try a different medicine name, category, prescription filter, or
              pharmacy branch.
            </EmptyState>
          ) : null}
        </div>

        {state.phase === 'done' && state.items.length > 0 ? (
          <>
            <div className="catalog-results-summary">
              <div>
                <strong>{meta?.total ?? state.items.length}</strong>{' '}
                {(meta?.total ?? state.items.length) === 1
                  ? 'medicine'
                  : 'medicines'}
                {filtered ? ' match your filters' : ''}
              </div>

              {branchName ? (
                <div className="catalog-branch-context">
                  <i className="bi bi-shop" aria-hidden="true"></i>
                  Viewing medicines currently available at{' '}
                  <strong>{branchName}</strong>
                </div>
              ) : (
                <div className="catalog-branch-context">
                  <i className="bi bi-building" aria-hidden="true"></i>
                  Showing availability across all branches
                </div>
              )}
            </div>

            <div className="cards-container catalog-grid" role="list">
              {state.items.map((medicine) => (
                <article
                  className="card catalog-card"
                  role="listitem"
                  key={medicine.id}
                >
                  <div className="card-image catalog-card-image">
                    {medicine.image_url ? (
                      <img src={medicine.image_url} alt="" />
                    ) : (
                      <i className="bi bi-capsule-pill" aria-hidden="true"></i>
                    )}
                  </div>

                  <div className="card-body catalog-card-body">
                    <div className="catalog-card-topline">
                      <span
                        className={
                          medicine.prescription_required
                            ? 'catalog-rx-badge catalog-rx-badge--required'
                            : 'catalog-rx-badge catalog-rx-badge--otc'
                        }
                      >
                        {medicine.prescription_required
                          ? 'Rx required'
                          : 'No prescription'}
                      </span>
                    </div>

                    <h2 className="card-title catalog-card-title">
                      {displayName(medicine)}
                    </h2>

                    <p className="catalog-medicine-meta">
                      {[medicine.strength, medicine.dosage_form]
                        .filter(Boolean)
                        .join(' · ')}
                    </p>

                    <p className="catalog-category">
                      <i className="bi bi-tag" aria-hidden="true"></i>{' '}
                      {medicine.category}
                    </p>

                    <Availability
                      medicine={medicine}
                      branchId={filters.branch}
                      branches={branches}
                      origin={origin}
                    />

                    <div className="catalog-card-actions">
                      {!isStaffRole(role) ? (
                        filters.branch ? (
                          <button
                            type="button"
                            className="reserve-button catalog-reserve-button"
                            disabled={reservingId === medicine.id}
                            onClick={() => reserveFromSelectedBranch(medicine)}
                          >
                            <i className="bi bi-bag-plus" aria-hidden="true"></i>{' '}
                            {reservingId === medicine.id
                              ? 'Checking stock...'
                              : `Reserve at ${branchName}`}
                            <span className="visually-hidden">
                              {' '}
                              {displayName(medicine)}
                            </span>
                          </button>
                        ) : (
                          <button
                            type="button"
                            className="reserve-button"
                            onClick={() =>
                              searchForMedicine(
                                medicine.id,
                                displayName(medicine),
                              )
                            }
                          >
                            <i className="bi bi-geo-alt" aria-hidden="true"></i>{' '}
                            Check availability
                            <span className="visually-hidden">
                              {' '}
                              for {displayName(medicine)}
                            </span>
                          </button>
                        )
                      ) : null}

                      <Button
                        to={`/medicines/${medicine.id}`}
                        block
                        className="result-card-details-link catalog-details-button"
                      >
                        <i className="bi bi-info-circle" aria-hidden="true"></i>{' '}
                        View details
                        <span className="visually-hidden">
                          {' '}
                          for {displayName(medicine)}
                        </span>
                      </Button>
                    </div>
                  </div>
                </article>
              ))}
            </div>

            {lastPage > 1 ? (
              <nav className="notif-pager catalog-pagination" aria-label="Pages of medicines">
                <Button
                  onClick={() => goTo(Math.max(1, page - 1))}
                  disabled={page <= 1}
                >
                  <i className="bi bi-chevron-left" aria-hidden="true"></i>{' '}
                  Previous
                </Button>

                <span>
                  Page {meta?.page ?? page} of {lastPage}
                </span>

                <Button
                  onClick={() => goTo(Math.min(lastPage, page + 1))}
                  disabled={page >= lastPage}
                >
                  Next{' '}
                  <i className="bi bi-chevron-right" aria-hidden="true"></i>
                </Button>
              </nav>
            ) : null}
          </>
        ) : null}
      </section>
    </div>
  )
}