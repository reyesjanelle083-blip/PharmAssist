/**
 * What every failed call throws. `code` is the gateway's error code (or NETWORK_ERROR when no answer came back),
 * `requestId` is the gateway's X-Request-Id so a problem can be traced in the gateway logs.
 */
export class ApiError extends Error {
  constructor({ status = 0, code = 'UNKNOWN_ERROR', message = '', details = null, requestId = null, retryAfter = null }) {
    super(message || code)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.details = details
    this.requestId = requestId
    // Seconds from the Retry-After header, when the browser is allowed to read it (see messages.js)
    this.retryAfter = retryAfter
  }
}
