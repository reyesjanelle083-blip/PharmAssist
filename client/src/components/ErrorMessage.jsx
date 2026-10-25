import { friendlyMessage } from '../api/messages.js'

/**
 * A friendly error in a live region, with the request id so a problem can be traced in the gateway logs.
 * It uses the old red alert colours. `children` can add a hint such as a link.
 */
export default function ErrorMessage({ error, message, children }) {
  if (!error && !message) return <div role="alert" className="alert-slot" />

  return (
    <div role="alert" className="app-notice app-notice--error">
      <p>
        <i className="fas fa-exclamation-circle" aria-hidden="true" style={{ marginRight: 8 }}></i>
        <strong>Error: </strong>
        {message ?? friendlyMessage(error)}
      </p>
      {children}
      {error?.requestId ? <p className="app-notice__reference">Reference: {error.requestId}</p> : null}
    </div>
  )
}
