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

/** login.php: title, notice, the rounded fields with icons, the eye button, the Sign in button and the sign up link. */
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

  const errors = { ...fieldErrors(error), ...localErrors }

  return (
    <>
      <h2 className="login-title">Login</h2>
      <p className="notice">Enter your details to sign in</p>

      {notice ? (
        <p className="app-notice app-notice--info" role="status">
          {notice}
        </p>
      ) : null}

      <form className="form-login" onSubmit={submit} noValidate aria-busy={busy}>
        <ErrorMessage error={error}>
          {error?.code === 'EMAIL_NOT_VERIFIED' ? (
            <p>
              <Link to="/verify-email" style={{ display: 'inline', fontSize: 'inherit' }}>
                Verify your email
              </Link>
            </p>
          ) : null}
        </ErrorMessage>

        <Field label="Email address" wrapperClass="input-email" icon="fa-solid fa-user" errors={errors.email}>
          {(props) => (
            <input {...props} type="email" name="email" autoComplete="email" placeholder="Enter your email" value={email} onChange={(e) => setEmail(e.target.value)} />
          )}
        </Field>

        <Field
          label="Password"
          wrapperClass="input-password"
          icon="fas fa-lock"
          errors={errors.password}
          after={
            <button type="button" className="toggle-password-btn" onClick={() => setShowPassword((v) => !v)} aria-pressed={showPassword} aria-label="Show password">
              <i className={showPassword ? 'far fa-eye-slash' : 'far fa-eye'} aria-hidden="true"></i>
            </button>
          }
        >
          {(props) => (
            <input
              {...props}
              type={showPassword ? 'text' : 'password'}
              name="password"
              autoComplete="current-password"
              placeholder="Enter your password"
              style={{ paddingRight: 40 }}
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
          )}
        </Field>

        <button type="submit" disabled={busy}>
          {busy ? 'Signing in...' : 'Sign in'}
        </button>
      </form>

      <div className="form-links">
        <Link to="/verify-email">Have a code from your email? Verify your email</Link>
      </div>

      <div className="signup-link" style={{ textAlign: 'center', margin: '10px 0 0 0' }}>
        <span style={{ fontSize: 12, color: '#666' }}>
          Don&apos;t have an account?{' '}
          <Link to="/register" style={{ display: 'inline', color: '#7393A7', textDecoration: 'none', fontSize: 12, margin: 0 }}>
            Sign up here
          </Link>
        </span>
      </div>
    </>
  )
}
