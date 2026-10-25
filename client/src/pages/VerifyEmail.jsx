import { useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { fieldErrors, verifyEmail } from '../api/index.js'
import ErrorMessage from '../components/ErrorMessage.jsx'
import Field from '../components/Field.jsx'
import { usePageTitle } from '../hooks/usePageTitle.js'

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
      <div className="form-page">
        <h1>Email verified</h1>
        <div className="notice notice--success" role="status">
          <p>Thank you. Your email address is confirmed.</p>
          <p>The pharmacy administrator still has to approve your account. You can log in once that has happened.</p>
        </div>
        <p>
          <Link className="button" to="/login">
            Go to Log in
          </Link>
        </p>
      </div>
    )
  }

  return (
    <div className="form-page">
      <h1>Verify your email</h1>
      <p>Enter the email address you registered with and the 6-digit code we sent to it.</p>

      <form onSubmit={submit} noValidate className="form" aria-busy={busy}>
        <ErrorMessage error={error} />
        <Field label="Email address" errors={errors.email}>
          {(props) => <input {...props} type="email" name="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} />}
        </Field>
        <Field label="6-digit code" errors={errors.code}>
          {(props) => (
            <input
              {...props}
              type="text"
              name="code"
              inputMode="numeric"
              pattern="[0-9]*"
              autoComplete="one-time-code"
              maxLength={6}
              value={code}
              onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
            />
          )}
        </Field>
        <button type="submit" className="button button--block" disabled={busy}>
          {busy ? 'Checking...' : 'Verify email'}
        </button>
      </form>

      <p className="form-page__links">
        Not registered yet? <Link to="/register">Create an account</Link>
      </p>
    </div>
  )
}
