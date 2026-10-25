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
        setState({ phase: 'error', message: 'Your location looks like it is outside the Philippines, so we cannot rank branches by distance. Please search again without it.', error })
        return
      }
      setState({ phase: 'error', error })
    }
  }

  return (
    <section aria-labelledby="search-heading" className="search">
      <h2 id="search-heading">Find a medicine</h2>

      <form onSubmit={submit} className="search__form" noValidate>
        <label htmlFor={inputId}>Medicine name (generic or brand)</label>
        <input
          id={inputId}
          type="search"
          name="q"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          maxLength={100}
          autoComplete="off"
          placeholder="For example: paracetamol or Biogesic"
        />

        <div className="search__actions">
          {origin ? (
            <button type="button" className="button button--outline" onClick={clearLocation}>
              Stop using my location
            </button>
          ) : (
            <button type="button" className="button button--outline" onClick={shareLocation} disabled={locating}>
              {locating ? 'Finding location...' : 'Use my location'}
            </button>
          )}
          <button type="submit" className="button" disabled={state.phase === 'loading'}>
            {state.phase === 'loading' ? 'Searching...' : 'Search'}
          </button>
        </div>

        <p className="field__hint">Your location is sent only for this search, ranked by distance, and is not stored.</p>
        <p className="search__geo" role="status">
          {geoStatus}
        </p>
      </form>

      <div aria-live="polite" className="search__results">
        {state.phase === 'loading' ? <p className="loading">Searching...</p> : null}

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
      <h3 ref={headingRef} tabIndex={-1} className="results__heading">
        {empty ? `No results for "${q}"` : `${items.length} ${items.length === 1 ? 'result' : 'results'} for "${q}"`}
        {!empty && usedLocation ? ', nearest first' : ''}
      </h3>

      {notes.map((note) => (
        <p key={note.code} className={note.code === 'NO_STOCK_FOUND' ? 'notice notice--info' : 'notice notice--warning'}>
          {note.text}
        </p>
      ))}

      {empty ? null : (
        <ol className="results__list">
          {items.map((item) => (
            <li key={`${item.branch_id}-${item.medicine_id}`}>
              <ResultCard item={item} headingLevel={4} />
            </li>
          ))}
        </ol>
      )}
    </>
  )
}
