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

  const submit = useCallback(
    async (event) => {
      event.preventDefault()
      const q = query.trim()

      // Results are shown on the home page: searching from another page takes you there.
      if (location.pathname !== '/') navigate('/')

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
    },
    [query, origin, location.pathname, navigate],
  )

  const value = useMemo(
    () => ({ query, setQuery, origin, geoStatus, locating, state, shareLocation, clearLocation, submit, resultsHeading }),
    [query, origin, geoStatus, locating, state, shareLocation, clearLocation, submit],
  )

  return <SearchContext.Provider value={value}>{children}</SearchContext.Provider>
}
