import { friendlyMessage } from '../api/messages.js'

/**
 * A friendly error in a live region, with the request id so a problem can be traced in the gateway logs.
 * `children` can add a hint such as a link.
 */
export default function ErrorMessage({ error, message, children }) {
  if (!error && !message) return <div role="alert" className="alert-slot" />

  return (
    <div role="alert" className="notice notice--error">
      <p className="notice__text">{message ?? friendlyMessage(error)}</p>
      {children}
      {error?.requestId ? <p className="notice__reference">Reference: {error.requestId}</p> : null}
    </div>
  )
}
