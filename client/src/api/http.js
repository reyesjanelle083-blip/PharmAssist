import axios from 'axios'
import { ApiError } from './ApiError.js'
import { getToken, notifyUnauthorized } from './session.js'

// Only the public gateway URL lives in client code. Never put keys or secrets here.
const http = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  timeout: 20000, // the gateway's slowest routes wait 10 s on a service; this is just a safety net
})

// ---- request layer: Accept header and the bearer token (never logged) ----
http.interceptors.request.use((config) => {
  config.headers.Accept = 'application/json'
  const token = getToken()
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

const ENVELOPE_KEYS = ['status', 'service', 'request_id', 'data', 'error', 'warnings']

function isEnvelope(body) {
  return body !== null && typeof body === 'object' && ENVELOPE_KEYS.every((key) => key in body)
}

function headerValue(headers, name) {
  const value = headers?.[name]
  return typeof value === 'string' && value !== '' ? value : null
}

function toApiError(error) {
  const response = error.response

  // No answer at all: offline, DNS, a CORS refusal, or a timeout.
  if (!response) {
    return new ApiError({ code: 'NETWORK_ERROR', message: 'The server could not be reached.' })
  }

  const body = response.data
  const requestId = (isEnvelope(body) ? body.request_id : null) ?? headerValue(response.headers, 'x-request-id')
  const retryAfter = Number.parseInt(headerValue(response.headers, 'retry-after') ?? '', 10)

  if (isEnvelope(body) && body.error) {
    return new ApiError({
      status: response.status,
      code: body.error.code ?? 'UNKNOWN_ERROR',
      message: body.error.message ?? '',
      details: body.error.details ?? null,
      requestId,
      retryAfter: Number.isFinite(retryAfter) ? retryAfter : null,
    })
  }

  // Not an envelope (a proxy page, for example): still an error, with no details to show.
  return new ApiError({
    status: response.status,
    code: response.status === 429 ? 'TOO_MANY_REQUESTS' : 'BAD_UPSTREAM_RESPONSE',
    requestId,
    retryAfter: Number.isFinite(retryAfter) ? retryAfter : null,
  })
}

// ---- response layer: unwrap the envelope ----
http.interceptors.response.use(
  (response) => {
    const body = response.data
    if (!isEnvelope(body)) {
      throw new ApiError({ status: response.status, code: 'BAD_UPSTREAM_RESPONSE', requestId: headerValue(response.headers, 'x-request-id') })
    }

    return {
      data: body.data,
      warnings: Array.isArray(body.warnings) ? body.warnings : [],
      meta: body.meta ?? body.data?.meta ?? null,
      requestId: body.request_id ?? null,
    }
  },
  (error) => {
    if (axios.isCancel(error)) return Promise.reject(error) // a search that was replaced by a newer one

    const apiError = toApiError(error)
    if (apiError.status === 401 && (apiError.code === 'UNAUTHENTICATED' || apiError.code === 'TOKEN_EXPIRED')) {
      notifyUnauthorized(apiError.code)
    }

    return Promise.reject(apiError)
  },
)

export default http
