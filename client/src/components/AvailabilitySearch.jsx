import { useEffect, useId, useRef, useState } from 'react'
import { fieldErrors, friendlyMessage, searchAvailability } from '../api/index.js'
import { warningText } from '../utils/warnings.js'
import ErrorMessage from './ErrorMessage.jsx'
import ResultCard from './ResultCard.jsx'

const GEO_MESSAGES = {
  1: 'Location was blocked. You can still search; results will not be ranked by distance.',
  2: 'Your location could not be found right now. You can still search without it.',
  3: 'Finding your location took too long. You can still search without it.',
}

/**
 * The home page search. The location is read only when the button is pressed, lives only in this component's state and is
 * sent only with the search request: it is not kept in the page address, storage or any log.
 *
 * Markup follows the old medicines page: a white filter panel (global-filter) with the pill search bar (ps-bar), the green and
 * blue-grey buttons (filter-btn, toggle-all-btn), then the search banner and the card grid (cards-container).
 */
export default function AvailabilitySearch() {
  const inputId = useId()
  const [query, setQuery] = useState('')
  const [origin, setOrigin] = useState(null) // { lat, lng } while shared
  const [geoStatus, setGeoStatus] = useState('') // text for the live region
  const [locating, setLocating] = useState(false)
  const [state, setState] = useState({ phase: 'idle' }) // idle | loading | done | error
  const controller = useRef(null)
  const resultsHeading = useRef(null)

  useEffect(() => () => controller.current?.abort(), [])

  function shareLocation() {
    if (!('geolocation' in navigator)) {
      setGeoStatus('Your browser cannot share a location. You can still search without it.')
      return
    }
    setLocating(true)
    setGeoStatus('Finding your location...')
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setOrigin({ lat: Number(position.coords.latitude.toFixed(6)), lng: Number(position.coords.longitude.toFixed(6)) })
        setGeoStatus('Location shared for this search. Branches will be ranked by distance.')
        setLocating(false)
      },
      (error) => {
        setOrigin(null)
        setGeoStatus(GEO_MESSAGES[error.code] ?? GEO_MESSAGES[2])
        setLocating(false)
      },
      { enableHighAccuracy: false, timeout: 10000, maximumAge: 0 },
    )
  }

  function clearLocation() {
    setOrigin(null)
    setGeoStatus('Location removed. Results will not be ranked by distance.')
  }

  async function submit(event) {
    event.preventDefault()
    const q = query.trim()
    if (q === '') {
      setState({ phase: 'error', message: 'Please type the name of a medicine to search.' })
      return
    }

    controller.current?.abort()
    controller.current = new AbortController()
    setState({ phase: 'loading' })

    try {
      const { data, warnings } = await searchAvailability({ q, ...(origin ?? {}) }, { signal: controller.current.signal })
      setState({ phase: 'done', q, items: data.items ?? [], warnings, usedLocation: origin !== null })
      // Let screen reader users hear that the results are there.
      requestAnimationFrame(() => resultsHeading.current?.focus())
    } catch (error) {
      if (error?.name === 'CanceledError') return // replaced by a newer search

      const details = fieldErrors(error)
      if (details.lat || details.lng) {
        setOrigin(null)
        setGeoStatus('')
        setState({ phase: 'error', error, message: 'Your location looks like it is outside the Philippines, so we cannot rank branches by distance. Please search again without it.' })
        return
      }
      setState({ phase: 'error', error })
    }
  }

  return (
    <section id="search" aria-labelledby="search-heading" className="branch-container">
      <div className="branch-header">
        <h2 id="search-heading" className="branch-title">
          Find a medicine
        </h2>
      </div>

      <form onSubmit={submit} noValidate className="global-filter" style={{ marginTop: 0 }}>
        <label htmlFor={inputId} className="global-filter-title">
          Medicine name (generic or brand)
        </label>
        <div className="ps-bar">
          <input
            id={inputId}
            className="ps-input"
            type="search"
            name="q"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            maxLength={100}
            autoComplete="off"
            placeholder="Search medicines..."
          />
          <button type="submit" className="ps-search-btn" aria-label="Search" disabled={state.phase === 'loading'}>
            <i className="bi bi-search" aria-hidden="true"></i>
          </button>
        </div>

        <div className="search-actions">
          {origin ? (
            <button type="button" className="toggle-all-btn" onClick={clearLocation}>
              <i className="bi bi-geo-alt-fill" aria-hidden="true"></i> Stop using my location
            </button>
          ) : (
            <button type="button" className="toggle-all-btn" onClick={shareLocation} disabled={locating}>
              <i className="bi bi-geo-alt" aria-hidden="true"></i> {locating ? 'Finding location...' : 'Use my location'}
            </button>
          )}
          <button type="submit" className="filter-btn" style={{ backgroundColor: '#84B179' }} disabled={state.phase === 'loading'}>
            {state.phase === 'loading' ? 'Searching...' : 'Search'}
          </button>
          <span className="filter-status" role="status">
            {geoStatus || 'Location not shared'}
          </span>
        </div>

        <p className="field-hint">Your location is sent only for this search, ranked by distance, and is not stored.</p>
      </form>

      <div aria-live="polite" className="search-results">
        {state.phase === 'loading' ? <p className="filter-status" style={{ display: 'inline-block' }}>Searching...</p> : null}

        {state.phase === 'error' ? <ErrorMessage error={state.error} message={state.message ?? friendlyMessage(state.error)} /> : <ErrorMessage />}

        {state.phase === 'done' ? <Results {...state} headingRef={resultsHeading} /> : null}
      </div>
    </section>
  )
}

function Results({ q, items, warnings, usedLocation, headingRef }) {
  const notes = warnings.map((code) => ({ code, text: warningText(code) })).filter((note) => note.text !== null)
  const empty = items.length === 0

  return (
    <>
      <div className="search-result-banner">
        <h3 ref={headingRef} tabIndex={-1} className="results-heading">
          Search Results for &quot;{q}&quot;
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
          <p>Try searching with a different keyword.</p>
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
