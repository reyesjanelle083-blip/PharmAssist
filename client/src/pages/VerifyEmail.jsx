import { useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { fieldErrors, verifyEmail } from '../api/index.js'
import ErrorMessage from '../components/ErrorMessage.jsx'
import Field from '../components/Field.jsx'
import { usePageTitle } from '../hooks/usePageTitle.js'

/** Built from the old login page: same title, notice, rounded fields and button. */
export default function VerifyEmail() {
  usePageTitle('Verify your email')
  const location = useLocation()
  const [email, setEmail] = useState(typeof location.state?.email === 'string' ? location.state.email : '')
  const [code, setCode] = useState('')
  const [errors, setErrors] = useState({})
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState(false)

  async function submit(event) {
    event.preventDefault()
    setError(null)

    const problems = {}
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) problems.email = ['Please enter the email address you registered with.']
    if (!/^[0-9]{6}$/.test(code.trim())) problems.code = ['The code has exactly 6 digits.']
    setErrors(problems)
    if (Object.keys(problems).length > 0) return

    setBusy(true)
    try {
      await verifyEmail(email.trim(), code.trim())
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
        <h2 className="login-title">Email verified</h2>
        <p className="notice">Thank you. Your email address is confirmed.</p>
        <div className="app-notice app-notice--success" role="status">
          <p>The pharmacy administrator still has to approve your account. You can log in once that has happened.</p>
        </div>
        <Link className="button-link" to="/login">
          Go to Log in
        </Link>
      </>
    )
  }

  return (
    <>
      <h2 className="login-title">Verify your email</h2>
      <p className="notice">Enter the email address you registered with and the 6-digit code we sent to it</p>

      <form className="form-login" onSubmit={submit} noValidate aria-busy={busy}>
        <ErrorMessage error={error} />

        <Field label="Email address" wrapperClass="input-email" icon="fa-solid fa-user" errors={errors.email}>
          {(props) => <input {...props} type="email" name="email" autoComplete="email" placeholder="Enter your email" value={email} onChange={(e) => setEmail(e.target.value)} />}
        </Field>

        <Field label="6-digit code" wrapperClass="input-username" icon="fas fa-lock" errors={errors.code}>
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
              onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
            />
          )}
        </Field>

        <button type="submit" disabled={busy}>
          {busy ? 'Checking...' : 'Verify email'}
        </button>
      </form>

      <div className="signup-link" style={{ textAlign: 'center', margin: '10px 0 0 0' }}>
        <span style={{ fontSize: 12, color: '#666' }}>
          Not registered yet?{' '}
          <Link to="/register" style={{ display: 'inline', color: '#7393A7', textDecoration: 'none', fontSize: 12, margin: 0 }}>
            Create an account
          </Link>
        </span>
      </div>
    </>
  )
}
