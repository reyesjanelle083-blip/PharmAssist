import { useMemo, useState } from 'react'
import { friendlyMessage } from '../api/index.js'
import { useSearch } from '../context/useSearch.js'
import { useCategoryOptions } from '../hooks/useCategoryOptions.js'
import { RX_OPTIONS } from '../utils/catalog.js'
import { warningText } from '../utils/warnings.js'
import ErrorMessage from './ErrorMessage.jsx'
import ResultCard from './ResultCard.jsx'
import SelectField from './ui/SelectField.jsx'
import { LOCATION_NOTE } from './TopSearch.jsx'

const SORT_OPTIONS = [
  { value: 'nearest', label: 'Nearest first' },
  { value: 'most_stock', label: 'Most stock first' },
  { value: 'available_only', label: 'Available only (hide low stock)' },
]

/**
 * A client-side view of the already-fetched list: it never calls the server again. "Nearest" and "Most stock" re-order
 * the same rows (a stable sort, so rows without a distance just keep their current order instead of jumping around).
 * "Available only" hides rows the server marked low_stock or out_of_stock, keeping the rows' existing order.
 */
function sortItems(items, sortBy) {
  if (sortBy === 'available_only') return items.filter((item) => item.status === 'available')
  if (sortBy === 'most_stock') return [...items].sort((a, b) => b.available_quantity - a.available_quantity)
  // 'nearest': without a shared location every travel_min is null, so this is a no-op (everything ties, order is kept)
  return [...items].sort((a, b) => (a.travel_min ?? Infinity) - (b.travel_min ?? Infinity))
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

// Resets to "Nearest first" for every fresh set of results (the Results element is remounted each time a search
// finishes, see SearchResults below), rather than silently carrying a sort choice over to an unrelated search.
function Results({ q, category, items, warnings, usedLocation, headingRef }) {
  const [sortBy, setSortBy] = useState('nearest')
  const notes = warnings.map((code) => ({ code, text: warningText(code) })).filter((note) => note.text !== null)
  const empty = items.length === 0
  const sorted = useMemo(() => sortItems(items, sortBy), [items, sortBy])
  const hiddenByFilter = !empty && sorted.length === 0 // "Available only" hid every row; the search itself found something
  // Describe what is actually shown, not just how the server ranked it: "most stock" and "available only" are a client
  // choice made after the search, and "available only" can shrink the count below what the search itself found.
  const shownCount = sortBy === 'available_only' ? sorted.length : items.length
  const orderNote = sortBy === 'most_stock' ? ', most stock first' : sortBy === 'available_only' ? ', available only' : usedLocation ? ', nearest first' : ''

  return (
    <>
      <div className="search-result-banner">
        <h3 ref={headingRef} tabIndex={-1} className="results-heading">
          Search Results for {resultsLabel(q, category)}
        </h3>
        <p>
          {empty ? 'No results' : `${shownCount} ${shownCount === 1 ? 'result' : 'results'}`}
          {!empty ? orderNote : ''}
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
        <>
          <div style={{ maxWidth: 280, marginBottom: 16 }}>
            <SelectField label="Sort by" value={sortBy} onChange={setSortBy} options={SORT_OPTIONS} />
          </div>

          {hiddenByFilter ? (
            <div className="no-results-message">
              <i className="bi bi-funnel" aria-hidden="true"></i>
              <h4>Nothing available right now</h4>
              <p>Every branch that has this is low on stock. Choose a different sort to see them anyway.</p>
            </div>
          ) : (
            <div className="cards-container" role="list">
              {sorted.map((item) => (
                <ResultCard key={`${item.branch_id}-${item.medicine_id}`} item={item} />
              ))}
            </div>
          )}
        </>
      )}
    </>
  )
}
