import { useEffect, useState } from 'react'
import { friendlyMessage, getMedicineCategories } from '../api/index.js'
import { useSearch } from '../context/useSearch.js'
import { warningText } from '../utils/warnings.js'
import ErrorMessage from './ErrorMessage.jsx'
import ResultCard from './ResultCard.jsx'
import SelectField from './ui/SelectField.jsx'
import { LOCATION_NOTE } from './TopSearch.jsx'

const RX_OPTIONS = [
  { value: 'false', label: 'No prescription needed' },
  { value: 'true', label: 'Prescription required' },
]

/** The categories in the catalog, for the filter below. Optional: if this fails, the filter just offers "All categories". */
function useCategoryOptions() {
  const [categories, setCategories] = useState([])

  useEffect(() => {
    const controller = new AbortController()
    getMedicineCategories({ signal: controller.signal })
      .then(({ data }) => setCategories(data.items ?? []))
      .catch(() => {})
    return () => controller.abort()
  }, [])

  return categories.map((name) => ({ value: name, label: name }))
}

/**
 * The results area of the home page, in the old medicines page look: a white panel, the search banner and the card grid.
 * The search itself is started from the top bar (TopSearch); the state is shared through SearchContext. Category and
 * prescription filters live here and are sent together with the next search (press Search again to apply a change).
 */
export default function SearchResults() {
  const { origin, geoStatus, category, setCategory, rx, setRx, state, resultsHeading } = useSearch()
  const categoryOptions = useCategoryOptions()

  return (
    <section id="search" aria-labelledby="search-heading" className="branch-container">
      <div className="branch-header">
        <h2 id="search-heading" className="branch-title">
          Find a medicine
        </h2>
      </div>

      <div className="global-filter" style={{ marginTop: 0 }}>
        <p className="global-filter-title" style={{ marginBottom: 8 }}>
          Type a medicine name in the search bar at the top of the page, or narrow the list below, then press Search.
        </p>
        <div className="search-filters">
          <SelectField label="Category" value={category} onChange={setCategory} options={categoryOptions} placeholder="All categories" />
          <SelectField label="Prescription" value={rx} onChange={setRx} options={RX_OPTIONS} placeholder="Any" />
        </div>
        <div className="search-actions" style={{ marginTop: 0 }}>
          <span className="filter-status" role="status">
            {geoStatus || (origin ? 'Location shared' : 'Location not shared')}
          </span>
        </div>
        <p className="field-hint search-note-inline">{LOCATION_NOTE}</p>
      </div>

      <div aria-live="polite" className="search-results">
        {state.phase === 'loading' ? <p className="filter-status" style={{ display: 'inline-block' }}>Searching...</p> : null}

        {state.phase === 'error' ? <ErrorMessage error={state.error} message={state.message ?? friendlyMessage(state.error)} /> : <ErrorMessage />}

        {state.phase === 'done' ? <Results {...state} headingRef={resultsHeading} /> : null}
      </div>
    </section>
  )
}

function resultsLabel(q, category) {
  if (q && category) return <>&quot;{q}&quot; in {category}</>
  if (q) return <>&quot;{q}&quot;</>
  if (category) return category
  return 'all medicines'
}

function Results({ q, category, items, warnings, usedLocation, headingRef }) {
  const notes = warnings.map((code) => ({ code, text: warningText(code) })).filter((note) => note.text !== null)
  const empty = items.length === 0

  return (
    <>
      <div className="search-result-banner">
        <h3 ref={headingRef} tabIndex={-1} className="results-heading">
          Search Results for {resultsLabel(q, category)}
        </h3>
        <p>
          {empty ? 'No results' : `${items.length} ${items.length === 1 ? 'result' : 'results'}`}
          {!empty && usedLocation ? ', nearest first' : ''}
        </p>
      </div>

      {notes
        .filter((note) => !(empty && note.code === 'NO_STOCK_FOUND'))
        .map((note) => (
          <p key={note.code} className={note.code === 'ORIGIN_NOT_PROVIDED' ? 'app-notice app-notice--info' : 'app-notice app-notice--warning'}>
            {note.text}
          </p>
        ))}

      {empty ? (
        <div className="no-results-message">
          <i className="bi bi-search" aria-hidden="true"></i>
          <h4>No medicines found</h4>
          <p>{warningText('NO_STOCK_FOUND')}</p>
          <p>Try searching with a different keyword, or a different category or prescription filter.</p>
        </div>
      ) : (
        <div className="cards-container" role="list">
          {items.map((item) => (
            <ResultCard key={`${item.branch_id}-${item.medicine_id}`} item={item} />
          ))}
        </div>
      )}
    </>
  )
}
