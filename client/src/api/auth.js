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

export function me() {
  return http.get('/auth/me')
}