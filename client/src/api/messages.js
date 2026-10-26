// The single place that turns an error code into a sentence a person can act on.

const FRIENDLY = {
  INVALID_CREDENTIALS:
    'The email or password is not correct. Please check them and try again.',

  ACCOUNT_LOCKED:
    'Too many failed log-in attempts, so this account is locked for a short while. Please try again later.',

  ACCOUNT_INACTIVE:
  'This account is inactive. Please contact the pharmacy for assistance.',

  EMAIL_NOT_VERIFIED:
    'Please verify your email address first. Use the 6-digit code that was sent to you.',

  EMAIL_EXISTS:
    'An account with this email address already exists. Try logging in instead.',

  INVALID_CODE:
    'That code is not correct. Please check the 6 digits in your email.',

  CODE_EXPIRED:
    'That code has expired. Please request a new verification code and try again.',

  VALIDATION_FAILED:
    'Please check the highlighted fields and try again.',

  UNAUTHENTICATED:
    'Please log in to continue.',

  TOKEN_EXPIRED:
    'Your session has ended. Please log in again.',

  FORBIDDEN:
    'You do not have permission to do this.',

  NOT_FOUND:
    'We could not find what you asked for.',

  PAYLOAD_TOO_LARGE:
    'That file is too large. The prescription photo must be 4 MB or smaller.',

  UNSUPPORTED_MEDIA_TYPE:
    'That kind of file is not supported.',

  SERVICE_UNAVAILABLE:
    'The service is not available right now. Please try again in a few minutes.',

  UPSTREAM_TIMEOUT:
    'The service took too long to answer. Please try again.',

  BAD_UPSTREAM_RESPONSE:
    'The service sent an answer we could not use. Please try again in a few minutes.',

  STOCK_INSUFFICIENT:
    'There is not enough stock for some of the items in your cart. Please change the quantities below.',

  PRESCRIPTION_REQUIRED:
    'A photo of the prescription is needed for one or more of these medicines.',

  DUPLICATE_REQUEST:
    'This reservation was already sent, or it is still being processed. Please check My Reservations before you try again.',

  IDEMPOTENCY_KEY_REQUIRED:
    'The request could not be sent safely. Please try again.',

  INVALID_TRANSITION:
    'This reservation can no longer be changed. It may already be cancelled, rejected, completed or expired.',

  INVALID_CLAIM_CODE:
    'That claim code does not match this reservation. Check the code the customer shows and try again.',

  RESERVATION_EXPIRED:
    'This reservation has expired.',

  HOLD_NOT_AVAILABLE:
    'The stock held for this reservation is no longer available. Please ask the branch for help.',

  NETWORK_ERROR:
    'We could not reach the server. Please check your internet connection and try again.',
}

/**
 * TOO_MANY_REQUESTS reads the Retry-After header. Note: the gateway lists only
 * X-Request-Id as an exposed CORS header, so a browser on another origin usually
 * cannot read Retry-After; the message then says to wait a minute.
 */
export function friendlyMessage(error) {
  const code = error?.code

  if (code === 'TOO_MANY_REQUESTS') {
    const seconds = error.retryAfter

    if (Number.isFinite(seconds) && seconds > 0) {
      return seconds > 90
        ? `You are doing that too often. Please wait about ${Math.ceil(seconds / 60)} minutes and try again.`
        : `You are doing that too often. Please wait ${seconds} seconds and try again.`
    }

    return 'You are doing that too often. Please wait a minute and try again.'
  }

  return FRIENDLY[code] ?? 'Something went wrong. Please try again.'
}

/**
 * For STOCK_INSUFFICIENT:
 * [{ medicineId, requested, available }]
 */
export function shortages(error) {
  const items =
    error?.code === 'STOCK_INSUFFICIENT'
      ? error.details?.items
      : null

  if (!Array.isArray(items)) {
    return []
  }

  return items
    .filter((item) => item && item.sufficient === false)
    .map((item) => ({
      medicineId: Number(item.medicine_id),
      requested: Number(item.requested),
      available: Number(item.available),
    }))
}

/**
 * Field messages for VALIDATION_FAILED:
 * { fieldName: ['message', ...] }
 */
export function fieldErrors(error) {
  if (
    error?.code !== 'VALIDATION_FAILED' ||
    error.details === null ||
    typeof error.details !== 'object'
  ) {
    return {}
  }

  const result = {}

  for (const [field, messages] of Object.entries(error.details)) {
    if (Array.isArray(messages) && messages.length > 0) {
      result[field] = messages.map(String)
    }
  }

  return result
}