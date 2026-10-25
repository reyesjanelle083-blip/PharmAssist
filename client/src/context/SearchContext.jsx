import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { fieldErrors, searchAvailability } from '../api/index.js'
import { SearchContext } from './searchContextObject.js'

const GEO_MESSAGES = {
  1: 'Location was blocked. You can still search; results will not be ranked by distance.',
  2: 'Your location could not be found right now. You can still search without it.',
  3: 'Finding your location took too long. You can still search without it.',
}

/**
 * The medicine search. It lives in the shell (above the pages) so the search bar can sit in the top bar, as on the old pages,
 * while the results are shown on the home page. Same behavior as before: the location is read only when the button is pressed,
 * stays in memory, and is sent only with the search request (never kept in the address, storage or any log).
 */
export function SearchProvider({ children }) {
  const navigate = useNavigate()
  const location = useLocation()
  const [query, setQuery] = useState('')
  const [category, setCategory] = useState('')
  const [rx, setRx] = useState('') // '' | 'true' | 'false'
  const [origin, setOrigin] = useState(null) // { lat, lng } while shared
  const [geoStatus, setGeoStatus] = useState('')
  const [locating, setLocating] = useState(false)
  const [state, setState] = useState({ phase: 'idle' }) // idle | loading | done | error
  const controller = useRef(null)
  const resultsHeading = useRef(null)

  useEffect(() => () => controller.current?.abort(), [])

  const shareLocation = useCallback(() => {
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
  }, [])

  const clearLocation = useCallback(() => {
    setOrigin(null)
    setGeoStatus('Location removed. Results will not be ranked by distance.')
  }, [])

  // One request to inventory-service, shared by a typed search and a filter-only search (a category or rx alone).
  const run = useCallback(
    async ({ q, medicineId, label }) => {
      controller.current?.abort()
      controller.current = new AbortController()
      setState({ phase: 'loading' })

      // An exact medicine_id lookup ignores category and rx on the backend, so they are left out here too.
      const filters = medicineId ? {} : { category, rx: rx || undefined }

      try {
        const { data, warnings } = await searchAvailability({ q, medicineId, ...filters, ...(origin ?? {}) }, { signal: controller.current.signal })
        setState({
          phase: 'done',
          q: label,
          category: medicineId ? '' : category,
          rx: medicineId ? '' : rx,
          items: data.items ?? [],
          warnings,
          usedLocation: origin !== null,
        })
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
    },
    [origin, category, rx],
  )

  const submit = useCallback(
    (event) => {
      event.preventDefault()
      const q = query.trim()

      // Results are shown on the home page: searching from another page takes you there.
      if (location.pathname !== '/') navigate('/')

      // A name, a category, or both: the gateway needs at least one. rx alone only narrows an existing search.
      if (q === '' && category === '') {
        setState({ phase: 'error', message: 'Please type the name of a medicine, or choose a category, to search.' })
        return
      }

      run({ q, label: q })
    },
    [query, category, location.pathname, navigate, run],
  )

  /** From Medicine Details: look up where this exact medicine is in stock, reusing the same results area. */
  const searchForMedicine = useCallback(
    (medicineId, label) => {
      if (location.pathname !== '/') navigate('/')
      setQuery('')
      run({ medicineId, label })
    },
    [location.pathname, navigate, run],
  )

  const value = useMemo(
    () => ({
      query,
      setQuery,
      category,
      setCategory,
      rx,
      setRx,
      origin,
      geoStatus,
      locating,
      state,
      shareLocation,
      clearLocation,
      submit,
      searchForMedicine,
      resultsHeading,
    }),
    [query, category, rx, origin, geoStatus, locating, state, shareLocation, clearLocation, submit, searchForMedicine],
  )

  return <SearchContext.Provider value={value}>{children}</SearchContext.Provider>
}
