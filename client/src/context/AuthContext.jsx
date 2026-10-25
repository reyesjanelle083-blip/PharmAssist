import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { login as apiLogin, me as apiMe, setToken, setUnauthorizedHandler } from '../api/index.js'
import { readJson, remove, writeJson } from '../utils/storage.js'
import { AuthContext } from './authContextObject.js'

const STORAGE_KEY = 'pharmassist.session'

/*
 * The token and the user are kept in sessionStorage so a page reload does not log the person out, and the session ends when
 * the tab is closed. sessionStorage can be read by any script running on the page, so an XSS bug would expose the token.
 * That is an accepted trade-off for this school project (plan section 21, limitation 8): the alternative (an httpOnly
 * cookie) would need cookie and CSRF handling in the gateway, which is out of scope. The token is never logged or put in a URL.
 */

export function AuthProvider({ children }) {
  const navigate = useNavigate()
  const [session, setSession] = useState(() => {
    const stored = readJson('session', STORAGE_KEY)
    const valid = stored?.token && stored?.user ? stored : null
    setToken(valid?.token ?? null) // before any child effect can make a call
    return valid
  })
  const [restoring, setRestoring] = useState(session !== null)

  const store = useCallback((next) => {
    setToken(next?.token ?? null)
    setSession(next)
    if (next) writeJson('session', STORAGE_KEY, next)
    else remove('session', STORAGE_KEY)
  }, [])

  const logout = useCallback(() => store(null), [store])

  // The gateway says the token is missing, invalid or expired (401 UNAUTHENTICATED or TOKEN_EXPIRED): end the session, go to log in.
  useEffect(() => {
    setUnauthorizedHandler(() => {
      store(null)
      navigate('/login', { replace: true, state: { notice: 'session_expired' } })
    })
    return () => setUnauthorizedHandler(null)
  }, [navigate, store])

  // Restore on load: the stored token must still be good, and the user record is refreshed from the server.
  useEffect(() => {
    if (session === null) return undefined
    let active = true
    setToken(session.token)

    apiMe()
      .then(({ data }) => {
        if (active) store({ token: session.token, user: data })
      })
      .catch((error) => {
        // A 401 has already ended the session through the handler above. When the server just cannot be reached,
        // keep the stored session: the next call will tell.
        if (active && error?.code !== 'NETWORK_ERROR') store(null)
      })
      .finally(() => {
        if (active) setRestoring(false)
      })

    return () => {
      active = false
    }
    // Only on load: the later changes to `session` come from this provider itself.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const login = useCallback(
    async (email, password) => {
      const { data } = await apiLogin(email, password)
      store({ token: data.access_token, user: data.user })
      return data.user
    },
    [store],
  )

  const value = useMemo(
    () => ({
      user: session?.user ?? null,
      role: session?.user?.role ?? null,
      loading: restoring,
      login,
      logout,
    }),
    [session, restoring, login, logout],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
