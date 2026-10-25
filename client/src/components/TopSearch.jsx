import { useSearch } from '../context/useSearch.js'

export const LOCATION_NOTE = 'Your location is sent only for this search, ranked by distance, and is not stored.'

/**
 * The search bar in the top bar, as on the old pages (the pill search bar of medicines.php). It drives the same search as before
 * (SearchContext); the results appear on the home page. The "Use my location" button and the privacy note sit beside it.
 */
export default function TopSearch() {
  const { query, setQuery, origin, locating, state, shareLocation, clearLocation, submit } = useSearch()
  const busy = state.phase === 'loading'

  return (
    <form role="search" className="topbar-search" onSubmit={submit} noValidate>
      <label htmlFor="topbar-q" className="visually-hidden">
        Medicine name (generic or brand)
      </label>
      <div className="ps-bar">
        <input
          id="topbar-q"
          className="ps-input"
          type="search"
          name="q"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          maxLength={100}
          autoComplete="off"
          placeholder="Search medicines..."
        />
        <button type="submit" className="ps-search-btn" aria-label="Search" disabled={busy}>
          <i className="bi bi-search" aria-hidden="true"></i>
        </button>
      </div>

      <button
        type="button"
        className="topbar-locate"
        onClick={origin ? clearLocation : shareLocation}
        disabled={locating}
        aria-pressed={origin !== null}
        title={origin ? 'Stop using my location' : 'Use my location'}
      >
        <i className={origin ? 'bi bi-geo-alt-fill' : 'bi bi-geo-alt'} aria-hidden="true"></i>
        <span className="topbar-locate__text">{locating ? 'Finding...' : origin ? 'Stop using my location' : 'Use my location'}</span>
      </button>

      <span className="topbar-note">{LOCATION_NOTE}</span>
    </form>
  )
}
