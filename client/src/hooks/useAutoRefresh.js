import { useEffect, useRef } from 'react'

/**
 * Calls refresh() every intervalMs while enabled, and once more when the tab becomes visible again. It never fires while the tab is
 * hidden, so a forgotten tab does not keep asking the server.
 */
export function useAutoRefresh(refresh, { enabled, intervalMs = 20000 }) {
  const latest = useRef(refresh)
  useEffect(() => {
    latest.current = refresh
  })

  useEffect(() => {
    if (!enabled) return undefined
    const tick = () => {
      if (document.visibilityState === 'visible') latest.current()
    }
    const timer = setInterval(tick, intervalMs)
    document.addEventListener('visibilitychange', tick)
    return () => {
      clearInterval(timer)
      document.removeEventListener('visibilitychange', tick)
    }
  }, [enabled, intervalMs])
}
