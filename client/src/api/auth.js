import http from './http.js'

export function login(email, password) {
  return http.post('/auth/login', { email, password })
}

/** multipart/form-data: the browser sets the boundary, so no Content-Type is set here. */
export function register({ email, password, first_name, last_name, contact_no, id_photo }) {
  const form = new FormData()
  form.append('email', email)
  form.append('password', password)
  form.append('first_name', first_name)
  form.append('last_name', last_name)
  form.append('contact_no', contact_no)
  form.append('id_photo', id_photo)
  return http.post('/auth/register', form)
}

export function verifyEmail(email, code) {
  return http.post('/auth/verify-email', { email, code })
}

export function me() {
  return http.get('/auth/me')
}
