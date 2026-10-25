import { useEffect } from 'react'

/** Sets the browser tab title; screen readers announce it when the page changes. */
export function usePageTitle(title) {
  useEffect(() => {
    document.title = title ? `${title} - PharmAssist` : 'PharmAssist'
  }, [title])
}
