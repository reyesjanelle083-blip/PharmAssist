import { useState } from 'react'
import { Link } from 'react-router-dom'
import { fieldErrors, forgotPassword } from '../api/index.js'
import ErrorMessage from '../components/ErrorMessage.jsx'
import Field from '../components/Field.jsx'
import { usePageTitle } from '../hooks/usePageTitle.js'

export default function ForgotPassword() {
  usePageTitle('Forgot password')

  const [email, setEmail] = useState('')
  const [errors, setErrors] = useState({})
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState(false)

  async function submit(event) {
    event.preventDefault()
    setError(null)

    const problems = {}
    const cleanEmail = email.trim()

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(cleanEmail)) {
      problems.email = ['Please enter a valid email address.']
    }

    setErrors(problems)

    if (Object.keys(problems).length > 0) {
      return
    }

    setBusy(true)

    try {
      await forgotPassword(cleanEmail)
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
        <h2 className="login-title">Check your email</h2>

        <p className="notice">
          If an account exists for that email address, a password reset code has been sent.
        </p>

        <div className="app-notice app-notice--success" role="status">
          <p>
            Enter the 6-digit code on the reset password page. The code expires after 15 minutes.
          </p>
        </div>

        <Link
          className="button-link"
          to="/reset-password"
          state={{ email: email.trim() }}
        >
          Reset Password
        </Link>
      </>
    )
  }

  return (
    <>
      <h2 className="login-title">Forgot Password</h2>

      <p className="notice">
        Enter your email address to receive a 6-digit reset code.
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

        <button type="submit" disabled={busy}>
          {busy ? 'Sending...' : 'Send reset code'}
        </button>
      </form>

      <div
        className="signup-link"
        style={{ textAlign: 'center', margin: '10px 0 0 0' }}
      >
        <Link
          to="/login"
          style={{
            display: 'inline',
            color: '#7393A7',
            textDecoration: 'none',
            fontSize: 12,
          }}
        >
          Back to Log in
        </Link>
      </div>
    </>
  )
}