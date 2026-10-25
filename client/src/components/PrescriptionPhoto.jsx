import { useEffect, useState } from 'react'
import { getPrescriptionBlob } from '../api/index.js'
import ErrorMessage from './ErrorMessage.jsx'

/** The prescription photo, fetched with the login (never a URL with a token), shown from an object URL that is released again. */
export default function PrescriptionPhoto({ id }) {
  const [state, setState] = useState({ phase: 'loading' })

  useEffect(() => {
    const controller = new AbortController()
    let url = null
    getPrescriptionBlob(id, { signal: controller.signal })
      .then((blob) => {
        url = URL.createObjectURL(blob)
        setState({ phase: 'done', url })
      })
      .catch((error) => {
        if (error?.name !== 'CanceledError') setState({ phase: 'error', error })
      })
    return () => {
      controller.abort()
      if (url) URL.revokeObjectURL(url)
    }
  }, [id])

  return (
    <div aria-live="polite">
      {state.phase === 'loading' ? <p className="waiting-text">Loading the prescription photo...</p> : null}
      {state.phase === 'error' ? <ErrorMessage error={state.error} /> : null}
      {state.phase === 'done' ? <img className="presc-photo" src={state.url} alt="The prescription photo you added to this reservation" /> : null}
    </div>
  )
}
