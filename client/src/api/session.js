// The one place that holds the bearer token for the request layer, and the one hook for "the session is no longer valid".
// It is deliberately tiny and never logs anything: the token must not end up in a console or an error message.

let token = null
let unauthorizedHandler = null

export function setToken(value) {
  token = typeof value === 'string' && value !== '' ? value : null
}

export function getToken() {
  return token
}

/** AuthContext registers what to do when the gateway says the token is missing, invalid or expired. */
export function setUnauthorizedHandler(handler) {
  unauthorizedHandler = handler
}

export function notifyUnauthorized(code) {
  if (unauthorizedHandler) unauthorizedHandler(code)
}
