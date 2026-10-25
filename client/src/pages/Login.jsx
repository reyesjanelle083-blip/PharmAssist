import { useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { fieldErrors } from '../api/index.js'
import ErrorMessage from '../components/ErrorMessage.jsx'
import Field from '../components/Field.jsx'
import { useAuth } from '../context/useAuth.js'
import { usePageTitle } from '../hooks/usePageTitle.js'

const NOTICES = {
  session_expired: 'Your session has ended. Please log in again.',
  login_required: 'Please log in to continue.',
}

/** Only paths inside this app are followed after logging in. */
function safeTarget(from) {
  return typeof from === 'string' && from.startsWith('/') && !from.startsWith('//') ? from : '/'
}

export default function Login() {
  usePageTitle('Log in')
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const notice = NOTICES[location.state?.notice]

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [localErrors, setLocalErrors] = useState({})

  async function submit(event) {
    event.preventDefault()
    const problems = {}
    if (email.trim() === '') problems.email = ['Please enter your email address.']
    if (password === '') problems.password = ['Please enter your password.']
    setLocalErrors(problems)
    setError(null)
    if (Object.keys(problems).length > 0) return

    setBusy(true)
    try {
      await login(email.trim(), password)
      navigate(safeTarget(location.state?.from), { replace: true })
    } catch (caught) {
      setError(caught)
      setBusy(false)
    }
  }

  const serverFields = fieldErrors(error)
  const errors = { ...serverFields, ...localErrors }

  return (
    <div className="form-page">
      <h1>Log in</h1>
      {notice ? (
        <p className="notice notice--info" role="status">
          {notice}
        </p>
      ) : null}

      <form onSubmit={submit} noValidate className="form" aria-busy={busy}>
        <ErrorMessage error={error}>
          {error?.code === 'EMAIL_NOT_VERIFIED' ? (
            <p>
              <Link to="/verify-email">Verify your email</Link>
            </p>
          ) : null}
        </ErrorMessage>

        <Field label="Email address" errors={errors.email}>
          {(props) => <input {...props} type="email" name="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} />}
        </Field>
        <Field label="Password" errors={errors.password}>
          {(props) => (
            <input
              {...props}
              type={showPassword ? 'text' : 'password'}
              name="password"
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
          )}
        </Field>

        <label className="checkbox">
          <input type="checkbox" checked={showPassword} onChange={(e) => setShowPassword(e.target.checked)} />
          <span>Show password</span>
        </label>

        <button type="submit" className="button button--block" disabled={busy}>
          {busy ? 'Logging in...' : 'Log in'}
        </button>
      </form>

      <p className="form-page__links">
        New here? <Link to="/register">Create an account</Link>
        <br />
        Have a code from your email? <Link to="/verify-email">Verify your email</Link>
      </p>
    </div>
  )
}
