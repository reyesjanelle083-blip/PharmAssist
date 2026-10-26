import http from './http.js'

export function login(email, password) {
  return http.post('/auth/login', { email, password })
}

export function register({
  email,
  password,
  first_name,
  last_name,
  contact_no,
}) {
  return http.post('/auth/register', {
    email,
    password,
    first_name,
    last_name,
    contact_no,
  })
}

export function verifyEmail(email, code) {
  return http.post('/auth/verify-email', { email, code })
}

export function forgotPassword(email) {
  return http.post('/auth/forgot-password', { email })
}

export function resetPassword({
  email,
  code,
  password,
  password_confirmation,
}) {
  return http.post('/auth/reset-password', {
    email,
    code,
    password,
    password_confirmation,
  })
}

export function me() {
  return http.get('/auth/me')
}