import { useEffect, useRef, useState } from 'react'
import { getMedicines } from '../api/index.js'
import Button from '../components/ui/Button.jsx'
import EmptyState from '../components/ui/EmptyState.jsx'
import ErrorState from '../components/ui/ErrorState.jsx'
import LoadingState from '../components/ui/LoadingState.jsx'
import PageHeader from '../components/ui/PageHeader.jsx'
import SelectField from '../components/ui/SelectField.jsx'
import FormField from '../components/ui/FormField.jsx'
import { useSearch } from '../context/useSearch.js'
import { useCategoryOptions } from '../hooks/useCategoryOptions.js'
import { usePageTitle } from '../hooks/usePageTitle.js'
import { displayName, RX_OPTIONS } from '../utils/catalog.js'

const PER_PAGE = 12

/**
 * Browse the whole medicine catalog without typing anything: a name box, a category and a prescription filter over the catalog
 * endpoint (GET /medicines), paged. Each card opens Medicine Details or runs the same availability search as the details page
 * (stock per branch, with the Reserve button), so reserving works exactly as it does from Home.
 */
export default function Medicines() {
  usePageTitle('Medicines')
  const { searchForMedicine } = useSearch()
  const categoryOptions = useCategoryOptions()
  const [typed, setTyped] = useState('')
  const [filters, setFilters] = useState({ q: '', category: '', rx: '' })
  const [page, setPage] = useState(1)
  const [reloadKey, setReloadKey] = useState(0)
  const [state, setState] = useState({ phase: 'loading' })
  const heading = useRef(null)

  useEffect(() => {
    const controller = new AbortController()
    getMedicines(
      { q: filters.q || undefined, category: filters.category || undefined, rx: filters.rx || undefined, page, per_page: PER_PAGE },
      { signal: controller.signal },
    )
      .then(({ data }) => setState({ phase: 'done', items: data.items ?? [], meta: data.meta ?? null }))
      .catch((error) => {
        if (error?.name !== 'CanceledError') setState({ phase: 'error', error })
      })
    return () => controller.abort()
  }, [filters, page, reloadKey])

  function change(next) {
    setState({ phase: 'loading' })
    setFilters((current) => ({ ...current, ...next }))
    setPage(1)
  }

  function submit(event) {
    event.preventDefault()
    change({ q: typed.trim() })
  }

  function clear() {
    setTyped('')
    change({ q: '', category: '', rx: '' })
  }

  function goTo(nextPage) {
    setState({ phase: 'loading' })
    setPage(nextPage)
    heading.current?.focus()
  }

  const filtered = filters.q !== '' || filters.category !== '' || filters.rx !== ''
  const meta = state.meta
  const lastPage = meta?.last_page ?? 1

  return (
    <div className="legacy-home">
      <section className="branch-container" aria-labelledby="medicines-heading">
        <PageHeader
          headingRef={heading}
          title={<span id="medicines-heading">Medicines</span>}
          subtitle="Browse everything we carry. Open a medicine for details, or check which branches have it in stock."
        />

        <form className="global-filter" onSubmit={submit} role="search" aria-label="Filter the medicine list" style={{ marginTop: 0 }}>
          <div className="search-filters">
            <FormField label="Name (generic or brand)">
              {(controlProps) => (
                <input {...controlProps} type="search" name="q" value={typed} maxLength={100} autoComplete="off" onChange={(event) => setTyped(event.target.value)} />
              )}
            </FormField>
            <SelectField label="Category" value={filters.category} onChange={(value) => change({ category: value })} options={categoryOptions} placeholder="All categories" />
            <SelectField label="Prescription" value={filters.rx} onChange={(value) => change({ rx: value })} options={RX_OPTIONS} placeholder="Any" />
          </div>
          <div className="search-actions" style={{ marginTop: 0 }}>
            <Button type="submit" variant="primary">
              <i className="bi bi-search" aria-hidden="true"></i> Search
            </Button>
            {filtered || typed !== '' ? <Button onClick={clear}>Clear filters</Button> : null}
          </div>
        </form>

        <div aria-live="polite" className="search-results">
          {state.phase === 'loading' ? <LoadingState>Loading medicines...</LoadingState> : null}
          {state.phase === 'error' ? (
            <ErrorState
              error={state.error}
              onRetry={() => {
                setState({ phase: 'loading' })
                setReloadKey((key) => key + 1)
              }}
            />
          ) : null}
          {state.phase === 'done' && state.items.length === 0 ? (
            <EmptyState icon="bi bi-search" title="No medicines found" actions={filtered ? <Button onClick={clear}>Clear filters</Button> : null}>
              Try a different name, category or prescription filter.
            </EmptyState>
          ) : null}
        </div>

        {state.phase === 'done' && state.items.length > 0 ? (
          <>
            <p className="field-hint">
              {meta?.total ?? state.items.length} {(meta?.total ?? state.items.length) === 1 ? 'medicine' : 'medicines'}
              {filtered ? ' match your filters' : ''}. Page {meta?.page ?? page} of {lastPage}.
            </p>
            <div className="cards-container" role="list">
              {state.items.map((medicine) => (
                <div className="card" role="listitem" key={medicine.id}>
                  <div className="card-image">
                    {medicine.image_url ? <img src={medicine.image_url} alt="" /> : <i className="bi bi-capsule" aria-hidden="true"></i>}
                  </div>
                  <div className="card-body">
                    <h2 className="card-title" style={{ fontSize: '1.05rem' }}>{displayName(medicine)}</h2>
                    <div className="prescription-status">{medicine.prescription_required ? 'Prescription required' : 'Non-prescribed Medication'}</div>
                    <div className="card-category">
                      {medicine.strength} &middot; {medicine.dosage_form}
                    </div>
                    <div className="card-category">{medicine.category}</div>
                    <button type="button" className="reserve-button" onClick={() => searchForMedicine(medicine.id, displayName(medicine))}>
                      Check availability <span className="visually-hidden">for {displayName(medicine)}</span>
                    </button>
                    <Button to={`/medicines/${medicine.id}`} block className="result-card-details-link">
                      View details <span className="visually-hidden">for {displayName(medicine)}</span>
                    </Button>
                  </div>
                </div>
              ))}
            </div>

            {lastPage > 1 ? (
              <nav className="notif-pager" aria-label="Pages of medicines" style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', gap: 16, margin: '24px 0' }}>
                <Button onClick={() => goTo(Math.max(1, page - 1))} disabled={page <= 1}>
                  <i className="bi bi-chevron-left" aria-hidden="true"></i> Previous
                </Button>
                <span>
                  Page {meta?.page ?? page} of {lastPage}
                </span>
                <Button onClick={() => goTo(Math.min(lastPage, page + 1))} disabled={page >= lastPage}>
                  Next <i className="bi bi-chevron-right" aria-hidden="true"></i>
                </Button>
              </nav>
            ) : null}
          </>
        ) : null}
      </section>
    </div>
  )
}
