import { useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { fieldErrors, resetPassword } from '../api/index.js'
import ErrorMessage from '../components/ErrorMessage.jsx'
import Field from '../components/Field.jsx'
import { usePageTitle } from '../hooks/usePageTitle.js'

export default function ResetPassword() {
  usePageTitle('Reset password')

  const location = useLocation()

  const [email, setEmail] = useState(
    typeof location.state?.email === 'string'
      ? location.state.email
      : '',
  )

  const [code, setCode] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [errors, setErrors] = useState({})
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState(false)

  async function submit(event) {
    event.preventDefault()
    setError(null)

    const problems = {}
    const cleanEmail = email.trim()
    const cleanCode = code.trim()

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(cleanEmail)) {
      problems.email = ['Please enter a valid email address.']
    }

    if (!/^[0-9]{6}$/.test(cleanCode)) {
      problems.code = ['The reset code must contain exactly 6 digits.']
    }

    if (
      password.length < 8 ||
      !/[A-Za-z]/.test(password) ||
      !/[0-9]/.test(password)
    ) {
      problems.password = [
        'Use at least 8 characters, with letters and numbers.',
      ]
    }

    if (confirmation !== password) {
      problems.password_confirmation = ['The passwords do not match.']
    }

    setErrors(problems)

    if (Object.keys(problems).length > 0) {
      return
    }

    setBusy(true)

    try {
      await resetPassword({
        email: cleanEmail,
        code: cleanCode,
        password,
        password_confirmation: confirmation,
      })

      setDone(true)
    } catch (caught) {
      setError(caught)
      setErrors(fieldErrors(caught))
    } finally {
      setBusy(false)
    }
  }

  if (done) {
    return (
      <>
        <h2 className="login-title">Password reset</h2>

        <p className="notice">
          Your password has been changed successfully.
        </p>

        <div className="app-notice app-notice--success" role="status">
          <p>You can now log in using your new password.</p>
        </div>

        <Link className="button-link" to="/login">
          Go to Log in
        </Link>
      </>
    )
  }

  return (
    <>
      <h2 className="login-title">Reset Password</h2>

      <p className="notice">
        Enter the 6-digit code from your email and choose a new password.
      </p>

      <form
        className="form-login"
        onSubmit={submit}
        noValidate
        aria-busy={busy}
      >
        <ErrorMessage error={error} />

        <Field
          label="Email address"
          wrapperClass="input-email"
          icon="fa-solid fa-user"
          errors={errors.email}
        >
          {(props) => (
            <input
              {...props}
              type="email"
              name="email"
              autoComplete="email"
              placeholder="Enter your email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
            />
          )}
        </Field>

        <Field
          label="6-digit reset code"
          wrapperClass="input-username"
          icon="fas fa-key"
          errors={errors.code}
        >
          {(props) => (
            <input
              {...props}
              type="text"
              name="code"
              inputMode="numeric"
              pattern="[0-9]*"
              autoComplete="one-time-code"
              maxLength={6}
              placeholder="Enter the 6 digits"
              value={code}
              onChange={(event) =>
                setCode(event.target.value.replace(/\D/g, ''))
              }
            />
          )}
        </Field>

        <Field
          label="New Password"
          wrapperClass="input-password"
          icon="fas fa-lock"
          errors={errors.password}
          hint="Use at least 8 characters with letters and numbers."
          after={
            <button
              type="button"
              className="toggle-password-btn"
              onClick={() => setShowPassword((value) => !value)}
              aria-pressed={showPassword}
              aria-label={showPassword ? 'Hide password' : 'Show password'}
            >
              <i
                className={
                  showPassword ? 'far fa-eye-slash' : 'far fa-eye'
                }
                aria-hidden="true"
              ></i>
            </button>
          }
        >
          {(props) => (
            <input
              {...props}
              type={showPassword ? 'text' : 'password'}
              name="password"
              autoComplete="new-password"
              placeholder="Enter your new password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
            />
          )}
        </Field>

        <Field
          label="Confirm New Password"
          wrapperClass="input-password"
          icon="fas fa-lock"
          errors={errors.password_confirmation}
        >
          {(props) => (
            <input
              {...props}
              type={showPassword ? 'text' : 'password'}
              name="password_confirmation"
              autoComplete="new-password"
              placeholder="Enter your new password again"
              value={confirmation}
              onChange={(event) => setConfirmation(event.target.value)}
            />
          )}
        </Field>

        <button type="submit" disabled={busy}>
          {busy ? 'Resetting...' : 'Reset password'}
        </button>
      </form>

      <div
        className="signup-link"
        style={{ textAlign: 'center', margin: '10px 0 0 0' }}
      >
        <Link
          to="/forgot-password"
          style={{
            display: 'inline',
            color: '#7393A7',
            textDecoration: 'none',
            fontSize: 12,
          }}
        >
          Request another code
        </Link>
      </div>
    </>
  )
}